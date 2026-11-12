<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database.php';
require_once dirname(__DIR__, 2) . '/session.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function send_tx_json(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    send_tx_json(405, ['success' => false, 'message' => 'Method not allowed.']);
}

start_app_session();

if (!is_logged_in()) {
    send_tx_json(401, ['success' => false, 'message' => 'Please log in to continue.']);
}

if ((int) $_SESSION['role_id'] !== ADMIN_ROLE_ID) {
    send_tx_json(403, ['success' => false, 'message' => 'Administrator access is required.']);
}

try {
    $pdo = db();

    $stmt = $pdo->query("
        SELECT
            lr.leave_request_id,
            lr.request_number,
            lr.date_filed,
            lr.start_datetime,
            lr.end_datetime,
            lr.total_leave_days,
            lr.reason_details,
            lr.status,
            lr.credit_type_id,
            lr.reviewed_by,
            lr.reviewed_at,

            CONCAT(
                reviewer_emp.first_name, ' ',
                COALESCE(CONCAT(reviewer_emp.middle_name, ' '), ''),
                reviewer_emp.last_name
            ) AS reviewer_name,

            COALESCE((
                SELECT SUM(
                    CASE
                        WHEN sct2.transaction_type = 'EARNED'   THEN  sct2.credit_amount
                        WHEN sct2.transaction_type = 'DEDUCTED' THEN -sct2.credit_amount
                        ELSE 0
                    END
                )
                FROM service_credit_transactions sct2
                WHERE sct2.employee_id = lr.employee_id
                  AND sct2.credit_type_id = 1
            ), 0) AS local_balance,

            COALESCE((
                SELECT SUM(
                    CASE
                        WHEN sct2.transaction_type = 'EARNED'   THEN  sct2.credit_amount
                        WHEN sct2.transaction_type = 'DEDUCTED' THEN -sct2.credit_amount
                        ELSE 0
                    END
                )
                FROM service_credit_transactions sct2
                WHERE sct2.employee_id = lr.employee_id
                  AND sct2.credit_type_id = 2
            ), 0) AS national_balance,

            e.employee_id,
            e.employee_number,
            e.first_name,
            e.middle_name,
            e.last_name,
            e.position,

            lt.leave_type_name,
            sct.credit_type_name

        FROM leave_requests lr
        INNER JOIN employees e
            ON e.employee_id = lr.employee_id
        INNER JOIN leave_types lt
            ON lt.leave_type_id = lr.leave_type_id
        LEFT JOIN service_credit_types sct
            ON sct.credit_type_id = lr.credit_type_id
        LEFT JOIN accounts AS reviewer_account
            ON reviewer_account.account_id = lr.reviewed_by
        LEFT JOIN employees AS reviewer_emp
            ON reviewer_emp.employee_id = reviewer_account.employee_id

        ORDER BY
            CASE WHEN lr.status = 'Pending' THEN 0 ELSE 1 END,
            lr.date_filed DESC,
            lr.leave_request_id DESC
    ");

    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    send_tx_json(200, [
        'success' => true,
        'data'    => [
            'admin'    => [
                'username'  => $_SESSION['username'],
                'role_name' => $_SESSION['role_name'],
            ],
            'requests' => $requests,
        ]
    ]);

} catch (Throwable $e) {
    error_log('PERSYS transactions API error: ' . $e->getMessage());
    send_tx_json(500, ['success' => false, 'message' => 'Unable to load transactions.']);
}
