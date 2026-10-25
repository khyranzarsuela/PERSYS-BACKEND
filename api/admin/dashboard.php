<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database.php';
require_once dirname(__DIR__, 2) . '/session.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function send_dashboard_json(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');

    send_dashboard_json(405, [
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
}

start_app_session();

try {
    // Verify the session and current account status in MySQL.
    if (!is_logged_in()) {
        send_dashboard_json(401, [
            'success' => false,
            'message' => 'Please log in to continue.'
        ]);
    }

    // Only administrators can access this endpoint.
    if ((int) $_SESSION['role_id'] !== ADMIN_ROLE_ID) {
        send_dashboard_json(403, [
            'success' => false,
            'message' => 'Administrator access is required.'
        ]);
    }

    $pdo = db();

    // Count active teaching personnel.
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM employees e
        INNER JOIN accounts a
            ON a.employee_id = e.employee_id
        WHERE a.is_active = 1
          AND e.personnel_type = 'Teaching'
    ");
    $stmt->execute();
    $teachingCount = (int) $stmt->fetchColumn();

    // Count active non-teaching personnel.
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM employees e
        INNER JOIN accounts a
            ON a.employee_id = e.employee_id
        WHERE a.is_active = 1
          AND e.personnel_type = 'Non-Teaching'
    ");
    $stmt->execute();
    $nonTeachingCount = (int) $stmt->fetchColumn();

    // Count pending leave requests.
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM leave_requests
        WHERE status = 'Pending'
    ");
    $stmt->execute();
    $pendingLeaveCount = (int) $stmt->fetchColumn();

    // Get the three most recent pending leave requests.
    $stmt = $pdo->prepare("
        SELECT
            lr.leave_request_id,
            lr.request_number,
            lr.start_datetime,
            lr.status,
            e.first_name,
            e.last_name,
            e.personnel_type,
            lt.leave_type_name
        FROM leave_requests lr
        INNER JOIN employees e
            ON lr.employee_id = e.employee_id
        INNER JOIN leave_types lt
            ON lr.leave_type_id = lt.leave_type_id
        WHERE lr.status = 'Pending'
        ORDER BY lr.created_at DESC
        LIMIT 3
    ");
    $stmt->execute();

    $recentLeaveRequests = $stmt->fetchAll();

    send_dashboard_json(200, [
        'success' => true,
        'data' => [
            'admin' => [
                'username' => $_SESSION['username'],
                'role_name' => $_SESSION['role_name']
            ],
            'statistics' => [
                'active_teaching' => $teachingCount,
                'active_non_teaching' => $nonTeachingCount,
                'pending_leave' => $pendingLeaveCount
            ],
            'recent_leave_requests' => $recentLeaveRequests
        ]
    ]);

} catch (Throwable $e) {
    error_log('PERSYS dashboard API error: ' . $e->getMessage());

    send_dashboard_json(500, [
        'success' => false,
        'message' => 'Unable to load dashboard data.'
    ]);
}
