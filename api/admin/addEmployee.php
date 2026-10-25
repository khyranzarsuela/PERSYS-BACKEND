<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/session.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function send_add_employee_json(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function employee_validation_error(string $message): never
{
    send_add_employee_json(422, [
        'success' => false,
        'message' => $message
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    send_add_employee_json(405, [
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
}

start_app_session();

try {
    if (!is_logged_in()) {
        send_add_employee_json(401, [
            'success' => false,
            'message' => 'Please log in to continue.'
        ]);
    }

    if ((int) $_SESSION['role_id'] !== ADMIN_ROLE_ID) {
        send_add_employee_json(403, [
            'success' => false,
            'message' => 'Administrator access is required.'
        ]);
    }

    // Accept the existing HTML form fields.
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
        'deped_email',
        'personnel_type'
    ];

    $input = [];

    foreach ($fields as $field) {
        $value = $_POST[$field] ?? '';

        if (!is_string($value)) {
            employee_validation_error('Invalid input for ' . $field . '.');
        }

        $input[$field] = trim($value);
    }

    // Required fields (same rules as original).
    $required = [
        'employee_number' => 'Employee number',
        'last_name' => 'Last name',
        'first_name' => 'First name',
        'plantilla_item_number' => 'Plantilla item number',
        'position' => 'Position',
        'salary_grade' => 'Salary grade',
        'step_increment' => 'Step increment',
        'date_original_appointment' => 'Date of original appointment',
        'deped_email' => 'DepEd email',
        'personnel_type' => 'Personnel type'
    ];

    foreach ($required as $field => $label) {
        if ($input[$field] === '') {
            employee_validation_error($label . ' is required.');
        }
    }

    // Numeric validation.
    foreach (['salary_grade', 'step_increment'] as $field) {
        if (
            !ctype_digit($input[$field]) ||
            (int) $input[$field] < 1
        ) {
            employee_validation_error(
                $field === 'salary_grade'
                    ? 'Salary grade must be a positive integer.'
                    : 'Step increment must be a positive integer.'
            );
        }
    }

    // Date validation.
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
            employee_validation_error(
                $field === 'date_original_appointment'
                    ? 'Date of original appointment must be a valid date (YYYY-MM-DD).'
                    : 'Date of last promotion must be a valid date (YYYY-MM-DD).'
            );
        }
    }

    // DepEd email validation.
    if (!filter_var($input['deped_email'], FILTER_VALIDATE_EMAIL)) {
        employee_validation_error(
            'DepEd email is not a valid email address.'
        );
    }

    // Personnel type validation.
    if (!in_array(
        $input['personnel_type'],
        ['Teaching', 'Non-Teaching'],
        true
    )) {
        employee_validation_error(
            'Personnel type must be Teaching or Non-Teaching.'
        );
    }

    $pdo = db();

    // Duplicate employee number.
    $check = $pdo->prepare(
        'SELECT 1 FROM employees
         WHERE employee_number = :number LIMIT 1'
    );

    $check->execute([
        ':number' => $input['employee_number']
    ]);

    if ($check->fetch()) {
        send_add_employee_json(409, [
            'success' => false,
            'message' => 'Employee number already exists.'
        ]);
    }

    // Duplicate DepEd email.
    $check = $pdo->prepare(
        'SELECT 1 FROM employees
         WHERE deped_email = :email LIMIT 1'
    );

    $check->execute([
        ':email' => $input['deped_email']
    ]);

    if ($check->fetch()) {
        send_add_employee_json(409, [
            'success' => false,
            'message' => 'DepEd email already exists.'
        ]);
    }

    // Insert into employees only.
    $insert = $pdo->prepare("
        INSERT INTO employees (
            employee_number,
            last_name,
            first_name,
            middle_name,
            plantilla_item_number,
            position,
            salary_grade,
            step_increment,
            date_original_appointment,
            date_last_promotion,
            deped_email,
            personnel_type
        ) VALUES (
            :employee_number,
            :last_name,
            :first_name,
            :middle_name,
            :plantilla_item_number,
            :position,
            :salary_grade,
            :step_increment,
            :date_original_appointment,
            :date_last_promotion,
            :deped_email,
            :personnel_type
        )
    ");

    $insert->execute([
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
        ':deped_email' => $input['deped_email'],
        ':personnel_type' => $input['personnel_type']
    ]);

    send_add_employee_json(201, [
        'success' => true,
        'message' => 'Employee added successfully.',
        'data' => [
            'employee_id' => (int) $pdo->lastInsertId()
        ]
    ]);

} catch (PDOException $e) {
    error_log('PERSYS add employee database error: ' . $e->getMessage());

    // Handle database unique-constraint conflicts as well.
    if ($e->getCode() === '23000') {
        send_add_employee_json(409, [
            'success' => false,
            'message' => 'Employee number or DepEd email already exists.'
        ]);
    }

    send_add_employee_json(500, [
        'success' => false,
        'message' => 'Unable to save employee record.'
    ]);

} catch (Throwable $e) {
    error_log('PERSYS add employee error: ' . $e->getMessage());

    send_add_employee_json(500, [
        'success' => false,
        'message' => 'An unexpected error occurred.'
    ]);
}
