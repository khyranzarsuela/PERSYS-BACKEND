<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/session.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function send_auth_json(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');

    send_auth_json(405, [
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
}

start_app_session();

try {
    if (!is_logged_in()) {
        send_auth_json(401, [
            'success' => false,
            'message' => 'Please log in to continue.'
        ]);
    }

    send_auth_json(200, [
        'success' => true,
        'data' => [
            'account_id' => (int) $_SESSION['account_id'],
            'employee_id' => (int) $_SESSION['employee_id'],
            'role_id' => (int) $_SESSION['role_id'],
            'role_name' => $_SESSION['role_name'],
            'username' => $_SESSION['username']
        ]
    ]);

} catch (Throwable $e) {
    error_log(
        'PERSYS session validation error: ' . $e->getMessage()
    );

    send_auth_json(500, [
        'success' => false,
        'message' => 'Unable to verify your session. Please try again.'
    ]);
}
