<?php
require_once dirname(__DIR__, 2) . '/session.php';
require_once dirname(__DIR__, 2) . '/database.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

start_app_session();
require_admin();

header('Content-Type: application/json');

function respond(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}


/*
|--------------------------------------------------------------------------
| Only POST is allowed
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
| Check uploaded file
|--------------------------------------------------------------------------
*/

if (!isset($_FILES['masterfile'])) {
    respond([
        'success' => false,
        'message' => 'Please choose an Excel file.'
    ], 400);
}

$file = $_FILES['masterfile'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    respond([
        'success' => false,
        'message' => 'The Excel file could not be uploaded.'
    ], 400);
}


/*
|--------------------------------------------------------------------------
| Check file extension
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
| Read Excel file
|--------------------------------------------------------------------------
*/

try {

    $spreadsheet = IOFactory::load($file['tmp_name']);

} catch (Throwable $e) {

    respond([
        'success' => false,
        'message' => 'The Excel file could not be read.'
    ], 400);
}


$worksheet = $spreadsheet->getActiveSheet();

$highestRow = $worksheet->getHighestRow();


/*
|--------------------------------------------------------------------------
| Expected Excel headers
|--------------------------------------------------------------------------
*/

$expectedHeaders = [
    'EMPLOYEE NUMBER',
    'LAST NAME',
    'FIRST NAME',
    'MIDDLE NAME',
    'PLANTILLA ITEM NUMBER',
    'POSITION',
    'SALARY GRADE',
    'STEP INCREMENT',
    'DATE OF ORIGINAL APPOINTMENT',
    'DATE OF LAST PROMOTION',
    'DEPED EMAIL',
    'PERSONNEL TYPE'
];


/*
|--------------------------------------------------------------------------
| Read and check headers
|--------------------------------------------------------------------------
*/

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
        'message' => 'The Excel headers do not match the required Employee Master File format.',
        'expected_headers' => $expectedHeaders,
        'received_headers' => $headers
    ], 400);
}


/*
|--------------------------------------------------------------------------
| Helper functions
|--------------------------------------------------------------------------
*/

function cleanValue($value): string
{
    return trim((string) $value);
}


function parseExcelDate($value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    /*
     * Excel stores dates internally as numbers.
     */
    if (is_numeric($value)) {

        try {

            return Date::excelToDateTimeObject($value)
                ->format('Y-m-d');

        } catch (Throwable $e) {

            return null;
        }
    }


    /*
     * Try common date formats.
     */

    $formats = [
        'm/d/Y',
        'm/d/y',
        'Y-m-d',
        'n/j/Y',
        'n/j/y'
    ];

    foreach ($formats as $format) {

        $date = DateTime::createFromFormat(
            $format,
            trim((string) $value)
        );

        if ($date !== false) {

            return $date->format('Y-m-d');
        }
    }

    return null;
}


/*
|--------------------------------------------------------------------------
| Read database records for duplicate checking
|--------------------------------------------------------------------------
*/

$pdo = db();

