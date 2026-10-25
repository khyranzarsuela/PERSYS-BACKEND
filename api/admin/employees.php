<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/session.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function send_employees_json(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

// Employee records can only be retrieved using GET.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');

    send_employees_json(405, [
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
}

start_app_session();

try {
    // Validate session against the current database account.
    if (!is_logged_in()) {
        send_employees_json(401, [
            'success' => false,
            'message' => 'Please log in to continue.'
        ]);
    }

    // Only Admin accounts can retrieve personnel records.
    if ((int) $_SESSION['role_id'] !== ADMIN_ROLE_ID) {
        send_employees_json(403, [
            'success' => false,
            'message' => 'Administrator access is required.'
        ]);
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT
            e.employee_id,
            e.employee_number,
            e.last_name,
            e.first_name,
            e.middle_name,
            e.plantilla_item_number,
            e.position,
            e.salary_grade,
            e.step_increment,
            e.date_original_appointment,
            e.date_last_promotion,
            e.deped_email,
            e.personnel_type,

            a.account_id,
            a.username,
            a.created_at AS account_created_at,
            a.is_active,
            r.role_name

        FROM employees e

        LEFT JOIN accounts a
            ON a.employee_id = e.employee_id

        LEFT JOIN roles r
            ON r.role_id = a.role_id

        ORDER BY
            e.last_name ASC,
            e.first_name ASC
    ");

    $stmt->execute();

    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $teaching = [];
    $nonTeaching = [];
    $accounts = [];

    foreach ($employees as $employee) {
        if ($employee['personnel_type'] === 'Teaching') {
            $teaching[] = $employee;
        } elseif ($employee['personnel_type'] === 'Non-Teaching') {
            $nonTeaching[] = $employee;
        }

        $accounts[] = $employee;
    }

    send_employees_json(200, [
        'success' => true,
        'data' => [
            'teaching' => $teaching,
            'non_teaching' => $nonTeaching,
            'accounts' => $accounts,
            'statistics' => [
                'teaching' => count($teaching),
                'non_teaching' => count($nonTeaching),
                'total' => count($employees)
            ]
        ]
    ]);

} catch (Throwable $e) {
    error_log(
        'PERSYS employees API error: ' . $e->getMessage()
    );

    send_employees_json(500, [
        'success' => false,
        'message' => 'Unable to load employee records.'
    ]);
}
