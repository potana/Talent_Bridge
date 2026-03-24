<?php
/**
 * Admin dashboard — platform-wide statistics at a glance.
 *
 * Displays four animated stat cards: total users, active listings,
 * total applications, and unread contact messages. Counters are animated
 * by admin_stats.js using requestAnimationFrame.
 *
 * @package TalentBridge
 */

session_start();
require_once '../includes/helpers.php';
require_once '../includes/auth.php';
require_once '../includes/db.php';

requireRole('admin');

// fetch platform statistics in a single query each
$stats = [
    'users'        => 0,
    'listings'     => 0,
    'applications' => 0,
    'unread_msgs'  => 0,
];

try {
    $pdo = getConnection();

    // use prepare/execute for convention consistency — no user input but avoids ->query() footgun
    $statsStmt = $pdo->prepare("
        SELECT
            (SELECT COUNT(*) FROM users)                              AS total_users,
            (SELECT COUNT(*) FROM job_listings WHERE status='active') AS active_listings,
            (SELECT COUNT(*) FROM applications)                      AS total_applications,
            (SELECT COUNT(*) FROM contact_messages WHERE is_read=0)  AS unread_messages
    ");
    $statsStmt->execute([]);
    $row = $statsStmt->fetch();

    if ($row) {
        $stats['users']        = (int) $row['total_users'];
        $stats['listings']     = (int) $row['active_listings'];
        $stats['applications'] = (int) $row['total_applications'];
        $stats['unread_msgs']  = (int) $row['unread_messages'];
    }

} catch (PDOException $e) {
    // stats remain at zero if the query fails
}

// fetch the five most recently registered users for a quick overview
$recentUsers = [];
try {
    $recentStmt = $pdo->prepare("
        SELECT user_id, name, email, role, is_active, created_at
          FROM users
         ORDER BY created_at DESC
         LIMIT 5
    ");
    $recentStmt->execute([]);
    $recentUsers = $recentStmt->fetchAll();
} catch (PDOException $e) {
    $recentUsers = [];
}

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard — TalentBridge</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .tb-chart-card {
            background: var(--tb-card-bg, #fff);
            border: 1px solid var(--tb-border-color, #e0e0e0);
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
            overflow: hidden;
        }

        .tb-chart-card .chart-header {
            background: var(--tb-light-bg, #f8f9fa);
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--tb-border-color, #e0e0e0);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .tb-chart-card .chart-header h3 {
            margin: 0;
            font-size: 1rem;
            font-weight: 600;
            color: var(--tb-primary, #007BFF);
        }

        .tb-chart-card .chart-header button {
            background: none;
            border: none;
            color: var(--tb-primary, #007BFF);
            cursor: pointer;
            padding: 0;
            font-size: 1.2rem;
        }

        .tb-chart-card .chart-header button:hover {
            color: var(--tb-primary-dark, #0056B3);
        }

        .tb-chart-card .chart-collapse {
            max-height: 1000px;
            overflow: hidden;
            transition: max-height 0.3s ease-in-out;
        }

        .tb-chart-card .chart-collapse:not(.show) {
            max-height: 0;
        }

        .tb-chart-card .chart-content {
            padding: 1.5rem;
        }

        .chart-filters {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .chart-filters select,
        .chart-filters label {
            font-size: 0.9rem;
        }

        .chart-wrapper {
            position: relative;
            height: 300px;
        }

        .chart-wrapper canvas {
            max-height: 100%;
        }

        #applicationsChart {
            max-height: 400px;
        }

        .industry-checkboxes-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: var(--tb-light-bg, #f8f9fa);
            border-radius: 0.25rem;
        }

        .form-check {
            margin: 0;
        }

        .form-check-label {
            cursor: pointer;
            margin-left: 0.5rem;
        }

        #toggleStackMode {
            transition: all 0.2s ease;
        }

        #toggleStackMode.active {
            background-color: var(--tb-primary, #007BFF);
            color: white;
            border-color: var(--tb-primary, #007BFF);
        }
    </style>
</head>
<body>

<?php require_once '../includes/nav.php'; ?>

<main>
    <section class="tb-section">
        <div class="container">

            <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
                <div>
                    <h1 class="tb-section-title mb-1">Admin Dashboard</h1>
                    <div class="tb-divider"></div>
                </div>
                <span class="text-muted small">Logged in as <?= sanitise($_SESSION['name']) ?></span>
            </div>

            <?php foreach ($flash as $type => $msg): ?>
                <div class="alert alert-<?= $type === 'error' ? 'danger' : sanitise($type) ?> tb-flash" role="alert">
                    <?= sanitise($msg) ?>
                </div>
            <?php endforeach; ?>

            <!-- stat cards -->
            <div class="row g-4 mb-5">

                <div class="col-sm-6 col-xl-3">
                    <div class="stat-card stat-card-blue">
                        <div class="stat-number"
                             data-stat-target="<?= $stats['users'] ?>"
                             aria-label="<?= $stats['users'] ?> total users">
                            <?= $stats['users'] ?>
                        </div>
                        <div class="stat-label">Total Users</div>
                        <a href="/admin/users.php"
                           class="stretched-link text-white-50 small mt-2 d-block text-decoration-none">
                            Manage users →
                        </a>
                    </div>
                </div>

                <div class="col-sm-6 col-xl-3">
                    <div class="stat-card stat-card-green">
                        <div class="stat-number"
                             data-stat-target="<?= $stats['listings'] ?>"
                             aria-label="<?= $stats['listings'] ?> active listings">
                            <?= $stats['listings'] ?>
                        </div>
                        <div class="stat-label">Active Listings</div>
                        <a href="/admin/listings.php"
                           class="stretched-link text-white-50 small mt-2 d-block text-decoration-none">
                            Manage listings →
                        </a>
                    </div>
                </div>

                <div class="col-sm-6 col-xl-3">
                    <div class="stat-card stat-card-purple">
                        <div class="stat-number"
                             data-stat-target="<?= $stats['applications'] ?>"
                             aria-label="<?= $stats['applications'] ?> total applications">
                            <?= $stats['applications'] ?>
                        </div>
                        <div class="stat-label">Total Applications</div>
                    </div>
                </div>

                <div class="col-sm-6 col-xl-3">
                    <div class="stat-card stat-card-orange">
                        <div class="stat-number"
                             data-stat-target="<?= $stats['unread_msgs'] ?>"
                             aria-label="<?= $stats['unread_msgs'] ?> unread messages">
                            <?= $stats['unread_msgs'] ?>
                        </div>
                        <div class="stat-label">Unread Messages</div>
                        <a href="/admin/messages.php"
                           class="stretched-link text-white-50 small mt-2 d-block text-decoration-none">
                            View messages →
                        </a>
                    </div>
                </div>

            </div>

            <!-- quick nav cards -->
            <div class="row g-4 mb-5">
                <div class="col-md-3">
                    <a href="/admin/users.php" class="text-decoration-none">
                        <div class="tb-card h-100 text-center py-4 tb-job-card">
                            <div style="font-size:2.5rem;margin-bottom:.5rem" aria-hidden="true">👥</div>
                            <h2 class="h6 fw-bold" style="color:var(--tb-primary)">Manage Users</h2>
                            <p class="text-muted small mb-0">Approve, suspend, or delete user accounts.</p>
                        </div>
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="/admin/listings.php" class="text-decoration-none">
                        <div class="tb-card h-100 text-center py-4 tb-job-card">
                            <div style="font-size:2.5rem;margin-bottom:.5rem" aria-hidden="true">📋</div>
                            <h2 class="h6 fw-bold" style="color:var(--tb-primary)">Manage Listings</h2>
                            <p class="text-muted small mb-0">Change status or remove job listings platform-wide.</p>
                        </div>
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="/admin/messages.php" class="text-decoration-none">
                        <div class="tb-card h-100 text-center py-4 tb-job-card">
                            <div style="font-size:2.5rem;margin-bottom:.5rem" aria-hidden="true">
                                ✉️<?php if ($stats['unread_msgs'] > 0): ?>
                                    <sup class="badge bg-danger" style="font-size:.5rem"><?= $stats['unread_msgs'] ?></sup>
                                <?php endif; ?>
                            </div>
                            <h2 class="h6 fw-bold" style="color:var(--tb-primary)">Contact Messages</h2>
                            <p class="text-muted small mb-0">Read, mark as read, and delete enquiries.</p>
                        </div>
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="/admin/audit_log.php" class="text-decoration-none">
                        <div class="tb-card h-100 text-center py-4 tb-job-card">
                            <div style="font-size:2.5rem;margin-bottom:.5rem" aria-hidden="true">🛡️</div>
                            <h2 class="h6 fw-bold" style="color:var(--tb-primary)">Security Audit Log</h2>
                            <p class="text-muted small mb-0">Review important security-related events.</p>
                        </div>
                    </a>
                </div>
            </div>

            <!-- data visualisations section -->
            <div id="chartsSection" class="mb-5">
                <div class="tb-chart-card">
                    <div class="chart-header p-0">
                        <ul class="nav nav-tabs border-0 w-100" id="adminChartTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active py-3 px-4 fw-bold" id="users-tab" data-bs-toggle="tab" data-bs-target="#users-pane" type="button" role="tab">New User Registrations</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link py-3 px-4 fw-bold" id="industry-tab" data-bs-toggle="tab" data-bs-target="#industry-pane" type="button" role="tab">Jobs by Industry</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link py-3 px-4 fw-bold" id="apps-tab" data-bs-toggle="tab" data-bs-target="#apps-pane" type="button" role="tab">Applications</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link py-3 px-4 fw-bold" id="geo-tab" data-bs-toggle="tab" data-bs-target="#geo-pane" type="button" role="tab">Geographic Activity</button>
                            </li>
                        </ul>
                    </div>

                    <div class="tab-content p-4" id="adminChartTabsContent">
                        <div class="tab-pane fade show active" id="users-pane" role="tabpanel" aria-labelledby="users-tab">
                            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-3">
                                <h4 class="h6 mb-0 text-muted">User registration trends</h4>
                                <div class="d-flex gap-2 flex-wrap align-items-center">
                                    <button id="toggleStackMode" class="btn btn-sm btn-outline-secondary" title="Toggle stacked/overlapping view">
                                        <i class="bi bi-stack"></i> <span>Stacked</span>
                                    </button>
                                    <select id="newUsersDateRange" class="form-select form-select-sm w-auto">
                                        <option value="7">Last 7 Days</option>
                                        <option value="14">Last 14 Days</option>
                                        <option value="30" selected>Last 30 Days</option>
                                        <option value="60">Last 60 Days</option>
                                        <option value="90">Last 90 Days</option>
                                        <option value="180">Last 6 Months</option>
                                        <option value="365">Last Year</option>
                                    </select>
                                </div>
                            </div>
                            <div id="newUsersChartContainer" class="chart-wrapper" style="height: 400px;">
                                <canvas id="newUsersChart"></canvas>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="industry-pane" role="tabpanel" aria-labelledby="industry-tab">
                            <div class="d-flex gap-3 mb-3 flex-wrap align-items-center">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="includeInactiveListings" checked>
                                    <label class="form-check-label small" for="includeInactiveListings">
                                        Include Closed Listings
                                    </label>
                                </div>
                                <select id="jobTypeFilter" class="form-select form-select-sm w-auto">
                                    <option value="">All Types</option>
                                    <option value="Full-time">Full-time</option>
                                    <option value="Part-time">Part-time</option>
                                    <option value="Contract">Contract</option>
                                    <option value="Internship">Internship</option>
                                    <option value="Remote">Remote</option>
                                </select>
                            </div>
                            <div id="industryCheckboxes" class="mb-3 d-flex flex-wrap gap-2"></div>
                            <div id="industryChartContainer" class="chart-wrapper">
                                <canvas id="industryChart"></canvas>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="apps-pane" role="tabpanel" aria-labelledby="apps-tab">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <h6 class="text-muted mb-2">Overall Applications</h6>
                                        <select id="applicationsDateRange" class="form-select form-select-sm w-auto mb-3">
                                            <option value="7">Last 7 Days</option>
                                            <option value="14">Last 14 Days</option>
                                            <option value="30" selected>Last 30 Days</option>
                                            <option value="60">Last 60 Days</option>
                                            <option value="90">Last 90 Days</option>
                                            <option value="180">Last 6 Months</option>
                                            <option value="365">Last Year</option>
                                        </select>
                                        <div id="applicationsChartContainer" class="chart-wrapper" style="height: 350px;">
                                            <canvas id="applicationsChart"></canvas>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <h6 class="text-muted mb-2">Applications by Industry</h6>
                                        <select id="industryApplicationFilter" class="form-select form-select-sm w-auto mb-3">
                                            <option value="">All Industries</option>
                                        </select>
                                        <div id="applicationsIndustryChartContainer" class="chart-wrapper" style="height: 350px;">
                                            <canvas id="applicationsIndustryChart"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="geo-pane" role="tabpanel" aria-labelledby="geo-tab">
                            <div class="d-flex gap-3 mb-3 flex-wrap align-items-center">
                                <div>
                                    <button type="button" id="geoOrderToggle" class="btn btn-sm btn-outline-primary">
                                        Order by: <strong id="geoOrderLabel">Job Listings ▼</strong>
                                    </button>
                                </div>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <label for="geoCountLimit" class="small mb-0" style="white-space: nowrap;">Top Countries:</label>
                                    <input type="number" id="geoCountLimit" class="form-control form-control-sm" 
                                           min="1" max="50" value="10" style="width: 70px;">
                                </div>
                            </div>
                            <div class="mb-3">
                                <h6 class="text-muted mb-2">Job Listings vs. Job Seekers by Location</h6>
                                <div id="geographicalChartContainer" class="chart-wrapper" style="height: 400px;">
                                    <canvas id="geographicalChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- recent users table -->
            <?php if (!empty($recentUsers)): ?>
                <div class="tb-card p-0 overflow-hidden">
                    <div class="px-4 pt-3 pb-2 d-flex justify-content-between align-items-center"
                         style="background:var(--tb-light-bg)">
                        <h2 class="h6 fw-bold mb-0" style="color:var(--tb-primary)">Recently Registered Users</h2>
                        <a href="/admin/users.php" class="btn btn-outline-primary btn-sm">View All</a>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead style="background:var(--tb-light-bg)">
                                <tr>
                                    <th scope="col" class="ps-4">Name</th>
                                    <th scope="col">Email</th>
                                    <th scope="col">Role</th>
                                    <th scope="col">Status</th>
                                    <th scope="col" class="pe-4">Joined</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentUsers as $u): ?>
                                    <tr>
                                        <td class="ps-4 fw-semibold"><?= sanitise($u['name']) ?></td>
                                        <td class="text-muted small"><?= sanitise($u['email']) ?></td>
                                        <td>
                                            <span class="badge <?= $u['role'] === 'admin' ? 'bg-danger' : ($u['role'] === 'employer' ? 'bg-primary' : 'bg-success') ?>">
                                                <?= sanitise(ucfirst($u['role'])) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge <?= $u['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                                                <?= $u['is_active'] ? 'Active' : 'Suspended' ?>
                                            </span>
                                        </td>
                                        <td class="pe-4 text-muted small">
                                            <?= date('d M Y', strtotime($u['created_at'])) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </section>
</main>

<footer class="tb-footer" role="contentinfo">
    <div class="container">
        <hr>
        <small>&copy; <?= date('Y') ?> TalentBridge Pte. Ltd. All rights reserved.</small>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="../assets/js/admin_stats.js"></script>
<script src="../assets/js/charts_dashboard.js"></script>
</body>
</html>
