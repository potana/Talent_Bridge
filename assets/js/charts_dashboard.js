/**
 * Admin Dashboard Charts — Chart.js visualization and interactions
 *
 * Handles rendering and filtering for:
 * - New users over time (stacked vs overlapping area chart)
 * - Industry popularity (bar chart with status & type filters)
 * - Job applications (dual donut charts - overall & by industry)
 * - Geographical popularity (grouped bar chart - listings vs seekers)
 *
 * @package TalentBridge
 */

let isStackedMode = true; // Track stacked vs overlapping mode
let currentDateRange = 30; // Track current date range for new users
let geoOrderBy = 'listings'; // Track geography chart ordering (listings or seekers)

document.addEventListener('DOMContentLoaded', function () {
    initCharts();
});

function initCharts() {
    // show loading state
    showLoadingState();

    // load all chart data in parallel
    Promise.all([
        loadChartData('new_users', currentDateRange),
        loadChartData('industry_popularity'),
        loadChartData('job_applications', 30),
        loadChartData('applications_by_industry', 30),
        loadChartData('geographical_popularity')
    ]).then(() => {
        hideLoadingState();
    }).catch(err => {
        console.error('Failed to load chart data:', err);
        hideLoadingState();
        showError('Failed to load chart data');
    });

    // attach event listeners
    setupEventListeners();
}

function setupEventListeners() {
    // new users date range filter
    document.getElementById('newUsersDateRange')?.addEventListener('change', function (e) {
        currentDateRange = parseInt(e.target.value);
        updateNewUsersChart(currentDateRange);
    });

    // new users stack/overlap toggle
    document.getElementById('toggleStackMode')?.addEventListener('click', function() {
        isStackedMode = !isStackedMode;
        const btnText = this.querySelector('span');
        btnText.textContent = isStackedMode ? 'Stacked' : 'Overlapping';
        this.classList.toggle('active');
        
        // Re-render chart if data exists
        if (window.lastNewUsersData) {
            renderNewUsersChart(window.lastNewUsersData);
        } else {
            // Data not loaded yet, reload chart
            loadChartData('new_users', currentDateRange);
        }
    });

    // industry filters
    document.getElementById('includeInactiveListings')?.addEventListener('change', function() {
        updateIndustryChart();
    });

    document.getElementById('jobTypeFilter')?.addEventListener('change', function() {
        updateIndustryChart();
    });

    // applications date range filter
    document.getElementById('applicationsDateRange')?.addEventListener('change', function (e) {
        updateApplicationsChart(parseInt(e.target.value));
    });

    // applications industry filter
    document.getElementById('industryApplicationFilter')?.addEventListener('change', function() {
        updateApplicationsByIndustry();
    });

    // industry filter checkboxes
    document.querySelectorAll('.industry-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', updateIndustryChart);
    });

    // geography chart controls
    document.getElementById('geoOrderToggle')?.addEventListener('click', function() {
        window.geoOrderBy = window.geoOrderBy === 'listings' ? 'seekers' : 'listings';
        const label = document.getElementById('geoOrderLabel');
        label.textContent = window.geoOrderBy === 'listings' ? 'Job Listings ▼' : 'Job Seekers ▼';
        updateGeographicalChart();
    });

    document.getElementById('geoCountLimit')?.addEventListener('change', function() {
        updateGeographicalChart();
    });
}

