<?php
/**
 * Admin listings page — platform-wide job listing management.
 *
 * Allows the admin to change the status of any listing or permanently
 * delete it. All state-changing actions use POST with CSRF.
 *
 * @package TalentBridge
 */

session_start();
require_once '../includes/helpers.php';
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/csrf.php';

requireRole('admin');

$validStatuses = ['active', 'closed', 'draft'];

// pagination settings
$perPage     = max(5, min(100, (int) ($_GET['per_page'] ?? 20)));
$currentPage = max(1, (int) ($_GET['page'] ?? 1));
$offset      = ($currentPage - 1) * $perPage;

// optional status filter
$filterStatus = $_GET['status'] ?? '';
if (!in_array($filterStatus, array_merge($validStatuses, ['']), true)) {
    $filterStatus = '';
}

// search functionality
$searchTerm = trim($_GET['search'] ?? '');
$searchTerm = strlen($searchTerm) > 0 ? $searchTerm : '';

// sorting functionality
$sortBy    = $_GET['sort_by'] ?? 'created_at';
$sortOrder = strtoupper($_GET['sort_order'] ?? 'DESC');
$validSortColumns = ['title', 'company_name', 'type', 'status', 'created_at'];
$validSortOrders  = ['ASC', 'DESC'];

if (!in_array($sortBy, $validSortColumns, true)) {
    $sortBy = 'created_at';
}
if (!in_array($sortOrder, $validSortOrders, true)) {
    $sortOrder = 'DESC';
}

// ---- handle POST actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }

    $action = $_POST['action'] ?? '';
    $jobId  = (int) ($_POST['job_id'] ?? 0);

    if ($jobId > 0) {
        try {
            $pdo = getConnection();

            if ($action === 'delete') {
                $pdo->prepare("DELETE FROM job_listings WHERE job_id = :jid")
                    ->execute([':jid' => $jobId]);
                setFlash('success', 'Listing deleted.');

            } elseif ($action === 'set_status') {
                $newStatus = trim($_POST['status'] ?? '');
                if (in_array($newStatus, $validStatuses, true)) {
                    $pdo->prepare("UPDATE job_listings SET status = :status WHERE job_id = :jid")
                        ->execute([':status' => $newStatus, ':jid' => $jobId]);
                    setFlash('success', 'Listing status updated.');
                }
            }

        } catch (PDOException $e) {
            setFlash('error', 'Action failed. Please try again.');
        }
    }

    // after POST, preserve the filters if they were active
    $intendedFilterStatus = $_POST['intended_status'] ?? '';
    $intendedPage = (int)($_POST['intended_page'] ?? 1);
    $intendedSearch = trim($_POST['intended_search'] ?? '');
    $intendedPerPage = max(5, min(100, (int)($_POST['intended_per_page'] ?? 20)));
    $intendedSortBy = $_POST['intended_sort_by'] ?? 'created_at';
    $intendedSortOrder = strtoupper($_POST['intended_sort_order'] ?? 'DESC');
    
    if (!in_array($intendedFilterStatus, array_merge($validStatuses, ['']), true)) {
        $intendedFilterStatus = '';
    }
    if ($intendedPage < 1) {
        $intendedPage = 1;
    }
    if (!in_array($intendedSortBy, $validSortColumns, true)) {
        $intendedSortBy = 'created_at';
    }
    if (!in_array($intendedSortOrder, $validSortOrders, true)) {
        $intendedSortOrder = 'DESC';
    }
    
    $redirectUrl = '/admin/listings.php';
    $params = [];
    if ($intendedFilterStatus) {
        $params[] = 'status=' . urlencode($intendedFilterStatus);
    }
    if ($intendedPage > 1) {
        $params[] = 'page=' . $intendedPage;
    }
    if (strlen($intendedSearch) > 0) {
        $params[] = 'search=' . urlencode($intendedSearch);
    }
    if ($intendedPerPage != 20) {
        $params[] = 'per_page=' . $intendedPerPage;
    }
    if ($intendedSortBy != 'created_at' || $intendedSortOrder != 'DESC') {
        $params[] = 'sort_by=' . urlencode($intendedSortBy);
        $params[] = 'sort_order=' . urlencode($intendedSortOrder);
    }
    if ($params) {
        $redirectUrl .= '?' . implode('&', $params);
    }
    redirect($redirectUrl);
}

