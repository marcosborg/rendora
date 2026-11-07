-- Apply to the isolated Sandbox database before enabling the integration.
CREATE TABLE IF NOT EXISTS paypal_intents (
    id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    company_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    charge_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin,
    plan_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin,
    app_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    recipient_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin,
    beneficiary VARCHAR(150),
    merchant_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin,
    paypal_plan_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin,
    amount_cents BIGINT NOT NULL,
    remote_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin UNIQUE,
    approval_url TEXT,
    status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'CREATING',
    covered_until DATETIME,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX paypal_intents_company_charge (company_id,charge_id,status),
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (charge_id) REFERENCES charges(id),
    FOREIGN KEY (plan_id) REFERENCES plans(id),
    FOREIGN KEY (recipient_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS paypal_subscription_payments (
    id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    intent_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    amount_cents BIGINT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (intent_id) REFERENCES paypal_intents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
