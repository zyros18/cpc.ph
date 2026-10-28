<?php
require_once __DIR__ . '/auth.php';
ensure_student_academic_fields();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Location: views/create.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method Not Allowed');
}

$fullName = trim($_POST['full_name'] ?? '');
$username = trim($_POST['username'] ?? '');
$studentId = trim($_POST['student_id'] ?? '');
$courseInput = $_POST['course'] ?? '';
$courseChoice = is_string($courseInput) ? trim($courseInput) : '';
$otherCourseInput = $_POST['other_course'] ?? '';
$otherCourse = is_string($otherCourseInput) ? trim($otherCourseInput) : '';
$course = $courseChoice === '__other__' ? $otherCourse : $courseChoice;
$cellphone = trim($_POST['cellphone'] ?? '');
$departmentInput = $_POST['department'] ?? '';
$department = is_string($departmentInput) ? $departmentInput : '';
$yearLevelInput = $_POST['year_level'] ?? '';
$yearLevel = is_string($yearLevelInput) ? $yearLevelInput : '';
$role = $_POST['role'] ?? 'student';
$teacherCode = $_POST['teacher_code'] ?? '';
$password = $_POST['password'] ?? '';
$passwordConfirm = $_POST['password_confirm'] ?? '';

$fail = static function (string $message): never {
    $_SESSION['register_error'] = $message;
    header('Location: views/create.php');
    exit;
};

if (!in_array($role, ['student', 'teacher'], true)) {
    $fail('Choose a valid account role.');
}

if ($fullName === '' || $username === '' || $cellphone === '') {
    $fail('Please complete all required fields.');
}

if (strlen($fullName) > 150 ||
    preg_match("/^[\\p{L}\\p{M} .'’-]+$/uD", $fullName) !== 1 ||
    preg_match('/\\p{L}/u', $fullName) !== 1) {
    $fail('Full name can contain letters, spaces, apostrophes, periods, or hyphens only. Numbers are not allowed.');
}

if (strlen($username) > 100) {
    $fail('Username must be 100 characters or fewer.');
}

if ($role === 'student') {
    if ($studentId === '' || strlen($studentId) > 50 ||
        preg_match('/^[0-9]+$/D', $studentId) !== 1) {
        $fail('Student ID is required and must contain numbers only.');
    }
    if (!in_array($department, ['CET', 'CTHM', 'CASE', 'CRIM', 'SSG', 'CME', 'COL', 'CMA', 'SHS', 'JHS', 'ELEMENTARY'], true)) {
        $fail('Choose a valid department.');
    }
    $courseOptions = student_course_catalog()[$department];
    if (($courseChoice === '__other__' && ($otherCourse === '' || strlen($otherCourse) > 150)) ||
        ($courseChoice !== '__other__' && !in_array($courseChoice, $courseOptions, true))) {
        $fail('Choose a course for the selected department or enter a course name under Other.');
    }
    if (!in_array($yearLevel, student_year_levels($department), true)) {
        $fail('Choose a valid year or grade level for the selected department.');
    }
} else {
    $registrationCode = getenv('CPC_TEACHER_REGISTRATION_CODE') ?: '021518';
    if ($registrationCode === '' || !hash_equals($registrationCode, $teacherCode)) {
        $fail('Teacher registration requires a valid teacher registration code.');
    }
    $studentId = null;
    $course = null;
    $department = null;
    $yearLevel = null;
}

if (strlen($password) < 6 || $password !== $passwordConfirm) {
    $fail('Passwords must match and contain at least 6 characters.');
}

try {
    $statement = database()->prepare(
        'INSERT INTO users (username, password, full_name, student_id, cellphone, role, department, course, year_level)
            VALUES (:username, :password, :full_name, :student_id, :cellphone, :role, :department, :course, :year_level)'
    );
    $statement->execute([
        'username' => $username,
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'full_name' => $fullName,
        'student_id' => $studentId,
        'cellphone' => $cellphone,
        'role' => $role,
        'department' => $department,
        'course' => $course,
        'year_level' => $yearLevel,
    ]);
} catch (PDOException $exception) {
    if ($exception->getCode() === '23000') {
        $fail('That username or student ID is already registered.');
    }

    $fail('Registration could not be completed. Check that the CPC database is installed.');
}

unset($_SESSION['user']);
session_regenerate_id(true);
$_SESSION['register_success'] = 'Account created. You can now log in.';
header('Location: ../login.php');
exit;
