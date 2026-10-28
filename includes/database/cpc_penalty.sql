CREATE DATABASE IF NOT EXISTS cpc_penalty CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cpc_penalty;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    student_id VARCHAR(50) NULL UNIQUE,
    cellphone VARCHAR(30) NULL,
    role ENUM('student', 'teacher') NOT NULL DEFAULT 'student',
    department VARCHAR(30) NULL,
    course VARCHAR(150) NULL,
    year_level VARCHAR(10) NULL,
    profile_picture LONGBLOB NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE users ADD COLUMN IF NOT EXISTS cellphone VARCHAR(30) NULL AFTER student_id;
ALTER TABLE users MODIFY department VARCHAR(30) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS course VARCHAR(150) NULL AFTER department;
ALTER TABLE users ADD COLUMN IF NOT EXISTS year_level VARCHAR(10) NULL AFTER course;

CREATE TABLE IF NOT EXISTS penalties (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status ENUM('unpaid', 'pending', 'paid') NOT NULL DEFAULT 'unpaid',
    payment_method VARCHAR(30) NULL,
    reference_number VARCHAR(100) NULL,
    paid_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_penalties_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS tuition_balances (
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
    CONSTRAINT fk_tuition_balances_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
);
ALTER TABLE tuition_balances ADD COLUMN IF NOT EXISTS pending_payment_amount DECIMAL(10,2) NULL AFTER amount;

INSERT IGNORE INTO users (username, password, full_name, student_id, role, department)
VALUES ('teacher01', '$2y$10$WVneWJG1IrEljPa3zE9D2ug/S0RgQfoopDSe1JfKsCj1NuwkZJJ8C', 'System Teacher', NULL, 'teacher', NULL);

INSERT IGNORE INTO users (username, password, full_name, student_id, role, department)
VALUES ('zyros', '$2y$10$c99Ar106YgtdrOPhR7UN0OxiRKQonT/PtfN4jGBhksrdxq7./ANO.', 'Zyros', 'ZYROS001', 'student', 'IT');
