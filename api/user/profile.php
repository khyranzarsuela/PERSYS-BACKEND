<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database.php';
require_once dirname(__DIR__, 2) . '/session.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function send_user_profile_json(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');

    send_user_profile_json(405, [
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
}

start_app_session();

try {
    // Verify the session and current account status in MySQL.
    if (!is_logged_in()) {
        send_user_profile_json(401, [
            'success' => false,
            'message' => 'Please log in to continue.'
        ]);
    }

    // Only Teaching (2) and Non-Teaching (3) users can access this endpoint.
    $roleId = (int) $_SESSION['role_id'];

    if ($roleId !== USER_TEACHING_ROLE_ID && $roleId !== USER_NONTEACHING_ROLE_ID) {
        send_user_profile_json(403, [
            'success' => false,
            'message' => 'Access restricted to personnel accounts.'
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
            a.username,
            r.role_name
        FROM employees e
        INNER JOIN accounts a
            ON a.employee_id = e.employee_id
        INNER JOIN roles r
            ON r.role_id = a.role_id
        WHERE e.employee_id = ?
          AND a.is_active = 1
          AND a.role_id IN (?, ?)
        LIMIT 1
    ");

    $stmt->execute([(int) $_SESSION['employee_id'], USER_TEACHING_ROLE_ID, USER_NONTEACHING_ROLE_ID]);
    $profile = $stmt->fetch();

    if (!$profile) {
        send_user_profile_json(404, [
            'success' => false,
            'message' => 'User profile not found.'
        ]);
    }

    // Never return password hashes or passwords from a profile API.
    send_user_profile_json(200, [
        'success' => true,
        'data' => [
            'profile' => [
                'employee_number'           => $profile['employee_number'],
                'last_name'                 => $profile['last_name'],
                'first_name'                => $profile['first_name'],
                'middle_name'               => $profile['middle_name'],
                'plantilla_item_number'     => $profile['plantilla_item_number'],
                'position'                  => $profile['position'],
                'salary_grade'              => $profile['salary_grade'],
                'step_increment'            => $profile['step_increment'],
                'date_original_appointment' => $profile['date_original_appointment'],
                'date_last_promotion'       => $profile['date_last_promotion'],
                'deped_email'               => $profile['deped_email'],
                'username'                  => $profile['username'],
                'role_name'                 => $profile['role_name'],
            ]
        ]
    ]);

} catch (Throwable $e) {
    error_log('PERSYS user profile API error: ' . $e->getMessage());

    send_user_profile_json(500, [
        'success' => false,
        'message' => 'Unable to load profile information.'
    ]);
}
