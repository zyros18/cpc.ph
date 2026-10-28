USE cpc_penalty;

CREATE TABLE IF NOT EXISTS gcash_merchant_settings (
    settings_id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    merchant_name VARCHAR(150) NOT NULL,
    gcash_account_number VARCHAR(30) NULL,
    qr_image MEDIUMBLOB NOT NULL,
    qr_mime_type VARCHAR(30) NOT NULL,
    updated_by_user_id INT UNSIGNED NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_gcash_merchant_settings_singleton CHECK (settings_id = 1),
    CONSTRAINT fk_gcash_merchant_settings_user FOREIGN KEY (updated_by_user_id)
        REFERENCES users(id) ON DELETE RESTRICT
);

UPDATE penalties p
INNER JOIN paymongo_checkouts c ON c.penalty_id = p.id
SET p.status = 'unpaid', p.payment_method = NULL, p.reference_number = NULL,
    p.paid_at = NULL
WHERE c.checkout_status IN ('creating', 'active')
  AND p.status = 'pending'
  AND p.payment_method = 'online';

UPDATE paymongo_checkouts
SET paymongo_session_id = NULL, checkout_url = NULL, checkout_status = 'failed'
WHERE checkout_status IN ('creating', 'active');
