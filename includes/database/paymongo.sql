USE cpc_penalty;

CREATE TABLE IF NOT EXISTS paymongo_checkouts (
    checkout_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    penalty_id INT UNSIGNED NOT NULL UNIQUE,
    paymongo_session_id VARCHAR(100) NULL UNIQUE,
    reference_number VARCHAR(100) NOT NULL UNIQUE,
    checkout_url TEXT NULL,
    checkout_status ENUM('creating', 'active', 'failed', 'paid') NOT NULL DEFAULT 'creating',
    amount DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_paymongo_checkout_penalty FOREIGN KEY (penalty_id)
        REFERENCES penalties(id) ON DELETE CASCADE
);
