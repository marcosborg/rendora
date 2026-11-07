-- Apply separately on MySQL before enabling BANK_TRANSFERS_ENABLED.
CREATE TABLE IF NOT EXISTS rent_bank_accounts (
    contract_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    company_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    recipient_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    beneficiary VARCHAR(150) NOT NULL,
    iban VARCHAR(34) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    FOREIGN KEY (contract_id) REFERENCES contracts(id),
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (recipient_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS bank_transfer_proofs (
    id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    company_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    charge_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin,
    plan_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin,
    months INT,
    amount_cents BIGINT NOT NULL,
    beneficiary VARCHAR(150) NOT NULL,
    iban VARCHAR(34) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    recipient_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin,
    author_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reviewer_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin,
    mime_type VARCHAR(100) NOT NULL,
    file_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'PENDING',
    bank_reference_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin UNIQUE,
    paid_until DATE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX bank_proofs_company_charge (company_id,charge_id,status),
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (charge_id) REFERENCES charges(id),
    FOREIGN KEY (plan_id) REFERENCES plans(id),
    FOREIGN KEY (recipient_id) REFERENCES users(id),
    FOREIGN KEY (author_id) REFERENCES users(id),
    FOREIGN KEY (reviewer_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
