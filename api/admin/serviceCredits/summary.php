<?php

require_once dirname(__DIR__, 3) . '/database.php';
require_once dirname(__DIR__, 3) . '/session.php';

header('Content-Type: application/json; charset=utf-8');

start_app_session();

/*
|--------------------------------------------------------------------------
| 1. AUTHENTICATION AND AUTHORIZATION
|--------------------------------------------------------------------------
*/

if (!is_logged_in()) {
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized. Please log in.'
    ]);

    exit;
}

if ((int) $_SESSION['role_id'] !== ADMIN_ROLE_ID) {
    http_response_code(403);

    echo json_encode([
        'success' => false,
        'message' => 'Access denied. Admin privileges are required.'
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| 2. REQUEST METHOD
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| 3. GET DATABASE CONNECTION
|--------------------------------------------------------------------------
*/

try {

    $pdo = db();

    $employeeId = (int) $_SESSION['employee_id'];


    /*
    |--------------------------------------------------------------------------
    | 4. GET LOCAL SERVICE CREDIT BALANCE
    |--------------------------------------------------------------------------
    */

    $localCreditsStatement = $pdo->prepare("
        SELECT COALESCE(
            SUM(
                CASE
                    WHEN transaction_type = 'EARNED'
                        THEN credit_amount
                    WHEN transaction_type = 'DEDUCTED'
                        THEN -credit_amount
                    ELSE 0
                END
            ), 0
        ) AS total_local_credits
        FROM service_credit_transactions
        WHERE employee_id = ?
          AND credit_type_id = 1
    ");

    $localCreditsStatement->execute([
        $employeeId
    ]);

    $localResult = $localCreditsStatement->fetch(PDO::FETCH_ASSOC);

    $totalLocalCredits = (float) $localResult['total_local_credits'];


    /*
    |--------------------------------------------------------------------------
    | 5. GET NATIONAL SERVICE CREDIT BALANCE
    |--------------------------------------------------------------------------
    */

    $nationalCreditsStatement = $pdo->prepare("
        SELECT COALESCE(
            SUM(
                CASE
                    WHEN transaction_type = 'EARNED'
                        THEN credit_amount
                    WHEN transaction_type = 'DEDUCTED'
                        THEN -credit_amount
                    ELSE 0
                END
            ), 0
        ) AS total_national_credits
        FROM service_credit_transactions
        WHERE employee_id = ?
          AND credit_type_id = 2
    ");

    $nationalCreditsStatement->execute([
        $employeeId
    ]);

    $nationalResult = $nationalCreditsStatement->fetch(PDO::FETCH_ASSOC);

    $totalNationalCredits = (float) $nationalResult['total_national_credits'];


    /*
    |--------------------------------------------------------------------------
    | 6. GET TOTAL SERVICE CREDIT UPLOAD RECORDS
    |--------------------------------------------------------------------------
    */

    $uploadServiceStatement = $pdo->query("
        SELECT COUNT(*) AS total_upload_records
        FROM service_credit_transactions
    ");

    $uploadService = $uploadServiceStatement->fetch(PDO::FETCH_ASSOC);


    /*
    |--------------------------------------------------------------------------
    | 7. GET SERVICE CREDIT UPLOAD HISTORY
    |--------------------------------------------------------------------------
    */

    $historyStatement = $pdo->query("
        SELECT
            b.upload_batch_id,
            b.file_name,
            b.uploaded_at,
            b.status,
            b.posted_at,

            CONCAT(
                e.first_name,
                ' ',
                e.last_name
            ) AS uploaded_by_name,

            COUNT(t.transaction_id) AS record_count

        FROM credit_upload_batches b

        INNER JOIN accounts a
            ON a.account_id = b.uploaded_by

        INNER JOIN employees e
            ON e.employee_id = a.employee_id

        LEFT JOIN service_credit_transactions t
            ON t.upload_batch_id = b.upload_batch_id

        GROUP BY
            b.upload_batch_id,
            b.file_name,
            b.uploaded_at,
            b.status,
            b.posted_at,
            e.first_name,
            e.last_name

        ORDER BY
            b.uploaded_at DESC
    ");

    $uploadHistory = $historyStatement->fetchAll(PDO::FETCH_ASSOC);


    /*
    |--------------------------------------------------------------------------
    | 8. RETURN JSON RESPONSE
    |--------------------------------------------------------------------------
    */

    echo json_encode([
        'success' => true,
        'data' => [
            'admin' => [
                'username'  => $_SESSION['username'],
                'role_name' => $_SESSION['role_name'],
            ],
            'total_local_credits' => $totalLocalCredits,
            'total_national_credits' => $totalNationalCredits,
            'total_upload_records' => (int) $uploadService['total_upload_records'],
            'upload_history' => $uploadHistory
        ]
    ]);

} catch (Throwable $e) {

    error_log('Service Credits Summary API Error: ' . $e->getMessage());

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while retrieving service credit information.'
    ]);

}

?>