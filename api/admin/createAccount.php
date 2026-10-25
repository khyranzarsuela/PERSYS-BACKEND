<?php

require_once dirname(__DIR__, 2) . '/session.php';
require_once dirname(__DIR__, 2) . '/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function accountResponse(int $status, bool $success, string $message): void
{
    http_response_code($status);
    echo json_encode([
        'success' => $success,
        'message' => $message
    ]);
    exit;
}

try {
    start_app_session();

    if (!is_logged_in()) {
        accountResponse(401, false, 'Please sign in to continue.');
    }

    if ((int) ($_SESSION['role_id'] ?? 0) !== 1) {
        accountResponse(403, false, 'Administrator access is required.');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        accountResponse(405, false, 'Method not allowed.');
    }

    $employeeId = trim((string) ($_POST['employee_id'] ?? ''));
    $roleId = trim((string) ($_POST['role_id'] ?? ''));
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (
        $employeeId === '' ||
        $roleId === '' ||
        $username === '' ||
        trim($password) === ''
    ) {
        accountResponse(422, false, 'Please complete all required fields.');
    }

    if (
        !ctype_digit($employeeId) ||
        (int) $employeeId <= 0 ||
        !in_array($roleId, ['1', '2', '3'], true)
    ) {
        accountResponse(422, false, 'Invalid employee or account role.');
    }

    if (strlen($username) > 100) {
        accountResponse(422, false, 'Username is too long.');
    }

    if (strlen($password) < 6) {
        accountResponse(
            422,
            false,
            'The temporary password must contain at least 6 characters.'
        );
    }

    $pdo = db();

    // Verify that the employee exists.
    $stmt = $pdo->prepare(
        'SELECT employee_id, personnel_type
         FROM employees
         WHERE employee_id = :employee_id
         LIMIT 1'
    );

    $stmt->execute([
        ':employee_id' => (int) $employeeId
    ]);

    $employee = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$employee) {
        accountResponse(404, false, 'Employee record was not found.');
    }

    // Enforce personnel-type and role compatibility.
    $allowedRoles = $employee['personnel_type'] === 'Teaching'
        ? ['1', '2']
        : ($employee['personnel_type'] === 'Non-Teaching'
            ? ['1', '3']
            : []);

    if (!in_array($roleId, $allowedRoles, true)) {
        accountResponse(
            422,
            false,
            'The selected role is not allowed for this personnel type.'
        );
    }

    // Prevent multiple accounts for the same employee.
    $stmt = $pdo->prepare(
        'SELECT account_id
         FROM accounts
         WHERE employee_id = :employee_id
         LIMIT 1'
    );

    $stmt->execute([
        ':employee_id' => (int) $employeeId
    ]);

    if ($stmt->fetch()) {
        accountResponse(
            409,
            false,
            'This employee already has a registered account.'
        );
    }

    // Check username availability.
    $stmt = $pdo->prepare(
        'SELECT account_id
         FROM accounts
         WHERE username = :username
         LIMIT 1'
    );

    $stmt->execute([
        ':username' => $username
    ]);

    if ($stmt->fetch()) {
        accountResponse(
            409,
            false,
            'This username is already taken. Please choose another.'
        );
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare(
        'INSERT INTO accounts
            (employee_id, role_id, username, password_hash)
         VALUES
            (:employee_id, :role_id, :username, :password_hash)'
    );

    $stmt->execute([
        ':employee_id' => (int) $employeeId,
        ':role_id' => (int) $roleId,
        ':username' => $username,
        ':password_hash' => $passwordHash
    ]);

    accountResponse(
        201,
        true,
        'Employee account created successfully.'
    );

} catch (PDOException $error) {

    error_log('PERSYS Create Account DB Error: ' . $error->getMessage());

    if ((string) $error->getCode() === '23000') {
        accountResponse(
            409,
            false,
            'The employee already has an account or the username is taken.'
        );
    }

    accountResponse(
        500,
        false,
        'A database error occurred while creating the account.'
    );

} catch (Throwable $error) {

    error_log('PERSYS Create Account Error: ' . $error->getMessage());

    accountResponse(
        500,
        false,
        'Unable to create the account. Please try again.'
    );
}