function loadChartData(action, days = null) {
    let url = `/admin/chart_data.php?action=${action}`;
    if (days) {
        url += `&days=${days}`;
    }

    // Add query parameters based on action
    if (action === 'industry_popularity') {
        const includeInactive = document.getElementById('includeInactiveListings')?.checked ? 1 : 0;
        const jobType = document.getElementById('jobTypeFilter')?.value || '';
        url += `&includeInactive=${includeInactive}`;
        if (jobType) url += `&jobType=${encodeURIComponent(jobType)}`;
    }

    if (action === 'applications_by_industry') {
        const industry = document.getElementById('industryApplicationFilter')?.value || '';
        if (industry) url += `&industry=${encodeURIComponent(industry)}`;
    }

    return fetch(url)
        .then(res => {
            if (!res.ok) throw new Error(`HTTP error! status: ${res.status}`);
            return res.json();
        })
        .then(data => {
            if (data.error) throw new Error(data.error);
            
            switch (action) {
                case 'new_users':
                    window.lastNewUsersData = data;
                    renderNewUsersChart(data);
                    break;
                case 'industry_popularity':
                    renderIndustryChart(data);
                    break;
                case 'job_applications':
                    renderApplicationsChart(data);
                    break;
                case 'applications_by_industry':
                    window.lastApplicationsByIndustryData = data;
                    populateIndustryApplicationFilter(data);
                    renderApplicationsByIndustryChart(data);
                    break;
                case 'geographical_popularity':
                    window.lastGeographicalData = data;
                    renderGeographicalChart(data);
                    break;
            }
        });
}

/**
 * Render area chart - New users over time
 */
let newUsersChart = null;

function renderNewUsersChart(data) {
    if (!data.dates || data.dates.length === 0) {
        document.getElementById('newUsersChartContainer').innerHTML =
            '<div class="text-muted text-center py-5">No data available</div>';
        return;
    }

    const ctx = document.getElementById('newUsersChart');
    if (!ctx) return;

    // destroy previous chart instance
    if (newUsersChart) {
        newUsersChart.destroy();
    }

    // Calculate appropriate max value with padding
    // When stacked, sum the values at each point and find the max sum
    // When overlapping, find the individual max
    let yMax;
    if (isStackedMode) {
        // Calculate sum at each point
        const sums = data.seekers.map((val, idx) => val + (data.employers[idx] || 0));
        const maxSum = Math.max(...sums, 0);
        yMax = Math.ceil(maxSum * 1.15);
    } else {
        // For overlapping, use the individual max
        const allValues = [...data.seekers, ...data.employers];
        const maxValue = Math.max(...allValues, 0);
        yMax = Math.ceil(maxValue * 1.15);
    }

    const datasets = [
        {
            label: 'Job Seekers',
            data: data.seekers,
            borderColor: '#28A745',
            backgroundColor: isStackedMode ? 'rgba(40, 167, 69, 0.2)' : 'rgba(40, 167, 69, 0.1)',
            borderWidth: 2,
            fill: true,
            tension: 0.4,
            pointRadius: 4,
            pointBackgroundColor: '#28A745',
            pointBorderColor: '#fff',
            pointBorderWidth: 2
        },
        {
            label: 'Employers',
            data: data.employers,
            borderColor: '#007BFF',
            backgroundColor: isStackedMode ? 'rgba(0, 123, 255, 0.2)' : 'rgba(0, 123, 255, 0.1)',
            borderWidth: 2,
            fill: true,
            tension: 0.4,
            pointRadius: 4,
            pointBackgroundColor: '#007BFF',
            pointBorderColor: '#fff',
            pointBorderWidth: 2
        }
    ];

    newUsersChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: data.dates,
            datasets: datasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        padding: 15
                    }
                },
                filler: {
                    propagate: true
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    max: yMax,
                    stacked: isStackedMode,
                    ticks: {
                        stepSize: Math.ceil(yMax / 5)
                    }
                },
                x: {
                    stacked: isStackedMode
                }
            }
        }
    });
}

function updateNewUsersChart(days) {
    loadChartData('new_users', days);
}

/**
 * Render bar chart - Industry popularity
 */
let industryChart = null;
let industryChartData = null;

