<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database.php';
require_once dirname(__DIR__, 2) . '/session.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function send_process_json(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    send_process_json(405, ['success' => false, 'message' => 'POST requests only.']);
}

start_app_session();

if (!is_logged_in()) {
    send_process_json(401, ['success' => false, 'message' => 'Please log in to continue.']);
}

if ((int) $_SESSION['role_id'] !== ADMIN_ROLE_ID) {
    send_process_json(403, ['success' => false, 'message' => 'Administrator access is required.']);
}

// Accept both JSON and form-encoded bodies
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$requestId    = isset($input['request_id'])
    ? filter_var($input['request_id'], FILTER_VALIDATE_INT)
    : false;
$targetStatus = trim((string) ($input['target_status'] ?? ''));
$adminId      = (int) ($_SESSION['account_id'] ?? 0);

if (!$adminId || !$requestId || $requestId < 1) {
    send_process_json(400, ['success' => false, 'message' => 'Invalid request or admin session.']);
}

if (!in_array($targetStatus, ['Approved', 'Rejected'], true)) {
    send_process_json(400, ['success' => false, 'message' => 'Choose Approved or Rejected.']);
}

$creditTypeId = null;

if ($targetStatus === 'Approved') {
    $creditTypeId = isset($input['credit_type_id'])
        ? filter_var($input['credit_type_id'], FILTER_VALIDATE_INT)
        : false;

    if (!in_array($creditTypeId, [1, 2], true)) {
        send_process_json(400, [
            'success' => false,
            'message' => 'Select Local (1) or National (2) Service Credits.'
        ]);
    }
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    // Lock the leave request to prevent concurrent finalization.
    $stmt = $pdo->prepare("
        SELECT
            lr.*,
            e.employee_id,
            lt.leave_type_name
        FROM leave_requests lr
        INNER JOIN employees e  ON e.employee_id  = lr.employee_id
        INNER JOIN leave_types lt ON lt.leave_type_id = lr.leave_type_id
        WHERE lr.leave_request_id = ?
        FOR UPDATE
    ");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        $pdo->rollBack();
        send_process_json(404, ['success' => false, 'message' => 'Leave request not found.']);
    }

    if ($request['status'] !== 'Pending') {
        $pdo->rollBack();
        send_process_json(409, [
            'success' => false,
            'message' => 'Only Pending requests can be finalized.'
        ]);
    }

    // ── REJECTION ────────────────────────────────────────────
    if ($targetStatus === 'Rejected') {
        $stmt = $pdo->prepare("
            UPDATE leave_requests
            SET status      = 'Rejected',
                reviewed_by = ?,
                reviewed_at = NOW()
            WHERE leave_request_id = ?
              AND status = 'Pending'
        ");
        $stmt->execute([$adminId, $requestId]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('The request could not be marked Rejected.');
        }

        $pdo->commit();
        send_process_json(200, [
            'success' => true,
            'status'  => 'Rejected',
            'message' => 'Leave request rejected. No service credits were deducted.'
        ]);
    }

    // ── APPROVAL ─────────────────────────────────────────────

    // Lock the employee row to serialize credit-balance changes.
    $stmt = $pdo->prepare(
        "SELECT employee_id FROM employees WHERE employee_id = ? FOR UPDATE"
    );
    $stmt->execute([$request['employee_id']]);

    if (!$stmt->fetch()) {
        throw new RuntimeException('Employee record not found.');
    }

    // Calculate current balance for the chosen pool.
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(
            CASE
                WHEN transaction_type = 'EARNED'   THEN  credit_amount
                WHEN transaction_type = 'DEDUCTED' THEN -credit_amount
                ELSE 0
            END
        ), 0) AS balance
        FROM service_credit_transactions
        WHERE employee_id    = ?
          AND credit_type_id = ?
    ");
    $stmt->execute([$request['employee_id'], $creditTypeId]);
    $balance   = (float) $stmt->fetchColumn();
    $leaveDays = (float) $request['total_leave_days'];

    if ($leaveDays <= 0) {
        throw new RuntimeException('The request has an invalid number of leave days.');
    }

    if ($balance < $leaveDays) {
        $pdo->rollBack();
        send_process_json(422, [
            'success' => false,
            'message' => sprintf(
                'Insufficient service credits. Available: %.2f; required: %.2f.',
                $balance,
                $leaveDays
            )
        ]);
    }

    // Update the leave request to Approved.
    $stmt = $pdo->prepare("
        UPDATE leave_requests
        SET status         = 'Approved',
            credit_type_id = ?,
            reviewed_by    = ?,
            reviewed_at    = NOW()
        WHERE leave_request_id = ?
          AND status = 'Pending'
    ");
    $stmt->execute([$creditTypeId, $adminId, $requestId]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('The request could not be marked Approved.');
    }

    // Record exactly one DEDUCTED transaction.
    $stmt = $pdo->prepare("
        INSERT INTO service_credit_transactions (
            employee_id,
            credit_type_id,
            upload_batch_id,
            leave_request_id,
            activity_name,
            activity_start_date,
            activity_end_date,
            credit_amount,
            transaction_type,
            created_by
        ) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, 'DEDUCTED', ?)
    ");
    $stmt->execute([
        $request['employee_id'],
        $creditTypeId,
        $requestId,
        'Leave deduction - ' . $request['request_number'],
        date('Y-m-d', strtotime($request['start_datetime'])),
        date('Y-m-d', strtotime($request['end_datetime'])),
        number_format($leaveDays, 2, '.', ''),
        $adminId,
    ]);

    $pdo->commit();

    send_process_json(200, [
        'success'           => true,
        'status'            => 'Approved',
        'balance'           => $balance,
        'deduction'         => $leaveDays,
        'remaining_balance' => $balance - $leaveDays,
        'message'           => 'Leave request approved and service credits deducted successfully.'
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('PERSYS processTransaction error: ' . $e->getMessage());
    send_process_json(500, [
        'success' => false,
        'message' => 'The status could not be updated. Please try again.'
    ]);
}
