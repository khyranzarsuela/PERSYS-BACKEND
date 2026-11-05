<?php

require_once dirname(__DIR__, 3) . '/database.php';
require_once dirname(__DIR__, 3) . '/session.php';

header('Content-Type: application/json; charset=utf-8');

start_app_session();

/*
|--------------------------------------------------------------------------
| JSON RESPONSE HELPER
|--------------------------------------------------------------------------
*/

function respond(array $data, int $status = 200): void
{
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| AUTHENTICATION AND AUTHORIZATION
|--------------------------------------------------------------------------
*/

if (!is_logged_in()) {
    respond([
        'success' => false,
        'message' => 'Unauthorized. Please log in.'
    ], 401);
}

if ((int) $_SESSION['role_id'] !== ADMIN_ROLE_ID) {
    respond([
        'success' => false,
        'message' => 'Access denied. Admin privileges are required.'
    ], 403);
}

/*
|--------------------------------------------------------------------------
| REQUEST METHOD
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
| READ JSON REQUEST
|--------------------------------------------------------------------------
*/

$rawInput = file_get_contents('php://input');

if ($rawInput === false || trim($rawInput) === '') {
    respond([
        'success' => false,
        'message' => 'No service credit data was received.'
    ], 400);
}

$data = json_decode($rawInput, true);

if (
    !is_array($data) ||
    !isset($data['rows']) ||
    !is_array($data['rows'])
) {
    respond([
        'success' => false,
        'message' => 'Invalid service credit data.'
    ], 400);
}

$rows = $data['rows'];

if (count($rows) === 0) {
    respond([
        'success' => false,
        'message' => 'There are no service credit records to post.'
    ], 400);
}

/*
|--------------------------------------------------------------------------
| HELPER FUNCTIONS
|--------------------------------------------------------------------------
*/

function cleanValue($value): string
{
    if (!is_scalar($value) && $value !== null) {
        return '';
    }

    return trim((string) $value);
}

function validateDateValue($value): ?string
{
    $value = cleanValue($value);

    if ($value === '') {
        return null;
    }

    $date = DateTime::createFromFormat('!Y-m-d', $value);

    if (
        $date === false ||
        $date->format('Y-m-d') !== $value
    ) {
        return null;
    }

    return $value;
}

function normalizeEmployeeName(string $name): string
{
    $name = strtoupper(trim($name));
    $name = preg_replace('/[^A-Z0-9\s-]/', '', $name);
    $name = preg_replace('/\s+/', ' ', $name);

    return trim($name);
}

/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/

try {

    $pdo = db();

    /*
    |--------------------------------------------------------------------------
    | LOAD SERVICE CREDIT TYPES
    |--------------------------------------------------------------------------
    |
    | Local and National IDs are retrieved from the database.
    | Their IDs are not hard-coded.
    |
    */

    $typeStatement = $pdo->query("
        SELECT
            credit_type_id,
            credit_type_name
        FROM service_credit_types
    ");

    $creditTypes = $typeStatement->fetchAll(PDO::FETCH_ASSOC);

    $creditTypeMap = [];

    foreach ($creditTypes as $type) {

        $creditTypeMap[
            strtoupper(trim($type['credit_type_name']))
        ] = (int) $type['credit_type_id'];

    }

    if (
        !isset($creditTypeMap['LOCAL']) ||
        !isset($creditTypeMap['NATIONAL'])
    ) {
        respond([
            'success' => false,
            'message' => 'Local and National service credit types could not be found.'
        ], 500);
    }

    /*
    |--------------------------------------------------------------------------
    | PREPARE EMPLOYEE LOOKUP
    |--------------------------------------------------------------------------
    */

    $employeeStatement = $pdo->prepare("
        SELECT
            employee_id,
            employee_number,
            CONCAT(
                last_name,
                ', ',
                first_name,
                CASE
                    WHEN middle_name IS NOT NULL
                         AND middle_name <> ''
                    THEN CONCAT(' ', middle_name)
                    ELSE ''
                END
            ) AS employee_name
        FROM employees
        WHERE employee_number = ?
        LIMIT 1
    ");

    /*
    |--------------------------------------------------------------------------
    | VALIDATE EVERY ROW
    |--------------------------------------------------------------------------
    */

    $validatedRows = [];

    $errors = [];

    foreach ($rows as $index => $row) {

        $rowNumber = $index + 1;

        $rowErrors = [];

        if (!is_array($row)) {

            $errors[] = [
                'row' => $rowNumber,
                'employee_number' => '',
                'errors' => [
                    'Invalid service credit row data.'
                ]
            ];

            continue;
        }

        /*
         * Get submitted values.
         */

        $employeeNumber = cleanValue(
            $row['employee_number'] ?? ''
        );

        $employeeName = cleanValue(
            $row['employee_name'] ?? ''
        );

        $activityName = cleanValue(
            $row['name_of_activity'] ?? ''
        );

        $activityStartDate = validateDateValue(
            $row['activity_start_date'] ?? ''
        );

        $activityEndDate = validateDateValue(
            $row['activity_end_date'] ?? ''
        );

        $creditAmount = $row['number_of_service_credits'] ?? '';

        $nature = strtoupper(
            cleanValue(
                $row['nature_of_service_credits'] ?? ''
            )
        );

        /*
        |--------------------------------------------------------------------------
        | EMPLOYEE NUMBER
        |--------------------------------------------------------------------------
        */

        if ($employeeNumber === '') {

            $rowErrors[] = 'Employee Number is required.';

            $employee = false;

        } else {

            $employeeStatement->execute([
                $employeeNumber
            ]);

            $employee = $employeeStatement->fetch(PDO::FETCH_ASSOC);

            if (!$employee) {
                $rowErrors[] =
                    'Employee Number does not exist in the employee records.';
            }
            else{
                 // Validate employee name against the database.
                    $submittedName = normalizeEmployeeName($employeeName);

                    $databaseName = normalizeEmployeeName(
                        $employee['employee_name']
                    );

                    if ($submittedName === '') {
                        $rowErrors[] = 'Employee name is required.';
                    } elseif ($submittedName !== $databaseName) {
                        $rowErrors[] =
                            'Employee name does not match the employee number.';
                    }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | ACTIVITY
        |--------------------------------------------------------------------------
        */

        if ($activityName === '') {
            $rowErrors[] = 'Activity name is required.';
        }

        /*
        |--------------------------------------------------------------------------
        | ACTIVITY START DATE
        |--------------------------------------------------------------------------
        */

        if ($activityStartDate === null) {
            $rowErrors[] =
                'Activity start date is invalid or missing.';
        }

        /*
        |--------------------------------------------------------------------------
        | ACTIVITY END DATE
        |--------------------------------------------------------------------------
        */

        if ($activityEndDate === null) {
            $rowErrors[] =
                'Activity end date is invalid or missing.';
        }

        /*
        |--------------------------------------------------------------------------
        | DATE RELATIONSHIP
        |--------------------------------------------------------------------------
        */

        if (
            $activityStartDate !== null &&
            $activityEndDate !== null &&
            $activityEndDate < $activityStartDate
        ) {
            $rowErrors[] =
                'Activity end date cannot be earlier than the start date.';
        }

        /*
        |--------------------------------------------------------------------------
        | SERVICE CREDIT AMOUNT
        |--------------------------------------------------------------------------
        */

        if (
            $creditAmount === '' ||
            !is_numeric($creditAmount) ||
            !is_finite((float) $creditAmount)
        ) {

            $rowErrors[] =
                'Number of service credits must be a valid number.';

        } else {

            $creditAmount = (float) $creditAmount;

            if ($creditAmount <= 0) {
                $rowErrors[] =
                    'Number of service credits must be greater than 0.';
            }
        }

        /*
        |--------------------------------------------------------------------------
        | NATURE OF SERVICE CREDIT
        |--------------------------------------------------------------------------
        */

        if (
            $nature !== 'LOCAL' &&
            $nature !== 'NATIONAL'
        ) {
            $rowErrors[] =
                'Nature of service credit must be LOCAL or NATIONAL.';
        }

        /*
        |--------------------------------------------------------------------------
        | STOP VALIDATION FOR THIS ROW IF INVALID
        |--------------------------------------------------------------------------
        */

        if (count($rowErrors) > 0) {

            $errors[] = [
                'row' => $rowNumber,
                'employee_number' => $employeeNumber,
                'errors' => $rowErrors
            ];

            continue;
        }

        /*
        |--------------------------------------------------------------------------
        | STORE VALIDATED ROW
        |--------------------------------------------------------------------------
        */

        $validatedRows[] = [

            'employee_id' =>
                (int) $employee['employee_id'],

            'employee_number' =>
                $employee['employee_number'],

            'employee_name' =>
                $employee['employee_name'],

            'activity_name' =>
                $activityName,

            'activity_start_date' =>
                $activityStartDate,

            'activity_end_date' =>
                $activityEndDate,

            'credit_amount' =>
                $creditAmount,

            'credit_type_id' =>
                $creditTypeMap[$nature]

        ];
    }

    /*
    |--------------------------------------------------------------------------
    | STOP IF ANY ROW IS INVALID
    |--------------------------------------------------------------------------
    */

    if (count($errors) > 0) {

        respond([
            'success' => false,
            'message' =>
                'Some service credit records are invalid. No records were posted.',
            'invalid_count' => count($errors),
            'errors' => $errors
        ], 422);
    }

    /*
    |--------------------------------------------------------------------------
    | BEGIN DATABASE TRANSACTION
    |--------------------------------------------------------------------------
    */

    $pdo->beginTransaction();

    /*
    |--------------------------------------------------------------------------
    | CREATE UPLOAD BATCH
    |--------------------------------------------------------------------------
    */

    $fileName = cleanValue($data['file_name'] ?? '');

    if ($fileName === '') {
        $fileName = 'service_credits.xlsx';
    }

    $batchStatement = $pdo->prepare("
        INSERT INTO credit_upload_batches
        (
            uploaded_by,
            file_name,
            status,
            uploaded_at,
            posted_at
        )
        VALUES
        (
            ?,
            ?,
            ?,
            NOW(),
            NOW()
        )
    ");

    $batchStatement->execute([

        $_SESSION['account_id'],

        $fileName,

        'POSTED'

    ]);

    $uploadBatchId = (int) $pdo->lastInsertId();

    /*
    |--------------------------------------------------------------------------
    | PREPARE SERVICE CREDIT TRANSACTION INSERT
    |--------------------------------------------------------------------------
    */

    $transactionStatement = $pdo->prepare("
        INSERT INTO service_credit_transactions
        (
            employee_id,
            credit_type_id,
            upload_batch_id,
            activity_name,
            activity_start_date,
            activity_end_date,
            credit_amount,
            transaction_type,
            created_by
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?
        )
    ");

    /*
    |--------------------------------------------------------------------------
    | INSERT ALL VALIDATED RECORDS
    |--------------------------------------------------------------------------
    */

    foreach ($validatedRows as $row) {

        $transactionStatement->execute([

            $row['employee_id'],

            $row['credit_type_id'],

            $uploadBatchId,

            $row['activity_name'],

            $row['activity_start_date'],

            $row['activity_end_date'],

            $row['credit_amount'],

            'EARNED',

            $_SESSION['account_id']

        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | COMMIT DATABASE TRANSACTION
    |--------------------------------------------------------------------------
    */

    $pdo->commit();

    respond([
        'success' => true,
        'message' => 'Service credits were successfully posted.',
        'upload_batch_id' => $uploadBatchId,
        'posted_count' => count($validatedRows)
    ]);

} catch (Throwable $e) {

    /*
     * Roll back all database changes if anything fails.
     */

    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Service Credits Posting API Error: ' . $e->getMessage());

    respond([
        'success' => false,
        'message' => 'The service credits could not be posted.'
    ], 500);
}

?>
