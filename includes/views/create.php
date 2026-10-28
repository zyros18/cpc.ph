<?php
require_once __DIR__ . '/../database.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$error = $_SESSION['register_error'] ?? '';
unset($_SESSION['register_error']);
$courseCatalog = student_course_catalog();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create account | CPC Penalty System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            margin: 0;
            padding: clamp(12px, 4vw, 32px);
            min-height: 100vh;
            min-height: 100svh;
            display: grid;
            place-items: center;
            background-color: #1e293b;
            background-image:
                linear-gradient(rgba(15, 23, 42, 0.35), rgba(15, 23, 42, 0.35)),
                url("../../Designer.png");
            background-position: center;
            background-size: cover;
            background-repeat: no-repeat;
            background-attachment: fixed;
        }
        .register-box {
            width: min(100%, 800px);
            border: 1px solid rgba(255, 255, 255, 0.22);
            background: rgba(12, 31, 59, 0.86);
            -webkit-backdrop-filter: blur(14px);
            backdrop-filter: blur(14px);
            border-radius: 20px;
            box-shadow: 0 24px 60px rgba(2, 12, 27, 0.35);
            padding: clamp(1.25rem, 5vw, 2rem);
            color: #f8fafc;
        }
        .logo-container { text-align: center; margin-bottom: 1.25rem; }
        .logo {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: block;
            object-fit: contain;
            padding: 5px;
            margin: 0 auto 0.85rem;
            background: #fff;
            box-shadow: 0 6px 18px rgba(2, 12, 27, 0.3);
        }
        .register-box h1 { color: #fff; font-weight: 700; letter-spacing: -0.02em; }
        .register-subtitle { color: #cbd5e1; }
        .register-box .form-label { color: #e2e8f0; font-weight: 600; }
        .register-box .form-control,
        .register-box .form-select {
            min-height: 46px;
            border-color: rgba(203, 213, 225, 0.65);
            border-radius: 9px;
            background-color: rgba(255, 255, 255, 0.97);
        }
        .register-box .form-control:focus,
        .register-box .form-select:focus {
            border-color: #60a5fa;
            box-shadow: 0 0 0 0.2rem rgba(96, 165, 250, 0.25);
        }
        .register-box .form-control:disabled,
        .register-box .form-select:disabled { background-color: #e2e8f0; }
        .register-box .form-text { color: #cbd5e1; }
        .student-grid,
        .account-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            column-gap: 1rem;
        }
        #studentFields[hidden] { display: none; }
        .student-grid .mb-3,
        .account-grid .mb-3 { min-width: 0; }
        .register-box .btn-primary {
            min-height: 46px;
            border-color: #60a5fa;
            background: #2563eb;
            font-weight: 650;
        }
        .register-box .btn-primary:hover { border-color: #3b82f6; background: #1d4ed8; }
        .register-box a { color: #dbeafe; }
        .register-box a:hover { color: #fff; }
        .register-footer { color: #cbd5e1; }
        @media (max-width: 600px) {
            body { background-attachment: scroll; background-position: center; }
            .register-box { border-radius: 14px; }
            .register-box h1 { font-size: 1.2rem; }
            .register-box .form-label { margin-bottom: 0.3rem; font-size: 0.82rem; }
            .register-box .form-control,
            .register-box .form-select {
                min-height: 40px;
                padding: 0.45rem 0.65rem;
                font-size: 0.85rem;
            }
            .student-grid,
            .account-grid { grid-template-columns: 1fr; }
            .logo-container { margin-bottom: 1.1rem; }
        }
    </style>
</head>
<body>
    <main class="register-box">
        <div class="logo-container">
            <img src="../../cpc.logo.jpg" alt="Colegio de la Purisima Concepcion logo" class="logo">
            <h1 class="h3 mb-1">CPC Penalty System</h1>
        </div>
        <p class="register-subtitle text-center mb-4">Create your student account</p>

        <?php if ($error): ?>
            <div class="alert alert-danger" role="alert">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="post" action="../register.php">
            <input type="hidden" name="action" value="register">

            <div class="account-grid">
            <div class="mb-3">
                <label class="form-label" for="full_name">Full name</label>
                <input class="form-control" id="full_name" name="full_name" maxlength="150" autocomplete="name" pattern="[\p{L}\p{M} .'\u2019\x2d]+" title="Use letters, spaces, apostrophes, periods, or hyphens only." required>
                <div class="form-text">Use letters</div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="username">Email</label>
                <input class="form-control" id="username" name="username" maxlength="100" autocomplete="username" required>
            </div>
            </div>
            <div id="studentFields" class="student-grid">
                <div class="mb-3">
                    <label class="form-label" for="student_id">Student ID</label>
                    <input class="form-control" type="text" id="student_id" name="student_id" inputmode="numeric" pattern="[0-9]+" maxlength="50" title="Student ID must contain numbers only." required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="department">Department</label>
                    <select class="form-select" id="department" name="department" required>
                        <option value="">Choose Department</option>
                        <option value="CET">CET</option>
                        <option value="CTHM">CHTM</option>
                        <option value="CASE">CASE</option>
                        <option value="CRIM">CRIM</option>
                        <option value="SSG">SSG</option>
                        <option value="CME">CME</option>
                        <option value="COL">COL</option>
                        <option value="CMA">CBMA</option>
                        <option value="SHS">SHS</option>
                        <option value="JHS">JHS</option>
                        <option value="ELEMENTARY">ELEMENTARY</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="course">Course</label>
                    <select class="form-select" id="course" name="course" required disabled>
                        <option value="">Choose a department first</option>
                    </select>
                    <div class="mt-2" id="otherCourseGroup" hidden>
                        <input class="form-control" id="other_course" name="other_course" maxlength="150" placeholder="Enter your course" disabled>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="year_level">Year level</label>
                    <select class="form-select" id="year_level" name="year_level" required disabled>
                        <option value="">Choose a department first</option>
                    </select>
                </div>
            </div>
            <div class="account-grid">
            <div class="mb-3">
                <label class="form-label" for="cellphone">Cellphone number</label>
                <input class="form-control" type="tel" id="cellphone" name="cellphone" autocomplete="tel" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="role">Account role</label>
                <select class="form-select" id="role" name="role" required>
                    <option value="student" selected>Student</option>
                    <option value="teacher">Teacher</option>
                </select>
            </div>
            <div class="mb-3" id="teacherCodeGroup" hidden>
                <label class="form-label" for="teacher_code">Teacher registration code</label>
                <input class="form-control" type="password" id="teacher_code" name="teacher_code" autocomplete="off" disabled>
            </div>
            </div>
            <div class="account-grid">
            <div class="mb-3">
                <label class="form-label" for="password">Password</label>
                <input class="form-control" type="password" id="password" name="password" minlength="6" autocomplete="new-password" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="password_confirm">Confirm password</label>
                <input class="form-control" type="password" id="password_confirm" name="password_confirm" minlength="6" autocomplete="new-password" required>
            </div>
            </div>
            <button class="btn btn-primary w-100" type="submit">Create account</button>
        </form>

        <p class="register-footer text-center mt-4 mb-0">Already have an account? <a href="../../login.php">Back to login</a></p>
    </main>
    <script>
        const roleSelect = document.getElementById('role');
        const fullName = document.getElementById('full_name');
        const teacherCodeGroup = document.getElementById('teacherCodeGroup');
        const teacherCode = document.getElementById('teacher_code');
        const studentFields = document.getElementById('studentFields');
        const studentId = document.getElementById('student_id');
        const course = document.getElementById('course');
        const otherCourse = document.getElementById('other_course');
        const otherCourseGroup = document.getElementById('otherCourseGroup');
        const department = document.getElementById('department');
        const yearLevel = document.getElementById('year_level');
        const courseCatalog = <?= json_encode($courseCatalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        function setOptions(select, placeholder, options) {
            select.replaceChildren(new Option(placeholder, ''));
            options.forEach(function (value) {
                select.add(new Option(value, value));
            });
        }

        function updateAcademicChoices() {
            const selectedDepartment = department.value;
            const courses = courseCatalog[selectedDepartment] || [];
            setOptions(course, selectedDepartment ? 'Choose a course' : 'Choose a department first', courses);
            if (selectedDepartment) {
                course.add(new Option('Other / not listed', '__other__'));
            }
            course.disabled = !selectedDepartment || roleSelect.value === 'teacher';

            let levels = ['1st', '2nd', '3rd', '4th'];
            if (selectedDepartment === 'ELEMENTARY') {
                levels = Array.from({ length: 6 }, function (_, index) { return 'Grade ' + (index + 1); });
            } else if (selectedDepartment === 'JHS') {
                levels = Array.from({ length: 4 }, function (_, index) { return 'Grade ' + (index + 7); });
            } else if (selectedDepartment === 'SHS') {
                levels = ['Grade 11', 'Grade 12'];
            }
            setOptions(yearLevel, selectedDepartment ? 'Choose year/grade level' : 'Choose a department first', levels);
            yearLevel.disabled = !selectedDepartment || roleSelect.value === 'teacher';
            updateOtherCourseField();
        }

        function updateOtherCourseField() {
            const showOtherCourse = course.value === '__other__' && roleSelect.value !== 'teacher';
            otherCourseGroup.hidden = !showOtherCourse;
            otherCourse.disabled = !showOtherCourse;
            otherCourse.required = !otherCourse.disabled;
        }

        department.addEventListener('change', updateAcademicChoices);
        course.addEventListener('change', updateOtherCourseField);

        fullName.addEventListener('input', function () {
            const cursorPosition = fullName.selectionStart ?? fullName.value.length;
            const cleanPrefix = fullName.value.slice(0, cursorPosition).replace(/[0-9]/g, '');
            const cleanValue = fullName.value.replace(/[0-9]/g, '');
            fullName.value = cleanValue;
            const nextPosition = Math.min(cleanPrefix.length, cleanValue.length);
            fullName.setSelectionRange(nextPosition, nextPosition);
        });

        studentId.addEventListener('input', function () {
            studentId.value = studentId.value.replace(/[^0-9]/g, '');
        });

        function updateRegistrationFields() {
            const isTeacher = roleSelect.value === 'teacher';
            teacherCodeGroup.hidden = !isTeacher;
            teacherCode.disabled = !isTeacher;
            teacherCode.required = isTeacher;
            studentFields.hidden = isTeacher;
            studentId.disabled = isTeacher;
            studentId.required = !isTeacher;
            course.required = !isTeacher;
            department.disabled = isTeacher;
            department.required = !isTeacher;
            yearLevel.required = !isTeacher;
            updateAcademicChoices();
        }

        roleSelect.addEventListener('change', updateRegistrationFields);
        updateRegistrationFields();
    </script>
</body>
</html>