function renderIndustryChart(data) {
    industryChartData = data;

    if (!data.industries || data.industries.length === 0) {
        document.getElementById('industryChartContainer').innerHTML =
            '<div class="text-muted text-center py-5">No data available</div>';
        return;
    }

    // Populate job type filter
    if (data.jobTypes && data.jobTypes.length > 0) {
        const typeSelect = document.getElementById('jobTypeFilter');
        const currentValue = typeSelect?.value || '';
        if (typeSelect && typeSelect.children.length === 1) {
            data.jobTypes.forEach(type => {
                const option = document.createElement('option');
                option.value = type;
                option.textContent = type;
                typeSelect.appendChild(option);
            });
            typeSelect.value = currentValue;
        }
    }

    // populate industry checkboxes
    const checkboxContainer = document.getElementById('industryCheckboxes');
    if (checkboxContainer) {
        checkboxContainer.innerHTML = '';
        data.industries.forEach(industry => {
            const div = document.createElement('div');
            div.className = 'form-check form-check-inline';
            div.innerHTML = `
                <input class="form-check-input industry-checkbox" type="checkbox" 
                       id="industry_${industry}" value="${industry}" checked>
                <label class="form-check-label" for="industry_${industry}" 
                       style="padding: 6px 12px; background-color: #f8f9fa; border: 1px solid #dee2e6; border-radius: 20px; 
                              cursor: pointer; font-size: 0.9rem; transition: all 0.2s ease;">
                    ${sanitizeHtml(industry)}
                </label>
            `;
            checkboxContainer.appendChild(div);
            
            // Improve checkbox styling
            const checkbox = div.querySelector('.industry-checkbox');
            const label = div.querySelector('label');
            checkbox.style.cursor = 'pointer';
            
            // Add visual feedback on hover and click
            label.addEventListener('mouseenter', function() {
                if (checkbox.checked) {
                    label.style.backgroundColor = '#e7f3ff';
                    label.style.borderColor = '#007bff';
                } else {
                    label.style.backgroundColor = '#f0f0f0';
                    label.style.borderColor = '#ccc';
                }
            });
            
            label.addEventListener('mouseleave', function() {
                if (checkbox.checked) {
                    label.style.backgroundColor = '#e7f3ff';
                    label.style.borderColor = '#007bff';
                } else {
                    label.style.backgroundColor = '#f8f9fa';
                    label.style.borderColor = '#dee2e6';
                }
            });
            
            // Set initial styles based on checked state
            if (checkbox.checked) {
                label.style.backgroundColor = '#e7f3ff';
                label.style.borderColor = '#007bff';
            }
            
            // add event listener
            checkbox.addEventListener('change', function() {
                if (this.checked) {
                    label.style.backgroundColor = '#e7f3ff';
                    label.style.borderColor = '#007bff';
                } else {
                    label.style.backgroundColor = '#f8f9fa';
                    label.style.borderColor = '#dee2e6';
                }
                updateIndustryChart();
            });
        });
    }

    updateIndustryChartDisplay();
}

function updateIndustryChart() {
    const includeInactive = document.getElementById('includeInactiveListings')?.checked ? 1 : 0;
    const jobType = document.getElementById('jobTypeFilter')?.value || '';
    
    let url = `/admin/chart_data.php?action=industry_popularity&includeInactive=${includeInactive}`;
    if (jobType) url += `&jobType=${encodeURIComponent(jobType)}`;
    
    fetch(url)
        .then(res => res.json())
        .then(data => {
            industryChartData = data;
            updateIndustryChartDisplay();
        })
        .catch(err => console.error('Failed to update industry chart:', err));
}

function updateIndustryChartDisplay() {
    if (!industryChartData) return;

    // get selected industries
    const checkedBoxes = document.querySelectorAll('.industry-checkbox:checked');
    const selectedIndustries = Array.from(checkedBoxes).map(cb => cb.value);

    if (selectedIndustries.length === 0) {
        document.getElementById('industryChartContainer').innerHTML =
            '<div class="text-muted text-center py-5">Select industries to display</div>';
        if (industryChart) industryChart.destroy();
        return;
    }

    // filter data
    const filteredIndices = industryChartData.industries
        .map((ind, idx) => selectedIndustries.includes(ind) ? idx : -1)
        .filter(idx => idx !== -1);

    const filteredIndustries = filteredIndices.map(idx => industryChartData.industries[idx]);
    const filteredCounts = filteredIndices.map(idx => industryChartData.counts[idx]);

    const ctx = document.getElementById('industryChart');
    if (!ctx) return;

    // destroy previous chart
    if (industryChart) {
        industryChart.destroy();
    }

    industryChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: filteredIndustries,
            datasets: [
                {
                    label: 'Job Listings',
                    data: filteredCounts,
                    backgroundColor: '#007BFF',
                    borderColor: '#0056B3',
                    borderWidth: 1
                }
            ]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top'
                }
            },
            scales: {
                x: {
                    beginAtZero: true
                }
            }
        }
    });
}