$existingEmployees = $pdo
    ->query("
        SELECT employee_number, deped_email
        FROM employees
    ")
    ->fetchAll();

$existingEmployeeNumbers = [];
$existingEmails = [];

foreach ($existingEmployees as $employee) {

    $existingEmployeeNumbers[] =
        strtolower(trim($employee['employee_number']));

    $existingEmails[] =
        strtolower(trim($employee['deped_email']));
}


/*
|--------------------------------------------------------------------------
| Process Excel rows
|--------------------------------------------------------------------------
*/

$rows = [];

$validCount = 0;
$invalidCount = 0;
$duplicateCount = 0;


/*
 * Keep track of duplicates inside
 * the uploaded Excel file itself.
 */

$fileEmployeeNumbers = [];
$fileEmails = [];


for ($rowNumber = 2; $rowNumber <= $highestRow; $rowNumber++) {

    /*
     * Read values
     */

        $employeeNumber = cleanValue(
            $worksheet->getCell([1, $rowNumber])->getFormattedValue()
        );

        $lastName = cleanValue(
            $worksheet->getCell([2, $rowNumber])->getFormattedValue()
        );

        $firstName = cleanValue(
            $worksheet->getCell([3, $rowNumber])->getFormattedValue()
        );

        $middleName = cleanValue(
            $worksheet->getCell([4, $rowNumber])->getFormattedValue()
        );

        $plantillaItemNumber = cleanValue(
            $worksheet->getCell([5, $rowNumber])->getFormattedValue()
        );

        $position = cleanValue(
            $worksheet->getCell([6, $rowNumber])->getFormattedValue()
        );

        $salaryGrade = cleanValue(
            $worksheet->getCell([7, $rowNumber])->getFormattedValue()
        );

        $stepIncrement = cleanValue(
            $worksheet->getCell([8, $rowNumber])->getFormattedValue()
        );

        $originalAppointmentRaw =
            $worksheet->getCell([9, $rowNumber])->getValue();

        $lastPromotionRaw =
            $worksheet->getCell([10, $rowNumber])->getValue();

        $depedEmail = cleanValue(
            $worksheet->getCell([11, $rowNumber])->getFormattedValue()
        );

        $personnelType = cleanValue(
            $worksheet->getCell([12, $rowNumber])->getFormattedValue()
        );

    /*
     * Skip completely empty rows.
     */

    $allValues = [
        $employeeNumber,
        $lastName,
        $firstName,
        $middleName,
        $plantillaItemNumber,
        $position,
        $salaryGrade,
        $stepIncrement,
        $originalAppointmentRaw,
        $lastPromotionRaw,
        $depedEmail,
        $personnelType
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
     * Convert dates.
     */

    $dateOriginalAppointment =
        parseExcelDate($originalAppointmentRaw);

    $dateLastPromotion =
        parseExcelDate($lastPromotionRaw);


    /*
     * Validation errors.
     */

    $errors = [];


    /*
     * Required fields.
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

    if ($dateOriginalAppointment === null) {
        $errors[] = 'Original appointment date is invalid or missing.';
    }

    if ($depedEmail === '') {
        $errors[] = 'DepEd email is required.';
    }


    /*
     * Salary grade validation.
     */

    if (
        $salaryGrade !== '' &&
        !filter_var($salaryGrade, FILTER_VALIDATE_INT)
    ) {
        $errors[] = 'Salary grade must be a whole number.';
    }


    /*
     * Step increment validation.
     */

    if (
        $stepIncrement !== '' &&
        !filter_var($stepIncrement, FILTER_VALIDATE_INT)
    ) {
        $errors[] = 'Step increment must be a whole number.';
    }


    /*
     * Email validation.
     */

    if (
        $depedEmail !== '' &&
        !filter_var($depedEmail, FILTER_VALIDATE_EMAIL)
    ) {
        $errors[] = 'Invalid DepEd email address.';
    }


    /*
     * Personnel type validation.
     */

    if (
        $personnelType !== 'Teaching' &&
        $personnelType !== 'Non-Teaching'
    ) {
        $errors[] =
            'Personnel type must be Teaching or Non-Teaching.';
    }


    /*
     * Check duplicates against database.
     */

    $employeeNumberKey =
        strtolower($employeeNumber);

    $emailKey =
        strtolower($depedEmail);


    if (
        $employeeNumber !== '' &&
        in_array(
            $employeeNumberKey,
            $existingEmployeeNumbers,
            true
        )
    ) {

        $errors[] =
            'Employee number already exists in the database.';

        $duplicateCount++;
    }


    if (
        $depedEmail !== '' &&
        in_array(
            $emailKey,
            $existingEmails,
            true
        )
    ) {

        $errors[] =
            'DepEd email already exists in the database.';

        $duplicateCount++;
    }


    /*
     * Check duplicates inside uploaded Excel.
     */

    if (
        $employeeNumber !== '' &&
        in_array(
            $employeeNumberKey,
            $fileEmployeeNumbers,
            true
        )
    ) {

        $errors[] =
            'Duplicate employee number in this Excel file.';

        $duplicateCount++;
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
            'Duplicate DepEd email in this Excel file.';

        $duplicateCount++;
    }


    /*
     * Remember current row for future duplicate checking.
     */

    if ($employeeNumber !== '') {
        $fileEmployeeNumbers[] = $employeeNumberKey;
    }

    if ($depedEmail !== '') {
        $fileEmails[] = $emailKey;
    }


    /*
     * Determine row status.
     */

    $status = empty($errors)
        ? 'valid'
        : 'invalid';


    if ($status === 'valid') {
        $validCount++;
    } else {
        $invalidCount++;
    }


    /*
     * Add row to preview.
     */

    $rows[] = [

        'excel_row' => $rowNumber,

        'employee_number' => $employeeNumber,
        'last_name' => $lastName,
        'first_name' => $firstName,
        'middle_name' => $middleName,
        'plantilla_item_number' => $plantillaItemNumber,
        'position' => $position,
        'salary_grade' => $salaryGrade,
        'step_increment' => $stepIncrement,

        'date_original_appointment' =>
            $dateOriginalAppointment,

        'date_last_promotion' =>
            $dateLastPromotion,

        'deped_email' => $depedEmail,
        'personnel_type' => $personnelType,

        'status' => $status,
        'errors' => $errors
    ];
}


/*
|--------------------------------------------------------------------------
| Return preview data
|--------------------------------------------------------------------------
*/

respond([

    'success' => true,

    'file_name' => $file['name'],

    'total_records' => count($rows),

    'valid_count' => $validCount,

    'invalid_count' => $invalidCount,

    'duplicate_count' => $duplicateCount,

    'rows' => $rows

]);