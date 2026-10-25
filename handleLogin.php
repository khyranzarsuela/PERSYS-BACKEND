<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/session.php';

start_app_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ./index.php');
    exit;
}

$login = trim((string) ($_POST['login'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

if ($login === '' || $password === '') {
    $_SESSION['login_error'] = 'Please enter your email/username and password.';
    header('Location: ./index.php');
    exit;
}

/*
 * Find all active accounts matching either:
 * - the account username
 * - OR the employee's DepEd email
 *
 * We intentionally use fetchAll() because one employee can now
 * have multiple accounts with the same DepEd email.
 */
$stmt = db()->prepare(
    'SELECT 
        accounts.account_id,
        accounts.employee_id,
        accounts.role_id,
        roles.role_name,
        employees.deped_email,
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

/*
 * If multiple accounts match the same email,
 * check the password against each account.
 *
 * The account whose password matches becomes
 * the authenticated account.
 */
foreach ($accounts as $account) {
    if (password_verify($password, $account['password_hash'])) {
        $user = $account;
        break;
    }
}

/*
 * Authentication failed.
 */
if (!$user) {
    $_SESSION['login_error'] = 'Invalid email/username or password.';
    header('Location: ./index.php');
    exit;
}

/*
 * Prevent session fixation after successful login.
 */
session_regenerate_id(true);

/*
 * Store the authenticated ACCOUNT information.
 */
$_SESSION['account_id']  = (int) $user['account_id'];
$_SESSION['employee_id'] = (int) $user['employee_id'];
$_SESSION['role_id']     = (int) $user['role_id'];
$_SESSION['role_name']   = $user['role_name'];
$_SESSION['username']    = $user['username'];

/*
 * Redirect according to the ACCOUNT'S role.
 */
if ($_SESSION['role_id'] === ADMIN_ROLE_ID) {
    header('Location: Pages/Admin/adminDashboard.php');
} else {
    header('Location: Pages/Users/userDashboard.php');
}

exit;