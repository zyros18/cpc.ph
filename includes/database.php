<?php
function database(): PDO
{
    static $connection;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $host = getenv('CPC_DB_HOST') ?: '127.0.0.1';
    $name = getenv('CPC_DB_NAME') ?: 'cpc_penalty';
    $user = getenv('CPC_DB_USER') ?: 'root';
    $password = getenv('CPC_DB_PASSWORD') ?: '';

    $connection = new PDO(
        "mysql:host={$host};dbname={$name};charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $connection;
}

function student_course_catalog(): array
{
    return [
        'CET' => ['IT', 'CE', 'EE'],
        'CTHM' => [
            'Bachelor of Science in Hospitality Management (BSHM)',
            'Bachelor of Science in Tourism Management (BSTM)',
        ],
        'CASE' => [
            'Bachelor of Arts in Political Science',
            'Bachelor of Arts in English',
            'Bachelor of Arts in Communication',
            'Bachelor of Science in Psychology',
            'Bachelor of Science in Mathematics',
            'Bachelor of Secondary Education (BSEd) - Mathematics',
            'Bachelor of Secondary Education (BSEd) - English',
            'Bachelor of Secondary Education (BSEd) - Filipino',
            'Bachelor of Secondary Education (BSEd) - Science',
            'Bachelor of Secondary Education (BSEd) - Social Studies',
            'Bachelor of Secondary Education (BSEd) - Religious Education',
            'Bachelor of Elementary Education (BEEd)',
            'Bachelor of Physical Education (BPEd)',
        ],
        'CRIM' => ['Bachelor of Science in Criminology (BS Criminology)'],
        'SSG' => ['Student leadership and governance (organization)'],
        'CME' => ['Maritime education programs (confirm current program with CPC)'],
        'COL' => ['Law degree program (confirm current title with CPC)'],
        'CMA' => [
            'Bachelor of Science in Accountancy (BSA)',
            'Bachelor of Science in Business Administration (BSBA)',
        ],
        'SHS' => ['Senior High School Program'],
        'JHS' => ['Junior High School Program'],
        'ELEMENTARY' => ['Elementary Program'],
    ];
}

function student_year_levels(string $department): array
{
    if ($department === 'ELEMENTARY') {
        return array_map(static fn (int $grade): string => 'Grade ' . $grade, range(1, 6));
    }
    if ($department === 'JHS') {
        return array_map(static fn (int $grade): string => 'Grade ' . $grade, range(7, 10));
    }
    if ($department === 'SHS') {
        return array_map(static fn (int $grade): string => 'Grade ' . $grade, range(11, 12));
    }

    return ['1st', '2nd', '3rd', '4th'];
}

function ensure_student_academic_fields(): void
{
    static $fieldsReady = false;
    if ($fieldsReady) {
        return;
    }

    $connection = database();
    $columns = $connection->query('SHOW COLUMNS FROM users')->fetchAll();
    $columnTypes = [];
    foreach ($columns as $column) {
        $columnTypes[$column['Field']] = $column['Type'];
    }

    if (!isset($columnTypes['department'])) {
        $connection->exec('ALTER TABLE users ADD COLUMN department VARCHAR(30) NULL AFTER role');
    } elseif (stripos($columnTypes['department'], 'enum(') === 0) {
        $connection->exec('ALTER TABLE users MODIFY department VARCHAR(30) NULL');
    }

    if (!isset($columnTypes['course'])) {
        $connection->exec('ALTER TABLE users ADD COLUMN course VARCHAR(150) NULL AFTER department');
    }

    if (!isset($columnTypes['year_level'])) {
        $connection->exec('ALTER TABLE users ADD COLUMN year_level VARCHAR(10) NULL AFTER course');
    }

    $fieldsReady = true;
}

function ensure_tuition_balances_table(): void
{
    static $tableReady = false;
    if ($tableReady) {
        return;
    }

    database()->exec(
        "CREATE TABLE IF NOT EXISTS tuition_balances (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            student_id INT UNSIGNED NOT NULL UNIQUE,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            pending_payment_amount DECIMAL(10,2) NULL,
            paid_amount DECIMAL(10,2) NULL,
            status ENUM('unpaid', 'pending', 'paid') NOT NULL DEFAULT 'unpaid',
            payment_method VARCHAR(30) NULL,
            reference_number VARCHAR(100) NULL,
            paid_at DATETIME NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_tuition_balances_student FOREIGN KEY (student_id)
                REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $columns = database()->query('SHOW COLUMNS FROM tuition_balances')->fetchAll();
    $hasPendingPaymentAmount = false;
    foreach ($columns as $column) {
        if ($column['Field'] === 'pending_payment_amount') {
            $hasPendingPaymentAmount = true;
            break;
        }
    }
    if (!$hasPendingPaymentAmount) {
        database()->exec('ALTER TABLE tuition_balances ADD COLUMN pending_payment_amount DECIMAL(10,2) NULL AFTER amount');
    }
    database()->exec(
        "UPDATE tuition_balances
         SET pending_payment_amount = amount
         WHERE status = 'pending' AND pending_payment_amount IS NULL"
    );

    $tableReady = true;
}

function money_to_cents(string $amount): ?int
{
    if (!preg_match('/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?$/D', $amount, $matches)) {
        return null;
    }

    return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
}

function ensure_refund_requests_table(): void
{
    static $tableReady = false;
    if ($tableReady) {
        return;
    }

    database()->exec(
        "CREATE TABLE IF NOT EXISTS refund_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            penalty_id INT UNSIGNED NOT NULL,
            reference_number VARCHAR(100) NULL,
            reason TEXT NULL,
            payout_number VARCHAR(30) NULL,
            screenshot LONGBLOB NOT NULL,
            screenshot_mime_type VARCHAR(30) NOT NULL,
            status ENUM('pending', 'approved', 'rejected', 'refunded') NOT NULL DEFAULT 'pending',
            reviewed_by_user_id INT UNSIGNED NULL,
            reviewed_at DATETIME NULL,
            refunded_amount DECIMAL(10,2) NULL,
            refund_transfer_reference VARCHAR(100) NULL,
            refunded_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_refund_requests_penalty_status (penalty_id, status),
            CONSTRAINT fk_refund_requests_penalty FOREIGN KEY (penalty_id)
                REFERENCES penalties(id) ON DELETE CASCADE,
            CONSTRAINT fk_refund_requests_reviewer FOREIGN KEY (reviewed_by_user_id)
                REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $reasonColumn = database()->query("SHOW COLUMNS FROM refund_requests LIKE 'reason'")->fetch();
    if (!$reasonColumn) {
        database()->exec('ALTER TABLE refund_requests ADD COLUMN reason TEXT NULL AFTER reference_number');
    }

    $columns = database()->query('SHOW COLUMNS FROM refund_requests')->fetchAll();
    $columnNames = array_column($columns, 'Field');
    $newColumns = [
        'payout_number' => 'ALTER TABLE refund_requests ADD COLUMN payout_number VARCHAR(30) NULL AFTER reason',
        'refunded_amount' => 'ALTER TABLE refund_requests ADD COLUMN refunded_amount DECIMAL(10,2) NULL AFTER reviewed_at',
        'refund_transfer_reference' => 'ALTER TABLE refund_requests ADD COLUMN refund_transfer_reference VARCHAR(100) NULL AFTER refunded_amount',
        'refunded_at' => 'ALTER TABLE refund_requests ADD COLUMN refunded_at DATETIME NULL AFTER refund_transfer_reference',
    ];
    foreach ($newColumns as $columnName => $alterSql) {
        if (!in_array($columnName, $columnNames, true)) {
            database()->exec($alterSql);
        }
    }

    $statusColumn = database()->query("SHOW COLUMNS FROM refund_requests LIKE 'status'")->fetch();
    if ($statusColumn && strpos($statusColumn['Type'], "'refunded'") === false) {
        database()->exec(
            "ALTER TABLE refund_requests
             MODIFY status ENUM('pending', 'approved', 'rejected', 'refunded')
             NOT NULL DEFAULT 'pending'"
        );
    }

    $tableReady = true;
}