/**
 * Render donut chart - Job applications by status
 */
let applicationsChart = null;

function renderApplicationsChart(data) {
    if (!data.data || data.data.length === 0) {
        document.getElementById('applicationsChartContainer').innerHTML =
            '<div class="text-muted text-center py-5">No data available</div>';
        return;
    }

    const ctx = document.getElementById('applicationsChart');
    if (!ctx) return;

    // destroy previous chart
    if (applicationsChart) {
        applicationsChart.destroy();
    }

    const labels = data.data.map(d => d.status);
    const counts = data.data.map(d => d.count);
    const colors = data.data.map(d => d.color);

    applicationsChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [
                {
                    data: counts,
                    backgroundColor: colors,
                    borderColor: '#fff',
                    borderWidth: 2
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'bottom',
                    labels: {
                        padding: 15
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function (context) {
                            return context.label + ': ' + context.parsed + ' applications';
                        }
                    }
                }
            }
        },
        plugins: [
            {
                id: 'textCenter',
                beforeDatasetsDraw(chart) {
                    const { width, height, ctx } = chart;
                    ctx.restore();

                    const labelFontSize = (height / 400).toFixed(2); // Smaller for the label
                    const valueFontSize = (height / 180).toFixed(2); // Larger for the number
        
                    ctx.textBaseline = 'middle';
                    ctx.textAlign = 'center';

                    const centerX = width / 2;
                    const centerY = height / 2;

                    ctx.font = `bold ${labelFontSize}em sans-serif`;
                    ctx.fillStyle = 'rgba(0, 0, 0, 0.4)';
                    ctx.fillText('TOTAL APPS:', centerX, centerY - 15); // Shifted up slightly

                    ctx.font = `bold ${valueFontSize}em sans-serif`;
                    ctx.fillStyle = '#000';
                    ctx.fillText(data.total, centerX, centerY + 15); // Shifted down slightl

                    ctx.save();
                }
            }
        ]
    });
}

function updateApplicationsChart(days) {
    loadChartData('job_applications', days);
}

/**
 * Populate industry filter for applications by industry
 */
function populateIndustryApplicationFilter(data) {
    if (!data.industries) return;
    
    const select = document.getElementById('industryApplicationFilter');
    if (!select) return;

    const currentValue = select.value;
    // Clear existing options except the first one
    while (select.children.length > 1) {
        select.removeChild(select.lastChild);
    }

    data.industries.forEach(industry => {
        const option = document.createElement('option');
        option.value = industry;
        option.textContent = industry;
        select.appendChild(option);
    });

    select.value = currentValue;
}

/**
 * Render donut chart - Applications by industry
 */
let applicationsIndustryChart = null;

function renderApplicationsByIndustryChart(data) {
    if (!data.data || data.data.length === 0) {
        document.getElementById('applicationsIndustryChartContainer').innerHTML =
            '<div class="text-muted text-center py-5">No data available</div>';
        return;
    }

    const ctx = document.getElementById('applicationsIndustryChart');
    if (!ctx) return;

    // destroy previous chart
    if (applicationsIndustryChart) {
        applicationsIndustryChart.destroy();
    }

    const labels = data.data.map(d => d.status);
    const counts = data.data.map(d => d.count);
    const colors = data.data.map(d => d.color);

    applicationsIndustryChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [
                {
                    data: counts,
                    backgroundColor: colors,
                    borderColor: '#fff',
                    borderWidth: 2
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'bottom',
                    labels: {
                        padding: 15
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function (context) {
                            return context.label + ': ' + context.parsed + ' applications';
                        }
                    }
                }
            }
        },
        plugins: [
            {
                id: 'textCenter',
                beforeDatasetsDraw(chart) {
                    const { width, height, ctx } = chart;
                    ctx.restore();

                    const labelFontSize = (height / 400).toFixed(2); // Smaller for the label
                    const valueFontSize = (height / 180).toFixed(2); // Larger for the number
        
                    ctx.textBaseline = 'middle';
                    ctx.textAlign = 'center';

                    const centerX = width / 2;
                    const centerY = height / 2;

                    ctx.font = `bold ${labelFontSize}em sans-serif`;
                    ctx.fillStyle = 'rgba(0, 0, 0, 0.4)';
                    ctx.fillText('TOTAL APPS:', centerX, centerY - 15); // Shifted up slightly

                    ctx.font = `bold ${valueFontSize}em sans-serif`;
                    ctx.fillStyle = '#000';
                    ctx.fillText(data.total, centerX, centerY + 15); // Shifted down slightl

                    ctx.save();
                }
            }
        ]
    });
}

