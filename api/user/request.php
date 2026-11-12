<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database.php';
require_once dirname(__DIR__, 2) . '/session.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function send_request_json(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

start_app_session();

// ── Auth guard ──────────────────────────────────────────────
if (!is_logged_in()) {
    send_request_json(401, [
        'success' => false,
        'message' => 'Please log in to continue.'
    ]);
}

$roleId = (int) $_SESSION['role_id'];

if ($roleId !== USER_TEACHING_ROLE_ID && $roleId !== USER_NONTEACHING_ROLE_ID) {
    send_request_json(403, [
        'success' => false,
        'message' => 'Access restricted to personnel accounts.'
    ]);
}

$empId = (int) $_SESSION['employee_id'];

try {
    $pdo = db();

    // ── GET — fetch leave types + all requests for this employee ──
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {

        // Active leave types for the form dropdown
        $stmtLT = $pdo->prepare(
            "SELECT leave_type_id, leave_type_name
             FROM leave_types
             WHERE is_active = 1
             ORDER BY leave_type_id ASC"
        );
        $stmtLT->execute();
        $leaveTypes = $stmtLT->fetchAll(PDO::FETCH_ASSOC);

        // All leave requests for this employee
        $stmtReqs = $pdo->prepare(
            "SELECT
                lr.leave_request_id,
                lr.request_number,
                lr.date_filed,
                lr.start_datetime,
                lr.end_datetime,
                lr.total_leave_days,
                lr.status,
                lt.leave_type_name
             FROM leave_requests lr
             INNER JOIN leave_types lt
                 ON lt.leave_type_id = lr.leave_type_id
             WHERE lr.employee_id = ?
             ORDER BY lr.created_at DESC"
        );
        $stmtReqs->execute([$empId]);
        $requests = $stmtReqs->fetchAll(PDO::FETCH_ASSOC);

        send_request_json(200, [
            'success' => true,
            'data'    => [
                'user' => [
                    'username'  => $_SESSION['username'],
                    'role_name' => $_SESSION['role_name'],
                ],
                'leave_types' => $leaveTypes,
                'requests'    => $requests,
            ]
        ]);
    }

    // ── POST — submit a new leave request ──────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        $input = json_decode(file_get_contents('php://input'), true);

        // Fall back to form-encoded body if JSON is not provided
        if (!is_array($input)) {
            $input = $_POST;
        }

        $leaveTypeId   = isset($input['leave_type_id'])
            ? filter_var($input['leave_type_id'], FILTER_VALIDATE_INT)
            : false;
        $startDate     = trim((string) ($input['start_date']       ?? ''));
        $endDate       = trim((string) ($input['end_date']         ?? ''));
        $totalDays     = isset($input['total_leave_days'])
            ? filter_var($input['total_leave_days'], FILTER_VALIDATE_FLOAT)
            : false;
        $reasonDetails = trim((string) ($input['reason_details']   ?? ''));

        if (!$leaveTypeId || $startDate === '' || $endDate === ''
                || $totalDays === false || $totalDays <= 0
                || $reasonDetails === '') {
            send_request_json(422, [
                'success' => false,
                'message' => 'All fields are required and total days must be at least 1.'
            ]);
        }

        // Validate that the chosen leave type is active
        $stmtCheck = $pdo->prepare(
            "SELECT COUNT(*) FROM leave_types
             WHERE leave_type_id = ? AND is_active = 1"
        );
        $stmtCheck->execute([$leaveTypeId]);

        if ((int) $stmtCheck->fetchColumn() === 0) {
            send_request_json(422, [
                'success' => false,
                'message' => 'The selected leave type is not available.'
            ]);
        }

        // Generate request number: LR-YYYY-NNNN
        $year    = date('Y');
        $stmtMax = $pdo->prepare(
            "SELECT MAX(CAST(SUBSTRING_INDEX(request_number, '-', -1) AS UNSIGNED))
             FROM leave_requests
             WHERE request_number LIKE ?"
        );
        $stmtMax->execute(['LR-' . $year . '-%']);
        $maxSeq = (int) $stmtMax->fetchColumn();
        $reqNum = 'LR-' . $year . '-' . str_pad((string)($maxSeq + 1), 4, '0', STR_PAD_LEFT);

        $stmtIns = $pdo->prepare(
            "INSERT INTO leave_requests
               (request_number, employee_id, leave_type_id, date_filed,
                start_datetime, end_datetime, total_leave_days, reason_details, status)
             VALUES (?, ?, ?, CURDATE(), ?, ?, ?, ?, 'Pending')"
        );

        try {
            $stmtIns->execute([
                $reqNum,
                $empId,
                $leaveTypeId,
                $startDate . ' 08:00:00',
                $endDate   . ' 17:00:00',
                $totalDays,
                $reasonDetails,
            ]);
        } catch (PDOException $e) {
            // UNIQUE constraint: request_number race condition
            if ($e->getCode() === '23000') {
                send_request_json(409, [
                    'success' => false,
                    'message' => 'A submission conflict occurred. Please try again.'
                ]);
            }
            throw $e;
        }

        send_request_json(201, [
            'success'        => true,
            'message'        => 'Leave request submitted successfully.',
            'request_number' => $reqNum,
            'status'         => 'Pending',
        ]);
    }

    // Any other method
    header('Allow: GET, POST');
    send_request_json(405, [
        'success' => false,
        'message' => 'Method not allowed.'
    ]);

} catch (Throwable $e) {
    error_log('PERSYS user request API error: ' . $e->getMessage());

    send_request_json(500, [
        'success' => false,
        'message' => 'A server error occurred. Please try again later.'
    ]);
}
