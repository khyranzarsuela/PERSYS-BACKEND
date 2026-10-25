<?php
require_once dirname(__DIR__, 2) . '/session.php';
require_once dirname(__DIR__, 2) . '/database.php';

start_app_session();
require_admin();

header('Content-Type: application/json');

function respond(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function cleanValue($value): string
{
    return trim((string) $value);
}

function isValidDate(?string $date): bool
{
    if ($date === null || $date === '') {
        return false;
    }

    $parsedDate = DateTime::createFromFormat(
        'Y-m-d',
        $date
    );

    return $parsedDate !== false
        && $parsedDate->format('Y-m-d') === $date;
}


/*
|--------------------------------------------------------------------------
| Request validation
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    respond([
        'success' => false,
        'message' => 'Invalid request method.'
    ], 405);
}


/*
|--------------------------------------------------------------------------
| Read JSON body
|--------------------------------------------------------------------------
*/

$rawInput = file_get_contents('php://input');

$data = json_decode($rawInput, true);

if (!is_array($data)) {

    respond([
        'success' => false,
        'message' => 'Invalid JSON data received.'
    ], 400);
}

if (
    !isset($data['rows']) ||
    !is_array($data['rows'])
) {

    respond([
        'success' => false,
        'message' => 'No employee records were received.'
    ], 400);
}


/*
|--------------------------------------------------------------------------
| Basic row count validation
|--------------------------------------------------------------------------
*/

if (count($data['rows']) === 0) {

    respond([
        'success' => false,
        'message' => 'No employee records were found.'
    ], 400);
}


/*
|--------------------------------------------------------------------------
| Validate employee rows
|--------------------------------------------------------------------------
*/

$validRows = [];
$invalidRows = [];

$fileEmployeeNumbers = [];
$fileEmails = [];

foreach ($data['rows'] as $index => $row) {

    $displayRowNumber = $index + 1;

    if (!is_array($row)) {

        $invalidRows[] = [
            'row' => $displayRowNumber,
            'errors' => [
                'Invalid employee record.'
            ]
        ];

        continue;
    }


    /*
    |--------------------------------------------------------------------------
    | Read values
    |--------------------------------------------------------------------------
    */

    $employeeNumber = cleanValue(
        $row['employee_number'] ?? ''
    );

    $lastName = cleanValue(
        $row['last_name'] ?? ''
    );

    $firstName = cleanValue(
        $row['first_name'] ?? ''
    );

    $middleName = cleanValue(
        $row['middle_name'] ?? ''
    );

    $plantillaItemNumber = cleanValue(
        $row['plantilla_item_number'] ?? ''
    );

    $position = cleanValue(
        $row['position'] ?? ''
    );

    $salaryGrade = cleanValue(
        $row['salary_grade'] ?? ''
    );

    $stepIncrement = cleanValue(
        $row['step_increment'] ?? ''
    );

    $dateOriginalAppointment = cleanValue(
        $row['date_original_appointment'] ?? ''
    );

    $dateLastPromotion = cleanValue(
        $row['date_last_promotion'] ?? ''
    );

    $depedEmail = cleanValue(
        $row['deped_email'] ?? ''
    );

    $personnelType = cleanValue(
        $row['personnel_type'] ?? ''
    );


    /*
    |--------------------------------------------------------------------------
    | Error collection
    |--------------------------------------------------------------------------
    */

    $errors = [];


    /*
    |--------------------------------------------------------------------------
    | Required fields
    |--------------------------------------------------------------------------
    */

    if ($employeeNumber === '') {
        $errors[] = 'Employee number is required.';
    }

    if ($lastName === '') {
        $errors[] = 'Last name is required.';
    }

    if ($firstName === '') {
        $errors[] = 'First name is required.';
    }

    if ($plantillaItemNumber === '') {
        $errors[] = 'Plantilla item number is required.';
    }

    if ($position === '') {
        $errors[] = 'Position is required.';
    }

    if ($salaryGrade === '') {
        $errors[] = 'Salary grade is required.';
    }

    if ($stepIncrement === '') {
        $errors[] = 'Step increment is required.';
    }

    if ($dateOriginalAppointment === '') {
        $errors[] = 'Original appointment date is required.';
    }

    if ($depedEmail === '') {
        $errors[] = 'DepEd email is required.';
    }

    if ($personnelType === '') {
        $errors[] = 'Personnel type is required.';
    }


    /*
    |--------------------------------------------------------------------------
    | Numeric validation
    |--------------------------------------------------------------------------
    */

    if (
        $salaryGrade !== '' &&
        filter_var($salaryGrade, FILTER_VALIDATE_INT) === false
    ) {

        $errors[] =
            'Salary grade must be a whole number.';
    }

    if (
        $stepIncrement !== '' &&
        filter_var($stepIncrement, FILTER_VALIDATE_INT) === false
    ) {

        $errors[] =
            'Step increment must be a whole number.';
    }


    /*
    |--------------------------------------------------------------------------
    | Date validation
    |--------------------------------------------------------------------------
    */

    if (
        $dateOriginalAppointment !== '' &&
        !isValidDate($dateOriginalAppointment)
    ) {

        $errors[] =
            'Original appointment date is invalid.';
    }

    if (
        $dateLastPromotion !== '' &&
        !isValidDate($dateLastPromotion)
    ) {

        $errors[] =
            'Last promotion date is invalid.';
    }


    /*
    |--------------------------------------------------------------------------
    | Email validation
    |--------------------------------------------------------------------------
    */

    if (
        $depedEmail !== '' &&
        !filter_var(
            $depedEmail,
            FILTER_VALIDATE_EMAIL
        )
    ) {

        $errors[] =
            'Invalid DepEd email address.';
    }


    /*
    |--------------------------------------------------------------------------
    | Personnel type validation
    |--------------------------------------------------------------------------
    */

    if (
        $personnelType !== 'Teaching' &&
        $personnelType !== 'Non-Teaching'
    ) {

        $errors[] =
            'Personnel type must be Teaching or Non-Teaching.';
    }


    /*
    |--------------------------------------------------------------------------
    | Duplicate detection inside uploaded file
    |--------------------------------------------------------------------------
    */

    $employeeNumberKey =
        strtolower($employeeNumber);

    $emailKey =
        strtolower($depedEmail);


    if (
        $employeeNumber !== '' &&
        in_array(
            $employeeNumberKey,
            $fileEmployeeNumbers,
            true
        )
    ) {

        $errors[] =
            'Duplicate employee number in this upload.';
    }


    if (
        $depedEmail !== '' &&
        in_array(
            $emailKey,
            $fileEmails,
            true
        )
    ) {

        $errors[] =
            'Duplicate DepEd email in this upload.';
    }


    if ($employeeNumber !== '') {

        $fileEmployeeNumbers[] =
            $employeeNumberKey;
    }

    if ($depedEmail !== '') {

        $fileEmails[] =
            $emailKey;
    }


    /*
    |--------------------------------------------------------------------------
    | Store result
    |--------------------------------------------------------------------------
    */

    if (empty($errors)) {

        $validRows[] = [
            'excel_row' =>
                $row['excel_row'] ?? $displayRowNumber,

            'employee_number' =>
                $employeeNumber,

            'last_name' =>
                $lastName,

            'first_name' =>
                $firstName,

            'middle_name' =>
                $middleName,

            'plantilla_item_number' =>
                $plantillaItemNumber,

            'position' =>
                $position,

            'salary_grade' =>
                (int) $salaryGrade,

            'step_increment' =>
                (int) $stepIncrement,

            'date_original_appointment' =>
                $dateOriginalAppointment,

            'date_last_promotion' =>
                $dateLastPromotion !== ''
                    ? $dateLastPromotion
                    : null,

            'deped_email' =>
                $depedEmail,

            'personnel_type' =>
                $personnelType
        ];

    } else {

        $invalidRows[] = [
            'row' =>
                $row['excel_row'] ?? $displayRowNumber,

            'errors' =>
                $errors
        ];
    }
}


/*
|--------------------------------------------------------------------------
| Database duplicate checking
|--------------------------------------------------------------------------
*/

$pdo = db();

$existingEmployees = $pdo
    ->query("
        SELECT
            employee_number,
            deped_email
        FROM employees
    ")
    ->fetchAll();

$existingEmployeeNumbers = [];
$existingEmails = [];

foreach ($existingEmployees as $employee) {

    $existingEmployeeNumbers[] =
        strtolower(
            trim($employee['employee_number'])
        );

    $existingEmails[] =
        strtolower(
            trim($employee['deped_email'])
        );
}


/*
|--------------------------------------------------------------------------
| Check valid rows against database
|--------------------------------------------------------------------------
*/

$finalValidRows = [];

foreach ($validRows as $row) {

    $errors = [];

    $employeeNumberKey =
        strtolower($row['employee_number']);

    $emailKey =
        strtolower($row['deped_email']);


    if (
        in_array(
            $employeeNumberKey,
            $existingEmployeeNumbers,
            true
        )
    ) {

        $errors[] =
            'Employee number already exists in the database.';
    }


    if (
        in_array(
            $emailKey,
            $existingEmails,
            true
        )
    ) {

        $errors[] =
            'DepEd email already exists in the database.';
    }


    if (empty($errors)) {

        $finalValidRows[] = $row;

    } else {

        $invalidRows[] = [
            'row' =>
                $row['excel_row'],

            'errors' =>
                $errors
        ];
    }
}


/*
|--------------------------------------------------------------------------
| Stop if there are invalid records
|--------------------------------------------------------------------------
*/

if (!empty($invalidRows)) {

    respond([
        'success' => false,
        'message' =>
            'Some employee records contain errors. No records were imported.',
        'valid_count' =>
            count($finalValidRows),
        'invalid_count' =>
            count($invalidRows),
        'invalid_rows' =>
            $invalidRows
    ], 422);
}


/*
|--------------------------------------------------------------------------
| Insert employees into database
|--------------------------------------------------------------------------
*/

try {

    $pdo->beginTransaction();

    $insertEmployee = $pdo->prepare("
        INSERT INTO employees (
            employee_number,
            personnel_type,
            last_name,
            first_name,
            middle_name,
            plantilla_item_number,
            position,
            salary_grade,
            step_increment,
            date_original_appointment,
            date_last_promotion,
            deped_email
        )
        VALUES (
            :employee_number,
            :personnel_type,
            :last_name,
            :first_name,
            :middle_name,
            :plantilla_item_number,
            :position,
            :salary_grade,
            :step_increment,
            :date_original_appointment,
            :date_last_promotion,
            :deped_email
        )
    ");

    $insertedCount = 0;

    foreach ($finalValidRows as $row) {

        $insertEmployee->execute([

            ':employee_number' =>
                $row['employee_number'],

            ':personnel_type' =>
                $row['personnel_type'],

            ':last_name' =>
                $row['last_name'],

            ':first_name' =>
                $row['first_name'],

            ':middle_name' =>
                $row['middle_name'] !== ''
                    ? $row['middle_name']
                    : null,

            ':plantilla_item_number' =>
                $row['plantilla_item_number'],

            ':position' =>
                $row['position'],

            ':salary_grade' =>
                $row['salary_grade'],

            ':step_increment' =>
                $row['step_increment'],

            ':date_original_appointment' =>
                $row['date_original_appointment'],

            ':date_last_promotion' =>
                $row['date_last_promotion'],

            ':deped_email' =>
                $row['deped_email']
        ]);

        $insertedCount++;
    }

    $pdo->commit();


    /*
    |--------------------------------------------------------------------------
    | Success response
    |--------------------------------------------------------------------------
    */

    respond([

        'success' => true,

        'message' =>
            'Employee records were successfully imported.',

        'inserted_count' =>
            $insertedCount

    ]);

} catch (Throwable $e) {

    /*
    |--------------------------------------------------------------------------
    | Rollback
    |--------------------------------------------------------------------------
    */

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Master file import failed: ' .
        $e->getMessage()
    );

    respond([

        'success' => false,

        'message' =>
            'The employee records could not be imported. No changes were saved.'

    ], 500);
}