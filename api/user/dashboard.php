<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database.php';
require_once dirname(__DIR__, 2) . '/session.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function send_user_dashboard_json(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');

    send_user_dashboard_json(405, [
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
}

start_app_session();

try {
    // Verify the session and current account status in MySQL.
    if (!is_logged_in()) {
        send_user_dashboard_json(401, [
            'success' => false,
            'message' => 'Please log in to continue.'
        ]);
    }

    // Only Teaching (2) and Non-Teaching (3) users can access this endpoint.
    $roleId = (int) $_SESSION['role_id'];

    if ($roleId !== USER_TEACHING_ROLE_ID && $roleId !== USER_NONTEACHING_ROLE_ID) {
        send_user_dashboard_json(403, [
            'success' => false,
            'message' => 'Access restricted to personnel accounts.'
        ]);
    }

    $pdo   = db();
    $empId = (int) $_SESSION['employee_id'];

    // --- Leave request counts (per employee) ---
    $stmtPending = $pdo->prepare(
        "SELECT COUNT(*) FROM leave_requests WHERE employee_id = ? AND status = 'Pending'"
    );
    $stmtPending->execute([$empId]);
    $pendingCount = (int) $stmtPending->fetchColumn();

    $stmtApproved = $pdo->prepare(
        "SELECT COUNT(*) FROM leave_requests WHERE employee_id = ? AND status = 'Approved'"
    );
    $stmtApproved->execute([$empId]);
    $approvedCount = (int) $stmtApproved->fetchColumn();

    $stmtRejected = $pdo->prepare(
        "SELECT COUNT(*) FROM leave_requests WHERE employee_id = ? AND status = 'Rejected'"
    );
    $stmtRejected->execute([$empId]);
    $rejectedCount = (int) $stmtRejected->fetchColumn();

    // --- Service credit balances (EARNED minus DEDUCTED per type) ---
    $stmtSC = $pdo->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN transaction_type = 'EARNED'   THEN credit_amount ELSE 0 END), 0)
          - COALESCE(SUM(CASE WHEN transaction_type = 'DEDUCTED' THEN credit_amount ELSE 0 END), 0)
         FROM service_credit_transactions
         WHERE employee_id = ? AND credit_type_id = ?"
    );

    $stmtSC->execute([$empId, 1]);
    $localBalance = (float) $stmtSC->fetchColumn();

    $stmtSC->execute([$empId, 2]);
    $nationalBalance = (float) $stmtSC->fetchColumn();

    // --- 5 most recent leave requests for this employee ---
    $stmtRecent = $pdo->prepare(
        "SELECT
            lr.leave_request_id,
            lr.request_number,
            lr.date_filed,
            lr.total_leave_days,
            lr.status,
            lt.leave_type_name
         FROM leave_requests lr
         INNER JOIN leave_types lt ON lt.leave_type_id = lr.leave_type_id
         WHERE lr.employee_id = ?
         ORDER BY lr.created_at DESC
         LIMIT 5"
    );
    $stmtRecent->execute([$empId]);
    $recentLeaves = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);

    send_user_dashboard_json(200, [
        'success' => true,
        'data' => [
            'user' => [
                'username'  => $_SESSION['username'],
                'role_name' => $_SESSION['role_name'],
            ],
            'stats' => [
                'pending'  => $pendingCount,
                'approved' => $approvedCount,
                'rejected' => $rejectedCount,
            ],
            'service_credits' => [
                'local'    => $localBalance,
                'national' => $nationalBalance,
            ],
            'recent_leave_requests' => $recentLeaves,
        ]
    ]);

} catch (Throwable $e) {
    error_log('PERSYS user dashboard API error: ' . $e->getMessage());

    send_user_dashboard_json(500, [
        'success' => false,
        'message' => 'Unable to load dashboard data.'
    ]);
}
