<?php
/**
 * AJAX backend for audit log live search.
 *
 * Receives a search query, performs the database search, and returns a
 * JSON object containing the rendered HTML for the table body, the
 * results count string, and the pagination HTML.
 *
 * @package TalentBridge
 */

// --- custom error handling to ensure JSON is always returned ---
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});


// The main logic is wrapped in a try/catch to handle all errors gracefully.
try {
    session_start();
    require_once '../includes/helpers.php';
    require_once '../includes/auth.php';
    require_once '../includes/db.php';

    // this is an ajax endpoint, only admins allowed
    if (getUserRole() !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    // --- search & filter ---
    $searchTerm = trim($_GET['q'] ?? '');

    // --- pagination settings ---
    $perPage     = 30;
    $currentPage = max(1, (int) ($_GET['page'] ?? 1));
    $offset      = ($currentPage - 1) * $perPage;

    // --- build dynamic query using positional placeholders ---
    $whereClause = '';
    $queryParams = [];

    if (!empty($searchTerm)) {
        $whereClause = "
            WHERE al.action LIKE ?
               OR al.ip_address LIKE ?
               OR u.name LIKE ?
               OR u.email LIKE ?
               OR al.details LIKE ?
        ";
        $likeTerm = "%{$searchTerm}%";
        // Add the parameter 5 times, once for each `?` placeholder
        $queryParams = [$likeTerm, $likeTerm, $likeTerm, $likeTerm, $likeTerm];
    }

    // ---- fetch paginated log entries ----
    $pdo = getConnection();

    // Count query
    $countQuery = "SELECT COUNT(al.id) FROM audit_log al LEFT JOIN users u ON u.user_id = al.user_id $whereClause";
    $countStmt = $pdo->prepare($countQuery);
    $countStmt->execute($queryParams);
    $totalLogs  = (int) $countStmt->fetchColumn();
    $totalPages = (int) ceil($totalLogs / $perPage);

    // Main data query
    $listQuery = "
        SELECT al.*, u.name as user_name, u.email as user_email
          FROM audit_log al
          LEFT JOIN users u ON u.user_id = al.user_id
        $whereClause
         ORDER BY al.created_at DESC
         LIMIT ? OFFSET ?
    ";

    // Add pagination params for the list query
    $listParams = $queryParams;
    $listParams[] = $perPage;
    $listParams[] = $offset;

    $listStmt = $pdo->prepare($listQuery);
    // PDO needs to be told the types for LIMIT/OFFSET when using positional placeholders
    $paramIndex = 1;
    foreach ($listParams as $param) {
        $type = PDO::PARAM_STR;
        if ($param === $perPage || $param === $offset) {
            $type = PDO::PARAM_INT;
        }
        $listStmt->bindValue($paramIndex, $param, $type);
        $paramIndex++;
    }
    $listStmt->execute();
    $logs = $listStmt->fetchAll();


    // ---- render html partials ----
    ob_start();

    // render table body
    if (empty($logs)) {
        echo '<tr><td colspan="5"><div class="alert alert-info text-center py-4 mb-0">';
        echo !empty($searchTerm) ? 'No events found matching your search term.' : 'No security events have been logged yet.';
        echo '</div></td></tr>';
    } else {
        foreach ($logs as $log) {
            echo '<tr>';
            echo '<td class="ps-4 text-muted small" style="white-space: nowrap;">' . date('d M Y, H:i:s', strtotime($log['created_at'])) . '</td>';
            echo '<td class="fw-semibold"><span class="badge bg-secondary">' . sanitise($log['action']) . '</span></td>';
            echo '<td>';
            if ($log['user_id']) {
                echo '<div class="d-flex flex-column">';
                echo '<strong class="small">' . sanitise($log['user_name'] ?? 'N/A') . '</strong>';
                echo '<small class="text-muted">' . sanitise($log['user_email'] ?? 'ID: ' . $log['user_id']) . '</small>';
                echo '</div>';
            } else {
                echo '<span class="text-muted small">Guest / Unauthenticated</span>';
            }
            echo '</td>';
            echo '<td class="text-muted small">' . sanitise($log['ip_address']) . '</td>';
            echo '<td class="pe-4 small">';
            if (!empty($log['details'])) {
                $details = json_decode($log['details'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    echo '<pre class="small bg-light p-2 rounded mb-0"><code>';
                    echo sanitise(print_r($details, true));
                    echo '</code></pre>';
                } else {
                    echo sanitise($log['details']);
                }
            }
            echo '</td>';
            echo '</tr>';
        }
    }
    $tableBodyHtml = ob_get_clean();

    // render results count
    ob_start();
    echo 'Showing ' . count($logs) . ' of ' . $totalLogs . ' event' . ($totalLogs !== 1 ? 's' : '');
    if (!empty($searchTerm)) {
        echo ' (filtered)';
    }
    $resultsCountHtml = ob_get_clean();

    // render pagination
    ob_start();
    if ($totalPages > 1) {
        echo '<ul class="pagination justify-content-center">';
        $queryString = http_build_query(array_filter(['q' => $searchTerm]));
        // previous
        $prevPage = $currentPage - 1;
        echo '<li class="page-item ' . ($currentPage <= 1 ? 'disabled' : '') . '">';
        echo '<a class="page-link" href="?page=' . $prevPage . '&' . $queryString . '">&laquo; Previous</a>';
        echo '</li>';
        // numbers
        for ($p = 1; $p <= $totalPages; $p++) {
            echo '<li class="page-item ' . ($p === $currentPage ? 'active' : '') . '">';
            echo '<a class="page-link" href="?page=' . $p . '&' . $queryString . '">' . $p . '</a>';
            echo '</li>';
        }
        // next
        $nextPage = $currentPage + 1;
        echo '<li class="page-item ' . ($currentPage >= $totalPages ? 'disabled' : '') . '">';
        echo '<a class="page-link" href="?page=' . $nextPage . '&' . $queryString . '">Next &raquo;</a>';
        echo '</li>';
        echo '</ul>';
    }
    $paginationHtml = ob_get_clean();

    // --- send json response ---
    header('Content-Type: application/json');
    echo json_encode([
        'tableBody' => $tableBodyHtml,
        'resultsCount' => $resultsCountHtml,
        'pagination' => $paginationHtml,
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    error_log("Fatal error in ajax_audit_log_search.php: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    // Send a generic, safe error to the client
    echo json_encode(['error' => 'A server error occurred. Please check the server logs.']);
    exit;
}
