USE cpc_penalty;

CREATE TABLE IF NOT EXISTS refund_requests (
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
);
