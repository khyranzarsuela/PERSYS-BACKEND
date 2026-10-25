<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

//role id must 1
define('ADMIN_ROLE_ID', 1);
define('USER_TEACHING_ROLE_ID', 2);
define('USER_NONTEACHING_ROLE_ID', 3);

/*
 * session.php — everything about the login SESSION lives here.
 *
 * A "session" lets the server remember who you are between page loads. PHP
 * stores your data on the server and gives the browser a cookie with a
 * session id. On every request the browser sends that cookie back, and PHP
 * loads your $_SESSION data again.
 */

/**
 * Start the session with safe cookie settings.
 * Call this at the top of every page that uses $_SESSION.
 */
function start_app_session(): void
{
    // Already started earlier in this request? Do nothing.
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Keep the session data alive on the server for the same length of time
    // as the cookie below.
    ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);

    // Control the session cookie the browser stores.
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,  // how long the cookie survives
        'path'     => '/',               // valid for the whole site
        // Only send over HTTPS when the connection is actually HTTPS.
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,              // JavaScript cannot read the cookie
        'samesite' => 'Lax',             // basic protection against CSRF
    ]);

    session_start();
}

/**
 * Is someone logged in right now?
 */

/**
 * Verify that the current PHP session still matches
 * an active account in the PERSYS database.
 *
 * Returns false and destroys the session when the
 * account is no longer authorized.
 *
 * Database failures are allowed to propagate so APIs
 * can return HTTP 500 instead of treating them as logout.
 */
function validate_account_session(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        start_app_session();
    }

    if (!isset(
        $_SESSION['account_id'],
        $_SESSION['employee_id'],
        $_SESSION['role_id'],
        $_SESSION['role_name'],
        $_SESSION['username']
    )) {
        return false;
    }

    $accountId = (int) $_SESSION['account_id'];

    if ($accountId <= 0) {
        logout_session();
        return false;
    }

    $stmt = db()->prepare("
        SELECT
            a.account_id,
            a.employee_id,
            a.role_id,
            a.username,
            a.is_active,
            r.role_name
        FROM accounts a
        INNER JOIN employees e
            ON e.employee_id = a.employee_id
        INNER JOIN roles r
            ON r.role_id = a.role_id
        WHERE a.account_id = ?
        LIMIT 1
    ");

    $stmt->execute([$accountId]);
    $account = $stmt->fetch();

    if (!$account || (int) $account['is_active'] !== 1) {
        logout_session();
        return false;
    }

    // Detect changes to the employee/account relationship.
    if (
        (int) $account['employee_id'] !==
        (int) $_SESSION['employee_id']
    ) {
        logout_session();
        return false;
    }

    // A changed role requires a new login.
    if (
        (int) $account['role_id'] !==
        (int) $_SESSION['role_id']
    ) {
        logout_session();
        return false;
    }

    // Reject unknown roles.
    if (!in_array((int) $account['role_id'], [
        ADMIN_ROLE_ID,
        USER_TEACHING_ROLE_ID,
        USER_NONTEACHING_ROLE_ID
    ], true)) {
        logout_session();
        return false;
    }

    // Keep non-security display information current.
    $_SESSION['username'] = $account['username'];
    $_SESSION['role_name'] = $account['role_name'];

    return true;
}

/**
 * Check whether the current session belongs to
 * a currently authorized PERSYS account.
 */
function is_logged_in(): bool
{
    return validate_account_session();
}



function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: /PERSYS/index.php');
        exit;
    }
}
/**
 * Guard a page: if the visitor is NOT logged in, send them to the login
 * page and stop. Call this at the top of pages that need a login.
 */
//employees cannot access it. only admins
//ADMIN ONLY
function require_admin(): void
{
    require_login();

    if ((int) $_SESSION['role_id'] !== ADMIN_ROLE_ID) {
        header('Location: /PERSYS/Pages/Users/userDashboard.php');
        exit;
    }
}
//admin cannot access it. only user
//USER ONLY
function require_user(): void
{
    require_login();

    if ((int) $_SESSION['role_id'] !== USER_TEACHING_ROLE_ID && (int) $_SESSION['role_id'] !== USER_NONTEACHING_ROLE_ID) {
        header('Location: /PERSYS/Pages/Admin/adminDashboard.php');
        exit;
    }
}

/**
 * Log the user out: wipe the data, delete the cookie, destroy the session.
 */
function logout_session(): void
{
    // 1. Empty the server-side data.
    $_SESSION = [];

    // 2. Tell the browser to delete the session cookie (set it to expire in
    //    the past).
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'] ?? '',
            $params['secure'],
            $params['httponly']
        );
    }

    // 3. Destroy the session on the server.
    session_destroy();
}
