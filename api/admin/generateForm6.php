<?php
declare(strict_types=1);

// ── Autoloader (backend vendor — has FPDI + FPDF) ──────────────
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/database.php';
require_once dirname(__DIR__, 2) . '/session.php';

use setasign\Fpdi\Fpdi;

start_app_session();

// Only admins may generate Form 6.
if (!is_logged_in() || (int) $_SESSION['role_id'] !== ADMIN_ROLE_ID) {
    http_response_code(403);
    exit('Access denied.');
}

$pdo = db();

// ── Validate request_id ─────────────────────────────────────────
$requestId = filter_input(INPUT_GET, 'request_id', FILTER_VALIDATE_INT);

if (!$requestId || $requestId < 1) {
    http_response_code(400);
    exit('Invalid leave request ID.');
}

// ── Fetch leave request + employee information ──────────────────
$stmt = $pdo->prepare("
    SELECT
        lr.leave_request_id,
        lr.request_number,
        lr.date_filed,
        lr.reason_details,
        lr.start_datetime,
        lr.end_datetime,
        lr.total_leave_days,
        lr.status,
        lt.leave_type_name,
        e.last_name,
        e.first_name,
        e.middle_name,
        e.position
    FROM leave_requests AS lr
    INNER JOIN employees AS e
        ON e.employee_id = lr.employee_id
    INNER JOIN leave_types AS lt
        ON lt.leave_type_id = lr.leave_type_id
    WHERE lr.leave_request_id = :request_id
    LIMIT 1
");
$stmt->execute(['request_id' => $requestId]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    http_response_code(404);
    exit('Leave request not found.');
}

// Form 6 is only available for Pending and Approved requests.
if ($request['status'] === 'Rejected') {
    http_response_code(403);
    exit('Form 6 is unavailable for rejected leave requests.');
}

// ── Load PDF template ───────────────────────────────────────────
$templatePath = dirname(__DIR__, 2) . '/Documents/Form6_Clean_Template.pdf';

if (!file_exists($templatePath)) {
    http_response_code(500);
    exit('Form 6 template not found on the server.');
}

// ── Build the populated PDF ─────────────────────────────────────
$pdf = new Fpdi();
$pdf->SetAutoPageBreak(false);
$pdf->SetMargins(0, 0, 0);
$pdf->setSourceFile($templatePath);
$templateId = $pdf->importPage(1);
$pageSize   = $pdf->getTemplateSize($templateId);

$pdf->AddPage(
    $pageSize['orientation'],
    [$pageSize['width'], $pageSize['height']]
);

$pdf->useTemplate($templateId);
$pdf->SetTextColor(0, 0, 0);

// Office / Department
$pdf->SetFont('Arial', '', 10);
$pdf->SetXY(25, 47);
$pdf->Write(5, 'BCSHS-SAC');

// Employee name (Last | First | Middle)
$pdf->SetXY(93, 47);
$pdf->Write(5, strtoupper($request['last_name']));

$pdf->SetXY(130, 47);
$pdf->Write(5, strtoupper($request['first_name']));

$pdf->SetXY(165, 47);
$pdf->Write(5, strtoupper($request['middle_name'] ?? ''));

// Date of Filing
$pdf->SetXY(43, 60);
$pdf->Write(5, date('m/d/Y', strtotime($request['date_filed'])));

// Position
$pdf->SetXY(105, 59);
$pdf->Write(5, strtoupper($request['position']));

// Leave type checkbox
$leaveType = strtolower(trim($request['leave_type_name']));

$pdf->SetFont('Arial', 'B', 12);

$checkboxPositions = [
    'vacation leave'                      => [15.5, 78],
    'mandatory/forced leave'              => [15.5, 83],
    'sick leave'                          => [15.5, 88],
    'maternity leave'                     => [15.5, 94],
    'paternity leave'                     => [15.5, 99],
    'special privilege leave'             => [15.5, 104],
    'solo parent leave'                   => [15.5, 109],
    'study leave'                         => [15.5, 114],
    '10-day vawc leave'                   => [15.5, 120],
    'rehabilitation privilege leave'      => [15.5, 125],
    'special leave benefits for women'    => [15.5, 130],
    'special emergency (calamity) leave'  => [15.5, 135],
    'adoption leave'                      => [15.5, 140],
];

if (isset($checkboxPositions[$leaveType])) {
    [$cx, $cy] = $checkboxPositions[$leaveType];
    $pdf->SetXY($cx, $cy);
    $pdf->Write(5, 'X');
}

// "Others" line — only when leave type is Others
if ($leaveType === 'others') {
    $otherDescription = trim($request['reason_details'] ?? '');
    if ($otherDescription !== '' && strtolower($otherDescription) !== 'n/a') {
        $pdf->SetFont('Arial', '', 9);
        $pdf->SetXY(20, 148);
        $pdf->Write(5, $otherDescription);
    }
}

// Total Working Days
$pdf->SetFont('Arial', '', 10);
$pdf->SetXY(43, 160);
$pdf->Write(5, rtrim(
    rtrim(number_format((float) $request['total_leave_days'], 2, '.', ''), '0'),
    '.'
));

// Inclusive Dates
$startDate      = date('F j, Y', strtotime($request['start_datetime']));
$endDate        = date('F j, Y', strtotime($request['end_datetime']));
$inclusiveDates = $startDate === $endDate
    ? $startDate
    : $startDate . ' - ' . $endDate;

$pdf->SetXY(40, 169);
$pdf->Write(5, $inclusiveDates);

// ── Stream PDF inline ───────────────────────────────────────────
$safeNumber = preg_replace('/[^A-Za-z0-9_-]/', '_', $request['request_number']);
$filename   = 'Form6_' . $safeNumber . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');

$pdf->Output('I', $filename);
exit;
