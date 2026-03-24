<?php
/**
 * Admin users page — paginated user management.
 *
 * Allows the admin to approve/suspend accounts (toggle is_active) and
 * permanently delete users (CASCADE removes all related rows). All
 * state-changing actions use POST with CSRF. Admins cannot delete or
 * suspend their own account.
 *
 * @package TalentBridge
 */

session_start();
require_once '../includes/helpers.php';
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/csrf.php';

requireRole('admin');

$adminId = (int) $_SESSION['user_id'];

// pagination settings
$perPage     = max(5, min(100, (int) ($_GET['per_page'] ?? 20))); // Allow 5-100 per page
$currentPage = max(1, (int) ($_GET['page'] ?? 1));
$offset      = ($currentPage - 1) * $perPage;

// optional role filter
$filterRole = $_GET['role'] ?? '';
$validRoles = ['seeker', 'employer', 'admin', ''];

if (!in_array($filterRole, $validRoles, true)) {
    $filterRole = '';
}

// search functionality
$searchTerm = trim($_GET['search'] ?? '');
$searchTerm = strlen($searchTerm) > 0 ? $searchTerm : '';

// sorting functionality
$sortBy    = $_GET['sort_by'] ?? 'created_at';
$sortOrder = strtoupper($_GET['sort_order'] ?? 'DESC');
$validSortColumns = ['name', 'email', 'role', 'is_active', 'created_at'];
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

    $action        = $_POST['action']  ?? '';
    $targetUserId  = (int) ($_POST['user_id'] ?? 0);

    // prevent admins from acting on their own account
    if ($targetUserId === $adminId) {
        setFlash('error', 'You cannot modify your own admin account.');
        redirect('/admin/users.php');
    }

    if ($targetUserId > 0) {
        try {
            $pdo = getConnection();

            if ($action === 'suspend') {
                $pdo->prepare("UPDATE users SET is_active = 0 WHERE user_id = :uid")
                    ->execute([':uid' => $targetUserId]);
                log_audit_event('USER_SUSPENDED', $adminId, ['target_user_id' => $targetUserId]);
                setFlash('success', 'User suspended.');

            } elseif ($action === 'activate') {
                $pdo->prepare("UPDATE users SET is_active = 1 WHERE user_id = :uid")
                    ->execute([':uid' => $targetUserId]);
                log_audit_event('USER_ACTIVATED', $adminId, ['target_user_id' => $targetUserId]);
                setFlash('success', 'User activated.');

            } elseif ($action === 'delete') {
                // cascade deletes seeker_profiles/companies/applications/saved_jobs
                $pdo->prepare("DELETE FROM users WHERE user_id = :uid")
                    ->execute([':uid' => $targetUserId]);
                log_audit_event('USER_DELETED', $adminId, ['target_user_id' => $targetUserId]);
                setFlash('success', 'User deleted.');
            }

        } catch (PDOException $e) {
            setFlash('error', 'Action failed. Please try again.');
        }
    }

    // after POST, preserve the filters if they were active
    $intendedRole = $_POST['intended_role'] ?? '';
    $intendedPage = (int)($_POST['intended_page'] ?? 1);
    $intendedSearch = trim($_POST['intended_search'] ?? '');
    $intendedPerPage = max(5, min(100, (int)($_POST['intended_per_page'] ?? 20)));
    $intendedSortBy = $_POST['intended_sort_by'] ?? 'created_at';
    $intendedSortOrder = strtoupper($_POST['intended_sort_order'] ?? 'DESC');
    
    if (!in_array($intendedRole, $validRoles, true)) {
        $intendedRole = '';
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
    
    $redirectUrl = '/admin/users.php';
    $params = [];
    if ($intendedRole) {
        $params[] = 'role=' . urlencode($intendedRole);
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

// ---- fetch paginated users ----
try {
    $pdo = getConnection();

    // build a parameterised where clause depending on the role filter and search term
    $whereConditions = [];
    $params = [];
    
    if ($filterRole) {
        $whereConditions[] = 'role = :role';
        $params[':role'] = $filterRole;
    }
    
    if (strlen($searchTerm) > 0) {
        $whereConditions[] = '(name LIKE :search OR email LIKE :search)';
        $params[':search'] = '%' . $searchTerm . '%';
    }
    
    $whereClause = count($whereConditions) > 0 ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM users $whereClause");
    $countStmt->execute($params);
    $totalUsers  = (int) $countStmt->fetchColumn();
    $totalPages  = (int) ceil($totalUsers / $perPage);

    $listStmt = $pdo->prepare("
        SELECT user_id, name, email, role, is_active, created_at
          FROM users
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
    $users = $listStmt->fetchAll();

} catch (PDOException $e) {
    $users      = [];
    $totalPages = 1;
    $totalUsers = 0;
}

$flash     = getFlash();
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users — TalentBridge Admin</title>
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
                    <h1 class="tb-section-title mb-1">Manage Users</h1>
                    <div class="tb-divider"></div>
                </div>
                <a href="/admin/dashboard.php" class="btn btn-outline-primary btn-sm">← Dashboard</a>
            </div>

            <?php foreach ($flash as $type => $msg): ?>
                <div class="alert alert-<?= $type === 'error' ? 'danger' : sanitise($type) ?> tb-flash" role="alert">
                    <?= sanitise($msg) ?>
                </div>
            <?php endforeach; ?>

            <!-- role filter tabs -->
            <div class="mb-4">
                <nav aria-label="Filter users by role">
                    <ul class="nav nav-pills gap-1">
                        <?php
                        $tabRoles = ['' => 'All', 'seeker' => 'Seekers', 'employer' => 'Employers', 'admin' => 'Admins'];
                        foreach ($tabRoles as $roleVal => $roleLabel):
                            $href = '/admin/users.php';
                            $params = [];
                            if ($roleVal) {
                                $params[] = 'role=' . urlencode($roleVal);
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
                                <a class="nav-link <?= $filterRole === $roleVal ? 'active' : '' ?>"
                                   href="<?= sanitise($href) ?>"
                                   <?= $filterRole === $roleVal ? 'aria-current="true"' : '' ?>>
                                    <?= sanitise($roleLabel) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </nav>
            </div>

            <!-- search & per-page controls -->
            <div class="d-flex gap-3 mb-4 flex-wrap align-items-center">
                <form method="get" action="/admin/users.php" class="d-flex gap-2 flex-grow-1" style="max-width: 400px;">
                    <input type="hidden" name="role" value="<?= sanitise($filterRole) ?>">
                    <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
                    <input type="hidden" name="sort_by" value="<?= sanitise($sortBy) ?>">
                    <input type="hidden" name="sort_order" value="<?= sanitise($sortOrder) ?>">
                    <input type="text" name="search" class="form-control form-control-sm" 
                           placeholder="Search by name or email..." value="<?= sanitise($searchTerm) ?>">
                    <button type="submit" class="btn btn-sm btn-outline-primary">Search</button>
                    <?php if (strlen($searchTerm) > 0): ?>
                        <a href="/admin/users.php<?= $filterRole ? '?role=' . urlencode($filterRole) : '' ?>" class="btn btn-sm btn-outline-secondary">Clear</a>
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
                    let url = '/admin/users.php?per_page=' + this.value;
                    <?php if ($filterRole): ?>url += '&role=<?= urlencode($filterRole) ?>';<?php endif; ?>
                    <?php if (strlen($searchTerm) > 0): ?>url += '&search=<?= urlencode($searchTerm) ?>';<?php endif; ?>
                    <?php if ($sortBy != 'created_at' || $sortOrder != 'DESC'): ?>
                        url += '&sort_by=<?= urlencode($sortBy) ?>&sort_order=<?= urlencode($sortOrder) ?>';
                    <?php endif; ?>
                    window.location.href = url;
                });
            </script>

            <p class="text-muted small mb-3">
                Showing <?= count($users) ?> of <?= $totalUsers ?> user<?= $totalUsers !== 1 ? 's' : '' ?>
                <?= $filterRole ? '(' . sanitise(ucfirst($filterRole)) . 's only)' : '' ?>
                <?= strlen($searchTerm) > 0 ? ' (filtered)' : '' ?>
            </p>

            <?php if (empty($users)): ?>
                <div class="alert alert-info text-center py-4">No users found.</div>

            <?php else: ?>
                <div class="tb-card p-0 overflow-hidden mb-4">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead style="background:var(--tb-light-bg)">
                                <tr>
                                    <?php
                                    $sortableColumns = [
                                        'name' => 'Name',
                                        'email' => 'Email',
                                        'role' => 'Role',
                                        'is_active' => 'Status',
                                        'created_at' => 'Joined'
                                    ];
                                    
                                    foreach ($sortableColumns as $colName => $colLabel):
                                        $newSortOrder = 'ASC';
                                        $indicator = '';
                                        
                                        if ($sortBy === $colName) {
                                            $newSortOrder = $sortOrder === 'ASC' ? 'DESC' : 'ASC';
                                            $indicator = $sortOrder === 'ASC' ? ' ▲' : ' ▼';
                                        }
                                        
                                        $href = '/admin/users.php?sort_by=' . urlencode($colName) . '&sort_order=' . $newSortOrder;
                                        if ($filterRole) $href .= '&role=' . urlencode($filterRole);
                                        if (strlen($searchTerm) > 0) $href .= '&search=' . urlencode($searchTerm);
                                        if ($perPage != 20) $href .= '&per_page=' . $perPage;
                                        if ($currentPage > 1) $href .= '&page=' . $currentPage;
                                        
                                        $thClass = ($colName === 'name' ? 'ps-4' : '');
                                    ?>
                                        <th scope="col" class="<?= $thClass ?>" style="cursor: pointer;">
                                            <a href="<?= sanitise($href) ?>" class="text-decoration-none" style="color: inherit;">
                                                <?= sanitise($colLabel) ?><?= $indicator ?>
                                            </a>
                                        </th>
                                    <?php endforeach; ?>
                                    <th scope="col" class="pe-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                    <tr <?= !$user['is_active'] ? 'class="table-secondary"' : '' ?>>
                                        <td class="ps-4 fw-semibold"><?= sanitise($user['name']) ?></td>
                                        <td class="text-muted small"><?= sanitise($user['email']) ?></td>
                                        <td>
                                            <?php
                                            $roleBadge = [
                                                'admin'    => 'bg-danger',
                                                'employer' => 'bg-primary',
                                                'seeker'   => 'bg-success',
                                            ][$user['role']] ?? 'bg-secondary';
                                            ?>
                                            <span class="badge <?= $roleBadge ?>">
                                                <?= sanitise(ucfirst($user['role'])) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge <?= $user['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                                                <?= $user['is_active'] ? 'Active' : 'Suspended' ?>
                                            </span>
                                        </td>
                                        <td class="text-muted small">
                                            <?= date('d M Y', strtotime($user['created_at'])) ?>
                                        </td>
                                        <td class="pe-4">
                                            <?php if ((int)$user['user_id'] !== $adminId): ?>
                                                <div class="d-flex gap-2 flex-nowrap">

                                                    <!-- suspend / activate toggle -->
                                                    <form method="post" action="/admin/users.php">
                                                        <input type="hidden" name="csrf_token" value="<?= sanitise($csrfToken) ?>">
                                                        <input type="hidden" name="user_id"   value="<?= (int)$user['user_id'] ?>">
                                                        <input type="hidden" name="action"    value="<?= $user['is_active'] ? 'suspend' : 'activate' ?>">
                                                        <input type="hidden" name="intended_role"   value="<?= sanitise($filterRole) ?>">
                                                        <input type="hidden" name="intended_page"   value="<?= (int)$currentPage ?>">
                                                        <input type="hidden" name="intended_search" value="<?= sanitise($searchTerm) ?>">
                                                        <input type="hidden" name="intended_per_page" value="<?= (int)$perPage ?>">
                                                        <input type="hidden" name="intended_sort_by" value="<?= sanitise($sortBy) ?>">
                                                        <input type="hidden" name="intended_sort_order" value="<?= sanitise($sortOrder) ?>">
                                                        <button type="submit"
                                                            class="btn btn-sm <?= $user['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                                            onclick="return confirm('<?= $user['is_active'] ? 'Suspend' : 'Activate' ?> this user?')">
                                                            <?= $user['is_active'] ? 'Suspend' : 'Activate' ?>
                                                        </button>
                                                    </form>

                                                    <!-- delete -->
                                                    <form method="post" action="/admin/users.php">
                                                        <input type="hidden" name="csrf_token" value="<?= sanitise($csrfToken) ?>">
                                                        <input type="hidden" name="user_id"   value="<?= (int)$user['user_id'] ?>">
                                                        <input type="hidden" name="action"    value="delete">
                                                        <input type="hidden" name="intended_role"   value="<?= sanitise($filterRole) ?>">
                                                        <input type="hidden" name="intended_page"   value="<?= (int)$currentPage ?>">
                                                        <input type="hidden" name="intended_search" value="<?= sanitise($searchTerm) ?>">
                                                        <input type="hidden" name="intended_per_page" value="<?= (int)$perPage ?>">
                                                        <input type="hidden" name="intended_sort_by" value="<?= sanitise($sortBy) ?>">
                                                        <input type="hidden" name="intended_sort_order" value="<?= sanitise($sortOrder) ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger"
                                                            onclick="return confirm('Permanently delete <?= sanitise(addslashes($user['name'])) ?>? This cannot be undone.')">
                                                            Delete
                                                        </button>
                                                    </form>

                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted small">You</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- pagination -->
                <?php if ($totalPages > 1): ?>
                    <nav aria-label="User list pagination">
                        <ul class="pagination justify-content-center">
                            <?php
                            // Helper function to build pagination URL
                            $buildPaginationUrl = function($page) use ($filterRole, $searchTerm, $perPage, $sortBy, $sortOrder) {
                                $url = '?page=' . $page;
                                if ($filterRole) $url .= '&role=' . urlencode($filterRole);
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
