<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database.php';
require_once dirname(__DIR__, 2) . '/session.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function send_json(int $status, array $data): never
{
    http_response_code($status);

    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');

    send_json(405, [
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
}

start_app_session();

$login = trim((string) ($_POST['login'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

if ($login === '' || $password === '') {
    send_json(400, [
        'success' => false,
        'message' => 'Please enter your email/username and password.'
    ]);
}

try {
    $stmt = db()->prepare(
        'SELECT
            accounts.account_id,
            accounts.employee_id,
            accounts.role_id,
            roles.role_name,
            accounts.username,
            accounts.password_hash
         FROM accounts
         INNER JOIN employees
            ON accounts.employee_id = employees.employee_id
         INNER JOIN roles
            ON accounts.role_id = roles.role_id
         WHERE accounts.is_active = 1
           AND (
                accounts.username = ?
                OR employees.deped_email = ?
           )'
    );

    $stmt->execute([$login, $login]);
    $accounts = $stmt->fetchAll();

    $user = false;

    foreach ($accounts as $account) {
        if (password_verify($password, $account['password_hash'])) {
            $user = $account;
            break;
        }
    }

    if (!$user) {
        send_json(401, [
            'success' => false,
            'message' => 'Invalid email/username or password.'
        ]);
    }

    session_regenerate_id(true);

    $_SESSION['account_id']  = (int) $user['account_id'];
    $_SESSION['employee_id'] = (int) $user['employee_id'];
    $_SESSION['role_id']     = (int) $user['role_id'];
    $_SESSION['role_name']   = $user['role_name'];
    $_SESSION['username']    = $user['username'];

    if ((int) $user['role_id'] === ADMIN_ROLE_ID) {
          $redirect = '/PERSYS-FRONTEND/Pages/Admin/adminDashboard.html';
    } elseif (
        (int) $user['role_id'] === USER_TEACHING_ROLE_ID ||
        (int) $user['role_id'] === USER_NONTEACHING_ROLE_ID
    ) {
        $redirect = '/PERSYS/Pages/Users/userDashboard.php';
    } else {
        logout_session();

        send_json(403, [
            'success' => false,
            'message' => 'This account does not have an authorized role.'
        ]);
    }

    send_json(200, [
        'success' => true,
        'message' => 'Login successful.',
        'redirect' => $redirect
    ]);

} catch (Throwable $e) {
    error_log('PERSYS login API error: ' . $e->getMessage());

    send_json(500, [
        'success' => false,
        'message' => 'A server error occurred. Please try again later.'
    ]);
}