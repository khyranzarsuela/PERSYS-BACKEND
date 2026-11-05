<?php

require_once dirname(__DIR__, 3) . '/database.php';
require_once dirname(__DIR__, 3) . '/session.php';
require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

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
| CHECK UPLOADED FILE
|--------------------------------------------------------------------------
*/

if (!isset($_FILES['service_credits_file'])) {
    respond([
        'success' => false,
        'message' => 'Please choose an Excel file.'
    ], 400);
}

$file = $_FILES['service_credits_file'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    respond([
        'success' => false,
        'message' => 'The Excel file could not be uploaded.'
    ], 400);
}

/*
|--------------------------------------------------------------------------
| CHECK FILE EXTENSION
|--------------------------------------------------------------------------
*/

$extension = strtolower(
    pathinfo($file['name'], PATHINFO_EXTENSION)
);

$allowedExtensions = ['xlsx', 'xls'];

if (!in_array($extension, $allowedExtensions, true)) {
    respond([
        'success' => false,
        'message' => 'Please upload an .xlsx or .xls file.'
    ], 400);
}

/*
|--------------------------------------------------------------------------
| HELPER FUNCTIONS
|--------------------------------------------------------------------------
*/

function cleanValue($value): string
{
    return trim((string) $value);
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
| PARSE ACTIVITY DATE RANGE
|--------------------------------------------------------------------------
|
| Accepted examples:
| 09/25/2026
| 2026-09-25
| September 25, 2026
| September 25-26, 2026
| SEPTEMBER 25-26
|
|--------------------------------------------------------------------------
*/

function parseActivityDateRange($value): array
{
    if ($value === null || $value === '') {
        return [
            'start' => null,
            'end' => null
        ];
    }

    // Excel numeric date.
    if (is_numeric($value)) {
        try {
            $date = Date::excelToDateTimeObject($value)
                ->format('Y-m-d');

            return [
                'start' => $date,
                'end' => $date
            ];

        } catch (Throwable $e) {
            return [
                'start' => null,
                'end' => null
            ];
        }
    }

    $text = trim((string) $value);

    $text = preg_replace('/\s+/', ' ', $text);

    $text = str_replace(
        ['–', '—'],
        '-',
        $text
    );

    // Try single-date formats first.
    $singleDateFormats = [
        'm/d/Y',
        'm/d/y',
        'n/j/Y',
        'n/j/y',
        'Y-m-d',
        'F j, Y',
        'F j Y',
        'M j, Y',
        'M j Y'
    ];

    foreach ($singleDateFormats as $format) {
        $date = DateTime::createFromFormat(
            $format,
            $text
        );

        if ($date !== false) {
            return [
                'start' => $date->format('Y-m-d'),
                'end' => $date->format('Y-m-d')
            ];
        }
    }

    // Ranges with an explicit year.
    if (
        preg_match(
            '/^([A-Za-z]+)\s+(\d{1,2})\s*-\s*(\d{1,2})(?:,\s*|\s+)(\d{4})$/',
            $text,
            $matches
        )
    ) {
        $month = $matches[1];
        $startDay = (int) $matches[2];
        $endDay = (int) $matches[3];
        $year = (int) $matches[4];

        $startDate = DateTime::createFromFormat(
            'F j Y',
            "$month $startDay $year"
        );

        $endDate = DateTime::createFromFormat(
            'F j Y',
            "$month $endDay $year"
        );

        if ($startDate !== false && $endDate !== false) {
            return [
                'start' => $startDate->format('Y-m-d'),
                'end' => $endDate->format('Y-m-d')
            ];
        }
    }

    // Ranges without a year use the current year.
    if (
        preg_match(
            '/^([A-Za-z]+)\s+(\d{1,2})\s*-\s*(\d{1,2})$/',
            $text,
            $matches
        )
    ) {
        $month = $matches[1];
        $startDay = (int) $matches[2];
        $endDay = (int) $matches[3];
        $year = (int) date('Y');

        $startDate = DateTime::createFromFormat(
            'F j Y',
            "$month $startDay $year"
        );

        $endDate = DateTime::createFromFormat(
            'F j Y',
            "$month $endDay $year"
        );

        if ($startDate !== false && $endDate !== false) {
            return [
                'start' => $startDate->format('Y-m-d'),
                'end' => $endDate->format('Y-m-d')
            ];
        }
    }

    return [
        'start' => null,
        'end' => null
    ];
}

/*
|--------------------------------------------------------------------------
| READ AND VALIDATE EXCEL FILE
|--------------------------------------------------------------------------
*/

try {

    $spreadsheet = IOFactory::load($file['tmp_name']);

    $worksheet = $spreadsheet->getActiveSheet();

    $highestRow = $worksheet->getHighestRow();

} catch (Throwable $e) {

    respond([
        'success' => false,
        'message' => 'The Excel file could not be read.'
    ], 400);
}

/*
|--------------------------------------------------------------------------
| EXPECTED EXCEL HEADERS
|--------------------------------------------------------------------------
*/

$expectedHeaders = [
    'EMPLOYEE NUMBER',
    'EMPLOYEE NAME',
    'NAME OF ACTIVITY',
    'DATE OF ACTIVITY',
    'NO. SERVICE CREDIT',
    'NATURE OF SERVICE CREDIT'
];

$headers = [];

for ($column = 1; $column <= count($expectedHeaders); $column++) {

    $value = $worksheet
        ->getCell([$column, 1])
        ->getFormattedValue();

    $headers[] = strtoupper(trim((string) $value));
}

if ($headers !== $expectedHeaders) {
    respond([
        'success' => false,
        'message' => 'The Excel headers do not match the required Service Credit format.',
        'expected_headers' => $expectedHeaders,
        'received_headers' => $headers
    ], 400);
}

/*
|--------------------------------------------------------------------------
| DATABASE AND REFERENCE DATA
|--------------------------------------------------------------------------
*/

try {

    $pdo = db();

    /*
     * Load employees once.
     * Employee number is used to identify each employee.
     */

    $employeeStatement = $pdo->query("
        SELECT
            employee_id,
            employee_number,
            last_name,
            first_name,
            middle_name
        FROM employees
    ");

    $employees = [];

    foreach ($employeeStatement->fetchAll(PDO::FETCH_ASSOC) as $employee) {

        $employeeKey = strtolower(
            trim($employee['employee_number'])
        );

        $employees[$employeeKey] = $employee;
    }

    /*
     * Load service credit types once.
     */

    $creditTypeStatement = $pdo->query("
        SELECT
            credit_type_id,
            credit_type_name
        FROM service_credit_types
    ");

    $creditTypes = [];

    foreach ($creditTypeStatement->fetchAll(PDO::FETCH_ASSOC) as $creditType) {

        $creditTypeKey = strtolower(
            trim($creditType['credit_type_name'])
        );

        $creditTypes[$creditTypeKey] = $creditType;
    }

    /*
    |--------------------------------------------------------------------------
    | PROCESS EXCEL ROWS
    |--------------------------------------------------------------------------
    */

    $rows = [];

    $validCount = 0;

    $invalidCount = 0;

    for ($rowNumber = 2; $rowNumber <= $highestRow; $rowNumber++) {

        $errors = [];

        /*
         * Read Excel values.
         */

        $employeeNumber = cleanValue(
            $worksheet->getCell([1, $rowNumber])->getFormattedValue()
        );

        $employeeName = cleanValue(
            $worksheet->getCell([2, $rowNumber])->getFormattedValue()
        );

        $nameOfActivity = cleanValue(
            $worksheet->getCell([3, $rowNumber])->getFormattedValue()
        );

        // Use raw value for Excel date processing.
        $activityDateRaw = $worksheet
            ->getCell([4, $rowNumber])
            ->getValue();

        $numberOfServiceCredits = cleanValue(
            $worksheet->getCell([5, $rowNumber])->getFormattedValue()
        );

        $natureOfServiceCredits = strtoupper(
            cleanValue(
                $worksheet->getCell([6, $rowNumber])->getFormattedValue()
            )
        );

        /*
         * Skip completely empty rows.
         */

        $allValues = [
            $employeeNumber,
            $employeeName,
            $nameOfActivity,
            $activityDateRaw,
            $numberOfServiceCredits,
            $natureOfServiceCredits
        ];

        $hasAnyValue = false;

        foreach ($allValues as $value) {
            if ($value !== null && $value !== '') {
                $hasAnyValue = true;
                break;
            }
        }

        if (!$hasAnyValue) {
            continue;
        }

        /*
         * Parse activity dates.
         */

        $activityDates = parseActivityDateRange(
            $activityDateRaw
        );

        $activityStartDate = $activityDates['start'];
        $activityEndDate = $activityDates['end'];

        /*
         * Required field validation.
         */

        if ($employeeNumber === '') {
            $errors[] = 'Employee number is required.';
        }

        if ($employeeName === '') {
            $errors[] = 'Employee name is required.';
        }

        if ($nameOfActivity === '') {
            $errors[] = 'Name of activity is required.';
        }

        if ($activityStartDate === null) {
            $errors[] = 'Date of activity is required or invalid.';
        }

        if ($numberOfServiceCredits === '') {
            $errors[] = 'Number of service credits is required.';
        }

        if ($natureOfServiceCredits === '') {
            $errors[] = 'Nature of service credit is required.';
        }

        /*
         * Service credit amount validation.
         */

        if ($numberOfServiceCredits !== '') {

            if (
                !is_numeric($numberOfServiceCredits) ||
                (float) $numberOfServiceCredits <= 0
            ) {
                $errors[] = 'Number of service credits must be greater than 0.';
            }
        }

        /*
         * Employee number validation.
         */

        $employeeId = null;
        $databaseEmployeeName = null;

        $employeeKey = strtolower(trim($employeeNumber));

        if ($employeeNumber !== '') {

            if (isset($employees[$employeeKey])) {

                $employee = $employees[$employeeKey];

                $employeeId = (int) $employee['employee_id'];

                $databaseEmployeeName = trim(
                    $employee['last_name'] . ', ' .
                    $employee['first_name'] . ' ' .
                    ($employee['middle_name'] ?? '')
                );

            } else {

                $errors[] = 'Employee number does not exist in the system.';
            }
        }

        /*
         * Employee name validation.
         */

        if (
            $employeeName !== '' &&
            $databaseEmployeeName !== null
        ) {

            $excelName = normalizeEmployeeName($employeeName);

            $databaseName = normalizeEmployeeName($databaseEmployeeName);

            if ($excelName !== $databaseName) {
                $errors[] = 'Employee name does not match the employee number.';
            }
        }

        /*
         * Nature of service credit validation.
         */

        $creditTypeId = null;

        if ($natureOfServiceCredits !== '') {

            if (
                $natureOfServiceCredits !== 'LOCAL' &&
                $natureOfServiceCredits !== 'NATIONAL'
            ) {

                $errors[] = 'Nature of service credit must be LOCAL or NATIONAL.';

            } else {

                $creditTypeKey = strtolower($natureOfServiceCredits);

                if (isset($creditTypes[$creditTypeKey])) {

                    $creditTypeId = (int) $creditTypes[$creditTypeKey]['credit_type_id'];

                } else {

                    $errors[] = 'The service credit type does not exist in the system.';
                }
            }
        }

        /*
         * Determine preview status.
         */

        $status = empty($errors) ? 'valid' : 'invalid';

        if ($status === 'valid') {
            $validCount++;
        } else {
            $invalidCount++;
        }

        /*
         * Add row to preview data.
         */

        $rows[] = [
            'excel_row' => $rowNumber,

            'employee_number' => $employeeNumber,
            'employee_name' => $employeeName,

            'employee_id' => $employeeId,
            'database_employee_name' => $databaseEmployeeName,

            'name_of_activity' => $nameOfActivity,

            'activity_start_date' => $activityStartDate,
            'activity_end_date' => $activityEndDate,

            'number_of_service_credits' => $numberOfServiceCredits,

            'nature_of_service_credits' => $natureOfServiceCredits,

            'credit_type_id' => $creditTypeId,

            'status' => $status,
            'errors' => $errors
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | RETURN PREVIEW DATA
    |--------------------------------------------------------------------------
    */

    respond([
        'success' => true,

        'file_name' => $file['name'],

        'total_records' => count($rows),

        'valid_count' => $validCount,

        'invalid_count' => $invalidCount,

        'rows' => $rows
    ]);

} catch (Throwable $e) {

    error_log('Service Credits Upload API Error: ' . $e->getMessage());

    respond([
        'success' => false,
        'message' => 'An error occurred while processing the Service Credit Excel file.'
    ], 500);
}

?>
