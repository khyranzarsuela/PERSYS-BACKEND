<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/session.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function send_update_json(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function update_validation_error(string $message): never
{
    send_update_json(422, [
        'success' => false,
        'message' => $message
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    send_update_json(405, [
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
}

start_app_session();

try {
    if (!is_logged_in()) {
        send_update_json(401, [
            'success' => false,
            'message' => 'Please log in to continue.'
        ]);
    }

    if ((int) ($_SESSION['role_id'] ?? 0) !== ADMIN_ROLE_ID) {
        send_update_json(403, [
            'success' => false,
            'message' => 'Administrator access is required.'
        ]);
    }

    $employeeIdRaw = $_POST['employee_id'] ?? '';

    if (
        !is_string($employeeIdRaw) ||
        !ctype_digit($employeeIdRaw) ||
        (int) $employeeIdRaw < 1
    ) {
        update_validation_error('Invalid employee ID.');
    }

    $employeeId = (int) $employeeIdRaw;

    $fields = [
        'employee_number',
        'last_name',
        'first_name',
        'middle_name',
        'plantilla_item_number',
        'position',
        'salary_grade',
        'step_increment',
        'date_original_appointment',
        'date_last_promotion',
        'deped_email'
    ];

    $input = [];

    foreach ($fields as $field) {
        $value = $_POST[$field] ?? '';

        if (!is_string($value)) {
            update_validation_error('Invalid input for ' . $field . '.');
        }

        $input[$field] = trim($value);
    }

    $required = [
        'employee_number' => 'Employee number',
        'last_name' => 'Last name',
        'first_name' => 'First name',
        'plantilla_item_number' => 'Plantilla item number',
        'position' => 'Position',
        'salary_grade' => 'Salary grade',
        'step_increment' => 'Step increment',
        'date_original_appointment' => 'Date of original appointment',
        'deped_email' => 'DepEd email'
    ];

    foreach ($required as $field => $label) {
        if ($input[$field] === '') {
            update_validation_error($label . ' is required.');
        }
    }

    foreach (['salary_grade', 'step_increment'] as $field) {
        if (
            !ctype_digit($input[$field]) ||
            (int) $input[$field] < 1
        ) {
            update_validation_error(
                $field === 'salary_grade'
                    ? 'Salary grade must be a positive integer.'
                    : 'Step increment must be a positive integer.'
            );
        }
    }

    foreach (
        ['date_original_appointment', 'date_last_promotion']
        as $field
    ) {
        $value = $input[$field];

        if ($field === 'date_last_promotion' && $value === '') {
            continue;
        }

        $date = DateTime::createFromFormat('!Y-m-d', $value);

        if (!$date || $date->format('Y-m-d') !== $value) {
            update_validation_error(
                'Invalid date for ' . $field . '.'
            );
        }
    }

    if (!filter_var($input['deped_email'], FILTER_VALIDATE_EMAIL)) {
        update_validation_error('Invalid DepEd email address.');
    }

   

    $pdo = db();
    $pdo->beginTransaction();

    // Lock the selected employee while updating.
 

    // Prevent incompatible account roles.
   

    // Exclude this employee from duplicate checks.
   
    // Lock the employee record while updating.
    $stmt = $pdo->prepare("
        SELECT employee_id
        FROM employees
        WHERE employee_id = :employee_id
        FOR UPDATE
    ");

    $stmt->execute([
        ':employee_id' => $employeeId
    ]);

    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        $pdo->rollBack();

        send_update_json(404, [
            'success' => false,
            'message' => 'Employee record not found.'
        ]);
    }

    // Check ALL account roles linked to this employee.
 

    
    // ================================================
    // CHECK DUPLICATE EMPLOYEE NUMBER
    // ================================================

    $check = $pdo->prepare("
        SELECT employee_id
        FROM employees
        WHERE employee_number = :employee_number
          AND employee_id <> :employee_id
        LIMIT 1
    ");

    $check->execute([
        ':employee_number' => $input['employee_number'],
        ':employee_id' => $employeeId
    ]);

    if ($check->fetch()) {
        $pdo->rollBack();

        send_update_json(409, [
            'success' => false,
            'message' => 'Employee number already belongs to another employee.'
        ]);
    }

    // ================================================
    // CHECK DUPLICATE DEPED EMAIL
    // ================================================

    $check = $pdo->prepare("
        SELECT employee_id
        FROM employees
        WHERE deped_email = :deped_email
          AND employee_id <> :employee_id
        LIMIT 1
    ");

    $check->execute([
        ':deped_email' => $input['deped_email'],
        ':employee_id' => $employeeId
    ]);

    if ($check->fetch()) {
        $pdo->rollBack();

        send_update_json(409, [
            'success' => false,
            'message' => 'DepEd email already belongs to another employee.'
        ]);
    }

    // ================================================
    // UPDATE EMPLOYEE INFORMATION
    // ================================================

    $update = $pdo->prepare("
        UPDATE employees SET
            employee_number = :employee_number,
            last_name = :last_name,
            first_name = :first_name,
            middle_name = :middle_name,
            plantilla_item_number = :plantilla_item_number,
            position = :position,
            salary_grade = :salary_grade,
            step_increment = :step_increment,
            date_original_appointment = :date_original_appointment,
            date_last_promotion = :date_last_promotion,
            deped_email = :deped_email
        WHERE employee_id = :employee_id
    ");

    $update->execute([
        ':employee_id' => $employeeId,
        ':employee_number' => $input['employee_number'],
        ':last_name' => $input['last_name'],
        ':first_name' => $input['first_name'],
        ':middle_name' => $input['middle_name'] !== ''
            ? $input['middle_name'] : null,
        ':plantilla_item_number' => $input['plantilla_item_number'],
        ':position' => $input['position'],
        ':salary_grade' => (int) $input['salary_grade'],
        ':step_increment' => (int) $input['step_increment'],
        ':date_original_appointment' =>
            $input['date_original_appointment'],
        ':date_last_promotion' =>
            $input['date_last_promotion'] !== ''
                ? $input['date_last_promotion'] : null,
        ':deped_email' => $input['deped_email']
    ]);

    // Save the transaction.
    $pdo->commit();

    // Return successful response.
    send_update_json(200, [
        'success' => true,
        'message' => 'Employee information updated successfully.',
        'data' => [
            'employee_id' => $employeeId
        ]
    ]);



} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('PERSYS update employee DB error: ' . $e->getMessage());

    if ($e->getCode() === '23000') {
        send_update_json(409, [
            'success' => false,
            'message' => 'Employee number or DepEd email already exists.'
        ]);
    }

    send_update_json(500, [
        'success' => false,
        'message' => 'Unable to update employee information.'
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('PERSYS update employee error: ' . $e->getMessage());

    send_update_json(500, [
        'success' => false,
        'message' => 'An unexpected error occurred.'
    ]);
}
