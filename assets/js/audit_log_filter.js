/**
 * audit_log_filter.js — real-time server-side search for the audit log.
 *
 * Listens for input on the search field and uses a debounced fetch request
 * to an AJAX backend to retrieve and display filtered log data without a
 * full page reload.
 *
 * @module audit_log_filter
 */

'use strict';

(function () {

    const searchInput = document.getElementById('audit-log-search-input');
    const tableContainer = document.getElementById('audit-log-table-container');
    const tableBody = tableContainer?.querySelector('tbody');
    const resultsCountEl = document.getElementById('audit-log-results-count');
    const paginationEl = document.getElementById('audit-log-pagination');
    const form = document.getElementById('audit-log-search-form');

    // if any required elements are missing, do not proceed.
    if (!searchInput || !tableContainer || !tableBody || !resultsCountEl || !paginationEl || !form) {
        return;
    }

    let debounceTimer;

    /**
     * A simple debounce function to prevent firing events on every keystroke.
     * @param {function} func The function to execute after the debounce period.
     * @param {number} delay The debounce period in milliseconds.
     */
    function debounce(func, delay = 300) {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(func, delay);
    }

    /**
     * Performs a fetch request to the backend and updates the page.
     * @param {string} query The search query string.
     * @param {number} page The page number to fetch.
     */
    async function performSearch(query = '', page = 1) {
        // add a loading indicator
        tableBody.style.opacity = '0.5';

        const url = `/admin/ajax_audit_log_search.php?q=${encodeURIComponent(query)}&page=${page}`;

        try {
            const response = await fetch(url);
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            const data = await response.json();

            // update the page content with the new HTML from the server
            // using requestAnimationFrame to ensure smooth rendering
            requestAnimationFrame(() => {
                tableBody.innerHTML = data.tableBody;
                resultsCountEl.innerHTML = data.resultsCount;
                paginationEl.innerHTML = data.pagination;
                tableBody.style.opacity = '1';
            });

            // update the browser's URL to reflect the search state
            const newUrl = `/admin/audit_log.php?q=${encodeURIComponent(query)}&page=${page}`;
            history.pushState({ path: newUrl }, '', newUrl);

        } catch (error) {
            console.error('Search fetch failed:', error);
            // restore opacity on failure
            tableBody.style.opacity = '1';
            // optionally display an error message to the user in the table
            tableBody.innerHTML = '<tr><td colspan="5"><div class="alert alert-danger text-center">Search failed. Please try again.</div></td></tr>';
        }
    }


    // --- event listeners ---

    // listen for typing in the search box
    searchInput.addEventListener('input', (e) => {
        const query = e.target.value;
        debounce(() => performSearch(query, 1)); // reset to page 1 for new search
    });

    // prevent the form from submitting via traditional page load
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        performSearch(searchInput.value, 1);
    });

    // handle clicks on pagination links dynamically
    paginationEl.addEventListener('click', (e) => {
        // only act on clicks on <a> tags
        if (e.target.tagName !== 'A') {
            return;
        }
        // prevent the link from navigating
        e.preventDefault();

        // get the url and its params from the clicked link
        const linkUrl = new URL(e.target.href);
        const page = linkUrl.searchParams.get('page') || '1';
        const query = linkUrl.searchParams.get('q') || '';

        performSearch(query, parseInt(page, 10));
    });

}());