function updateApplicationsByIndustry() {
    const days = parseInt(document.getElementById('applicationsDateRange')?.value || 30);
    loadChartData('applications_by_industry', days);
}

/**
 * Render grouped bar chart - Geographical popularity (listings vs seekers)
 */
let geographicalChart = null;

function renderGeographicalChart(data) {
    if (!data.locations || data.locations.length === 0) {
        document.getElementById('geographicalChartContainer').innerHTML =
            '<div class="text-muted text-center py-5">No data available</div>';
        return;
    }

    const ctx = document.getElementById('geographicalChart');
    if (!ctx) return;

    // destroy previous chart
    if (geographicalChart) {
        geographicalChart.destroy();
    }

    // Get user preferences
    const countLimit = parseInt(document.getElementById('geoCountLimit')?.value || 10);
    
    // Create array of objects for sorting
    let dataArray = data.locations.map((location, idx) => ({
        location: location,
        jobListings: data.jobListings[idx],
        seekers: data.seekers[idx]
    }));
    
    // Sort by selected criterion (descending)
    const sortKey = geoOrderBy === 'seekers' ? 'seekers' : 'jobListings';
    dataArray.sort((a, b) => b[sortKey] - a[sortKey]);
    
    // Limit to top N countries
    dataArray = dataArray.slice(0, countLimit);
    
    // Extract sorted data back into arrays
    const sortedLocations = dataArray.map(d => d.location);
    const sortedJobListings = dataArray.map(d => d.jobListings);
    const sortedSeekers = dataArray.map(d => d.seekers);

    geographicalChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: sortedLocations,
            datasets: [
                {
                    label: 'Job Listings',
                    data: sortedJobListings,
                    backgroundColor: '#007BFF',
                    borderColor: '#0056B3',
                    borderWidth: 1
                },
                {
                    label: 'Job Seekers',
                    data: sortedSeekers,
                    backgroundColor: '#28A745',
                    borderColor: '#1E7E34',
                    borderWidth: 1
                }
            ]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top'
                }
            },
            scales: {
                x: {
                    beginAtZero: true
                }
            }
        }
    });
}

function updateGeographicalChart() {
    if (window.lastGeographicalData) {
        renderGeographicalChart(window.lastGeographicalData);
    } else {
        loadChartData('geographical_popularity');
    }
}

/**
 * Utility functions
 */
function showLoadingState() {
    document.querySelectorAll('[id$="DateRange"], [id$="Filter"], [id$="Listings"], .industry-checkbox, #toggleStackMode').forEach(elem => {
        if (elem.disabled !== undefined) elem.disabled = true;
    });
}

function hideLoadingState() {
    document.querySelectorAll('[id$="DateRange"], [id$="Filter"], [id$="Listings"], .industry-checkbox, #toggleStackMode').forEach(elem => {
        if (elem.disabled !== undefined) elem.disabled = false;
    });
}

function showError(message) {
    const alert = document.createElement('div');
    alert.className = 'alert alert-danger alert-dismissible fade show';
    alert.innerHTML = `
        ${sanitizeHtml(message)}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    const mainContent = document.querySelector('main');
    if (mainContent) {
        mainContent.insertBefore(alert, mainContent.firstChild);
    }
}

function sanitizeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function sanitizeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
