<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database.php';
require_once dirname(__DIR__, 2) . '/session.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function send_user_sc_json(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');

    send_user_sc_json(405, [
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
}

start_app_session();

try {
    // Verify the session and current account status in MySQL.
    if (!is_logged_in()) {
        send_user_sc_json(401, [
            'success' => false,
            'message' => 'Please log in to continue.'
        ]);
    }

    // Only Teaching (2) and Non-Teaching (3) users can access this endpoint.
    $roleId = (int) $_SESSION['role_id'];

    if ($roleId !== USER_TEACHING_ROLE_ID && $roleId !== USER_NONTEACHING_ROLE_ID) {
        send_user_sc_json(403, [
            'success' => false,
            'message' => 'Access restricted to personnel accounts.'
        ]);
    }

    $pdo   = db();
    $empId = (int) $_SESSION['employee_id'];

    // --- Local service credit balance (EARNED - DEDUCTED) ---
    $stmtLocal = $pdo->prepare("
        SELECT COALESCE(SUM(
            CASE
                WHEN transaction_type = 'EARNED'   THEN  credit_amount
                WHEN transaction_type = 'DEDUCTED' THEN -credit_amount
                ELSE 0
            END
        ), 0) AS total_local_credits
        FROM service_credit_transactions
        WHERE employee_id = ?
          AND credit_type_id = 1
    ");
    $stmtLocal->execute([$empId]);
    $totalLocalCredits = (float) $stmtLocal->fetchColumn();

    // --- National service credit balance (EARNED - DEDUCTED) ---
    $stmtNational = $pdo->prepare("
        SELECT COALESCE(SUM(
            CASE
                WHEN transaction_type = 'EARNED'   THEN  credit_amount
                WHEN transaction_type = 'DEDUCTED' THEN -credit_amount
                ELSE 0
            END
        ), 0) AS total_national_credits
        FROM service_credit_transactions
        WHERE employee_id = ?
          AND credit_type_id = 2
    ");
    $stmtNational->execute([$empId]);
    $totalNationalCredits = (float) $stmtNational->fetchColumn();

    // --- All transactions for this employee (newest first) ---
    $stmtTx = $pdo->prepare("
        SELECT
            sct.transaction_id,
            sct.activity_name,
            sct.activity_start_date,
            sct.activity_end_date,
            sct.credit_amount,
            sct.transaction_type,
            sct.created_at,
            sty.credit_type_name
        FROM service_credit_transactions sct
        INNER JOIN service_credit_types sty
            ON sty.credit_type_id = sct.credit_type_id
        WHERE sct.employee_id = ?
        ORDER BY sct.created_at DESC
    ");
    $stmtTx->execute([$empId]);
    $transactions = $stmtTx->fetchAll(PDO::FETCH_ASSOC);

    send_user_sc_json(200, [
        'success' => true,
        'data' => [
            'user' => [
                'username'  => $_SESSION['username'],
                'role_name' => $_SESSION['role_name'],
            ],
            'total_local_credits'    => $totalLocalCredits,
            'total_national_credits' => $totalNationalCredits,
            'transactions'           => $transactions,
        ]
    ]);

} catch (Throwable $e) {
    error_log('PERSYS user service credits API error: ' . $e->getMessage());

    send_user_sc_json(500, [
        'success' => false,
        'message' => 'Unable to load service credits data.'
    ]);
}