// ---- fetch paginated listings ----
try {
    $pdo = getConnection();

    // build a parameterised where clause depending on the status filter and search term
    $whereConditions = [];
    $params = [];
    
    if ($filterStatus) {
        $whereConditions[] = 'jl.status = :status';
        $params[':status'] = $filterStatus;
    }
    
    if (strlen($searchTerm) > 0) {
        $whereConditions[] = '(jl.title LIKE :search OR c.company_name LIKE :search)';
        $params[':search'] = '%' . $searchTerm . '%';
    }
    
    $whereClause = count($whereConditions) > 0 ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

    // Count total listings
    $countStmt = $pdo->prepare("
        SELECT COUNT(*) FROM job_listings jl
        JOIN companies c ON c.company_id = jl.company_id
        $whereClause
    ");
    $countStmt->execute($params);
    $totalListings = (int) $countStmt->fetchColumn();
    $totalPages    = (int) ceil($totalListings / $perPage);

    // Fetch paginated listings
    $listStmt = $pdo->prepare("
        SELECT jl.job_id, jl.title, jl.type, jl.location, jl.status, jl.created_at,
               c.company_name,
               (SELECT COUNT(*) FROM applications WHERE job_id = jl.job_id) AS app_count
          FROM job_listings jl
          JOIN companies c ON c.company_id = jl.company_id
        $whereClause
         ORDER BY $sortBy $sortOrder
         LIMIT :limit OFFSET :offset
    ");
    $listStmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
    $listStmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $listStmt->bindValue($key, $value);
    }
    $listStmt->execute();
    $listings = $listStmt->fetchAll();

} catch (PDOException $e) {
    $listings = [];
    $totalPages = 1;
    $totalListings = 0;
}

$flash     = getFlash();
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Listings — TalentBridge Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>

<?php require_once '../includes/nav.php'; ?>

<main>
    <section class="tb-section">
        <div class="container">

            <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
                <div>
                    <h1 class="tb-section-title mb-1">Manage Listings</h1>
                    <div class="tb-divider"></div>
                </div>
                <a href="/admin/dashboard.php" class="btn btn-outline-primary btn-sm">← Dashboard</a>
            </div>

            <?php foreach ($flash as $type => $msg): ?>
                <div class="alert alert-<?= $type === 'error' ? 'danger' : sanitise($type) ?> tb-flash" role="alert">
                    <?= sanitise($msg) ?>
                </div>
            <?php endforeach; ?>

            <!-- status filter tabs -->
            <div class="mb-4">
                <nav aria-label="Filter listings by status">
                    <ul class="nav nav-pills gap-1">
                        <?php
                        foreach (['' => 'All', 'active' => 'Active', 'closed' => 'Closed', 'draft' => 'Draft'] as $s => $label):
                            $href = '/admin/listings.php';
                            $params = [];
                            if ($s) {
                                $params[] = 'status=' . urlencode($s);
                            }
                            if (strlen($searchTerm) > 0) {
                                $params[] = 'search=' . urlencode($searchTerm);
                            }
                            if ($perPage != 20) {
                                $params[] = 'per_page=' . $perPage;
                            }
                            if ($params) {
                                $href .= '?' . implode('&', $params);
                            }
                        ?>
                            <li class="nav-item">
                                <a class="nav-link <?= $filterStatus === $s ? 'active' : '' ?>"
                                   href="<?= sanitise($href) ?>"
                                   <?= $filterStatus === $s ? 'aria-current="true"' : '' ?>>
                                    <?= sanitise($label) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </nav>
            </div>

            <!-- search & per-page controls -->
            <div class="d-flex gap-3 mb-4 flex-wrap align-items-center">
                <form method="get" action="/admin/listings.php" class="d-flex gap-2 flex-grow-1" style="max-width: 400px;">
                    <input type="hidden" name="status" value="<?= sanitise($filterStatus) ?>">
                    <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
                    <input type="hidden" name="sort_by" value="<?= sanitise($sortBy) ?>">
                    <input type="hidden" name="sort_order" value="<?= sanitise($sortOrder) ?>">
                    <input type="text" name="search" class="form-control form-control-sm" 
                           placeholder="Search by job title or company..." value="<?= sanitise($searchTerm) ?>">
                    <button type="submit" class="btn btn-sm btn-outline-primary">Search</button>
                    <?php if (strlen($searchTerm) > 0): ?>
                        <a href="/admin/listings.php<?= $filterStatus ? '?status=' . urlencode($filterStatus) : '' ?>" class="btn btn-sm btn-outline-secondary">Clear</a>
                    <?php endif; ?>
                </form>
                
                <select name="per_page" id="perPageSelect" class="form-select form-select-sm" style="max-width: 120px;">
                    <option value="5" <?= $perPage == 5 ? 'selected' : '' ?>>5 per page</option>
                    <option value="10" <?= $perPage == 10 ? 'selected' : '' ?>>10 per page</option>
                    <option value="20" <?= $perPage == 20 ? 'selected' : '' ?>>20 per page</option>
                    <option value="50" <?= $perPage == 50 ? 'selected' : '' ?>>50 per page</option>
                    <option value="100" <?= $perPage == 100 ? 'selected' : '' ?>>100 per page</option>
                </select>
            </div>

            <script>
                document.getElementById('perPageSelect').addEventListener('change', function() {
                    let url = '/admin/listings.php?per_page=' + this.value;
                    <?php if ($filterStatus): ?>url += '&status=<?= urlencode($filterStatus) ?>';<?php endif; ?>
                    <?php if (strlen($searchTerm) > 0): ?>url += '&search=<?= urlencode($searchTerm) ?>';<?php endif; ?>
                    <?php if ($sortBy != 'created_at' || $sortOrder != 'DESC'): ?>
                        url += '&sort_by=<?= urlencode($sortBy) ?>&sort_order=<?= urlencode($sortOrder) ?>';
                    <?php endif; ?>
                    window.location.href = url;
                });
            </script>

            <p class="text-muted small mb-3">
                Showing <?= count($listings) ?> of <?= $totalListings ?> listing<?= $totalListings !== 1 ? 's' : '' ?>
                <?= $filterStatus ? '(' . sanitise(ucfirst($filterStatus)) . ' only)' : '' ?>
                <?= strlen($searchTerm) > 0 ? ' (filtered)' : '' ?>
            </p>

            <?php if (empty($listings)): ?>
                <div class="alert alert-info text-center py-4">No listings found.</div>

            <?php else: ?>
                <div class="tb-card p-0 overflow-hidden">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead style="background:var(--tb-light-bg)">
                                <tr>
                                    <?php
                                    $sortableColumns = [
                                        'title' => 'Job Title',
                                        'company_name' => 'Company',
                                        'type' => 'Type',
                                        'status' => 'Status',
                                        'created_at' => 'Posted'
                                    ];
                                    
                                    foreach ($sortableColumns as $colName => $colLabel):
                                        $newSortOrder = 'ASC';
                                        $indicator = '';
                                        
                                        if ($sortBy === $colName) {
                                            $newSortOrder = $sortOrder === 'ASC' ? 'DESC' : 'ASC';
                                            $indicator = $sortOrder === 'ASC' ? ' ▲' : ' ▼';
                                        }
                                        
                                        $href = '/admin/listings.php?sort_by=' . urlencode($colName) . '&sort_order=' . $newSortOrder;
                                        if ($filterStatus) $href .= '&status=' . urlencode($filterStatus);
                                        if (strlen($searchTerm) > 0) $href .= '&search=' . urlencode($searchTerm);
                                        if ($perPage != 20) $href .= '&per_page=' . $perPage;
                                        if ($currentPage > 1) $href .= '&page=' . $currentPage;
                                        
                                        $thClass = ($colName === 'title' ? 'ps-4' : '');
                                    ?>
                                        <th scope="col" class="<?= $thClass ?>" style="cursor: pointer;">
                                            <a href="<?= sanitise($href) ?>" class="text-decoration-none" style="color: inherit;">
                                                <?= sanitise($colLabel) ?><?= $indicator ?>
                                            </a>
                                        </th>
                                    <?php endforeach; ?>
                                    <th scope="col">Apps</th>
                                    <th scope="col" class="pe-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($listings as $listing): ?>
                                    <tr>
                                        <td class="ps-4 fw-semibold">
                                            <a href="/job_detail.php?id=<?= (int)$listing['job_id'] ?>"
                                               class="text-decoration-none" style="color:var(--tb-primary)">
                                                <?= sanitise($listing['title']) ?>
                                            </a>
                                        </td>
                                        <td class="text-muted small"><?= sanitise($listing['company_name']) ?></td>
                                        <td class="small"><?= sanitise($listing['type']) ?></td>
                                        <td>
                                            <?php
                                            $statusClass = [
                                                'active' => 'bg-success',
                                                'closed' => 'bg-secondary',
                                                'draft'  => 'bg-warning text-dark',
                                            ][$listing['status']] ?? 'bg-secondary';
                                            ?>
                                            <span class="badge <?= $statusClass ?>">
                                                <?= sanitise(ucfirst($listing['status'])) ?>
                                            </span>
                                        </td>
                                        <td class="text-muted small"><?= (int)$listing['app_count'] ?></td>
                                        <td class="text-muted small">
                                            <?= date('d M Y', strtotime($listing['created_at'])) ?>
                                        </td>
                                        <td class="pe-4">
                                            <div class="d-flex gap-2 flex-wrap">

                                                <!-- status change dropdown form -->
                                                <form method="post" action="/admin/listings.php"
                                                      class="d-flex gap-1 align-items-center">
                                                    <input type="hidden" name="csrf_token" value="<?= sanitise($csrfToken) ?>">
                                                    <input type="hidden" name="action"     value="set_status">
                                                    <input type="hidden" name="job_id"     value="<?= (int)$listing['job_id'] ?>">
                                                    <input type="hidden" name="intended_status"   value="<?= sanitise($filterStatus) ?>">
                                                    <input type="hidden" name="intended_page"     value="<?= (int)$currentPage ?>">
                                                    <input type="hidden" name="intended_search"   value="<?= sanitise($searchTerm) ?>">
                                                    <input type="hidden" name="intended_per_page" value="<?= (int)$perPage ?>">
                                                    <input type="hidden" name="intended_sort_by"  value="<?= sanitise($sortBy) ?>">
                                                    <input type="hidden" name="intended_sort_order" value="<?= sanitise($sortOrder) ?>">
                                                    <select name="status" class="form-select form-select-sm"
                                                            aria-label="Change listing status"
                                                            onchange="this.form.submit()">
                                                        <?php foreach ($validStatuses as $s): ?>
                                                            <option value="<?= sanitise($s) ?>"
                                                                <?= $listing['status'] === $s ? 'selected' : '' ?>>
                                                                <?= sanitise(ucfirst($s)) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </form>

                                                <!-- delete -->
                                                <form method="post" action="/admin/listings.php">
                                                    <input type="hidden" name="csrf_token" value="<?= sanitise($csrfToken) ?>">
                                                    <input type="hidden" name="action"     value="delete">
                                                    <input type="hidden" name="job_id"     value="<?= (int)$listing['job_id'] ?>">
                                                    <input type="hidden" name="intended_status"   value="<?= sanitise($filterStatus) ?>">
                                                    <input type="hidden" name="intended_page"     value="<?= (int)$currentPage ?>">
                                                    <input type="hidden" name="intended_search"   value="<?= sanitise($searchTerm) ?>">
                                                    <input type="hidden" name="intended_per_page" value="<?= (int)$perPage ?>">
                                                    <input type="hidden" name="intended_sort_by"  value="<?= sanitise($sortBy) ?>">
                                                    <input type="hidden" name="intended_sort_order" value="<?= sanitise($sortOrder) ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger"
                                                        onclick="return confirm('Delete this listing permanently?')">
                                                        Delete
                                                    </button>
                                                </form>

                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- pagination -->
                <?php if ($totalPages > 1): ?>
                    <nav aria-label="Listing list pagination" class="mt-4">
                        <ul class="pagination justify-content-center">
                            <?php
                            // Helper function to build pagination URL
                            $buildPaginationUrl = function($page) use ($filterStatus, $searchTerm, $perPage, $sortBy, $sortOrder) {
                                $url = '?page=' . $page;
                                if ($filterStatus) $url .= '&status=' . urlencode($filterStatus);
                                if (strlen($searchTerm) > 0) $url .= '&search=' . urlencode($searchTerm);
                                if ($perPage != 20) $url .= '&per_page=' . $perPage;
                                if ($sortBy != 'created_at' || $sortOrder != 'DESC') {
                                    $url .= '&sort_by=' . urlencode($sortBy) . '&sort_order=' . urlencode($sortOrder);
                                }
                                return $url;
                            };
                            ?>
                            <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= $buildPaginationUrl($currentPage - 1) ?>">
                                    &laquo; Previous
                                </a>
                            </li>
                            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                <li class="page-item <?= $p === $currentPage ? 'active' : '' ?>">
                                    <a class="page-link"
                                       href="<?= $buildPaginationUrl($p) ?>"
                                       <?= $p === $currentPage ? 'aria-current="page"' : '' ?>>
                                        <?= $p ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= $buildPaginationUrl($currentPage + 1) ?>">
                                    Next &raquo;
                                </a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
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
</body>
</html>
