<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/session.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function send_logout_json(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

// Only POST requests are allowed.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');

    send_logout_json(405, [
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
}

// Start the existing PHP session.
start_app_session();

// Logout does not require a database connection.
// Destroy the session whether the account is active,
// disabled, deleted, or already logged out.
logout_session();

send_logout_json(200, [
    'success' => true,
    'message' => 'You have been logged out successfully.'
]);
