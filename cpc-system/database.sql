CREATE DATABASE IF NOT EXISTS cpc_student_penalties
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
USE cpc_student_penalties;

CREATE TABLE IF NOT EXISTS roles (
    role_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_name VARCHAR(40) NOT NULL,
    role_description VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (role_id),
    UNIQUE KEY uq_roles_name (role_name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
    user_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_id BIGINT UNSIGNED NOT NULL,
    student_id VARCHAR(50) NULL,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    phone_number VARCHAR(30) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_users_student_id (student_id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role (role_id),
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (role_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS penalties (
    penalty_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_user_id BIGINT UNSIGNED NOT NULL,
    recorded_by_user_id BIGINT UNSIGNED NOT NULL,
    penalty_title VARCHAR(150) NOT NULL,
    penalty_description TEXT NULL,
    amount DECIMAL(10,2) NOT NULL,
    status ENUM('unpaid', 'paid') NOT NULL DEFAULT 'unpaid',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (penalty_id),
    KEY idx_penalties_student (student_user_id),
    KEY idx_penalties_recorder (recorded_by_user_id),
    KEY idx_penalties_status_created (status, created_at),
    CONSTRAINT chk_penalties_amount CHECK (amount > 0),
    CONSTRAINT fk_penalties_student FOREIGN KEY (student_user_id) REFERENCES users (user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_penalties_recorder FOREIGN KEY (recorded_by_user_id) REFERENCES users (user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
    payment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    penalty_id BIGINT UNSIGNED NOT NULL,
    payer_user_id BIGINT UNSIGNED NOT NULL,
    amount_paid DECIMAL(10,2) NOT NULL,
    payment_reference VARCHAR(80) NOT NULL,
    payment_status ENUM('pending', 'verified', 'rejected') NOT NULL DEFAULT 'pending',
    payment_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    verified_by_user_id BIGINT UNSIGNED NULL,
    verified_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (payment_id),
    UNIQUE KEY uq_payments_penalty (penalty_id),
    UNIQUE KEY uq_payments_reference (payment_reference),
    KEY idx_payments_payer (payer_user_id),
    KEY idx_payments_verifier (verified_by_user_id),
    KEY idx_payments_status_date (payment_status, payment_date),
    CONSTRAINT chk_payments_amount CHECK (amount_paid > 0),
    CONSTRAINT fk_payments_penalty FOREIGN KEY (penalty_id) REFERENCES penalties (penalty_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_payments_payer FOREIGN KEY (payer_user_id) REFERENCES users (user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_payments_verifier FOREIGN KEY (verified_by_user_id) REFERENCES users (user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS qr_codes (
    qr_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    payment_id BIGINT UNSIGNED NOT NULL,
    qr_token CHAR(64) NOT NULL,
    qr_image_path VARCHAR(255) NOT NULL,
    is_used TINYINT(1) NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    used_at DATETIME NULL,
    PRIMARY KEY (qr_id),
    UNIQUE KEY uq_qr_payment (payment_id),
    UNIQUE KEY uq_qr_token (qr_token),
    KEY idx_qr_expiry (expires_at, is_used),
    CONSTRAINT fk_qr_payment FOREIGN KEY (payment_id) REFERENCES payments (payment_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payment_channels (
    channel_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    channel_name VARCHAR(100) NOT NULL,
    channel_type ENUM('online', 'in_person') NOT NULL,
    details VARCHAR(500) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (channel_id),
    UNIQUE KEY uq_channels_name (channel_name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payment_channel_assignments (
    assignment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    payment_id BIGINT UNSIGNED NOT NULL,
    channel_id BIGINT UNSIGNED NOT NULL,
    transaction_details VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (assignment_id),
    UNIQUE KEY uq_assignment_payment_channel (payment_id, channel_id),
    KEY idx_assignments_channel (channel_id),
    CONSTRAINT fk_assignments_payment FOREIGN KEY (payment_id) REFERENCES payments (payment_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_assignments_channel FOREIGN KEY (channel_id) REFERENCES payment_channels (channel_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
    audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    details JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (audit_id),
    KEY idx_audit_user_date (user_id, created_at),
    KEY idx_audit_entity (entity_type, entity_id),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO roles (role_name, role_description) VALUES
    ('Admin', 'Full system, account, and reporting access'),
    ('Officer', 'Penalty, student record, payment channel, and verification access'),
    ('Student', 'Personal penalty, payment, QR, and history access')
ON DUPLICATE KEY UPDATE role_description = VALUES(role_description);

INSERT INTO payment_channels (channel_name, channel_type, details) VALUES
    ('GCash', 'online', 'Mobile wallet'),
    ('Maya', 'online', 'Mobile wallet'),
    ('CPC Cashier Office', 'in_person', 'Over-the-counter')
ON DUPLICATE KEY UPDATE channel_type = VALUES(channel_type), details = VALUES(details);
