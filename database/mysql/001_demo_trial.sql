-- MySQL 5.7 demo migration trial. Apply to a new empty database.

CREATE TABLE users (
    `id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    `name` TEXT NOT NULL,
    `email` VARCHAR(512) COLLATE utf8mb4_unicode_ci NOT NULL UNIQUE,
    `password_hash` TEXT NOT NULL,
    `platform_admin` BIGINT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    email_verified_at DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE plans (
    `id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    `name` TEXT NOT NULL,
    `stripe_price_id` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin,
    `property_limit` BIGINT NOT NULL DEFAULT 100,
    `member_limit` BIGINT NOT NULL DEFAULT 20,
    `active` BIGINT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE companies (
    `id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    `slug` VARCHAR(512) NOT NULL UNIQUE,
    `name` TEXT NOT NULL,
    `email` VARCHAR(512) NOT NULL,
    `phone` VARCHAR(255) NOT NULL DEFAULT '',
    `description` TEXT,
    `color` VARCHAR(255) NOT NULL DEFAULT '#197566',
    `stripe_account_id` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin UNIQUE,
    `stripe_customer_id` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin UNIQUE,
    `plan_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin REFERENCES plans(id),
    `subscription_status` VARCHAR(255) NOT NULL DEFAULT 'unconfigured',
    `reminder_days` VARCHAR(255) NOT NULL DEFAULT '[-5,0,3]',
    `email_template` TEXT,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE memberships (
    `id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    `company_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `user_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `role` VARCHAR(100) NOT NULL,
    `active` BIGINT NOT NULL DEFAULT 1,
    UNIQUE(company_id,user_id),
    invitation_pending BIGINT NOT NULL DEFAULT 0,
    FOREIGN KEY (`company_id`) REFERENCES companies(id),
    FOREIGN KEY (`user_id`) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE sessions (
    `id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    `user_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `token_hash` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    `csrf` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE reset_tokens (
    `id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    `user_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `token_hash` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    `expires_at` DATETIME NOT NULL,
    FOREIGN KEY (`user_id`) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE rate_limits (
    `key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    `attempts` BIGINT NOT NULL,
    `expires_at` BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE properties (
    `id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    `company_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `parent_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin,
    `owner_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin REFERENCES users(id),
    `name` TEXT NOT NULL,
    `type` VARCHAR(100) NOT NULL,
    `address` TEXT NOT NULL,
    `city` TEXT NOT NULL,
    `description` TEXT,
    `bedrooms` BIGINT NOT NULL DEFAULT 1,
    `area` BIGINT NOT NULL DEFAULT 0,
    `rent_cents` BIGINT NOT NULL,
    `published` BIGINT NOT NULL DEFAULT 0,
    `photos` TEXT,
    `amenities` TEXT,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(company_id,id),
    FOREIGN KEY(company_id,parent_id) REFERENCES properties(company_id,id),
    FOREIGN KEY (`company_id`) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE contacts (
    `id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    `company_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `user_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin REFERENCES users(id),
    `name` TEXT NOT NULL,
    `email` VARCHAR(512) NOT NULL,
    `phone` VARCHAR(255) NOT NULL DEFAULT '',
    `kind` VARCHAR(255) NOT NULL DEFAULT 'LEAD',
    `tags` TEXT,
    `notes` TEXT,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(company_id,id),
    FOREIGN KEY (`company_id`) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE leads (
    `id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    `company_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `contact_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `property_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin,
    `stage` VARCHAR(255) NOT NULL DEFAULT 'NEW',
    `source` VARCHAR(255) NOT NULL DEFAULT 'manual',
    `notes` TEXT,
    `visit_at` DATETIME,
    `task` VARCHAR(255) NOT NULL DEFAULT '',
    `task_due` DATE,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(company_id,id),
    FOREIGN KEY(company_id,contact_id) REFERENCES contacts(company_id,id),
    FOREIGN KEY(company_id,property_id) REFERENCES properties(company_id,id),
    FOREIGN KEY (`company_id`) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE contracts (
    `id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    `company_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `property_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `start_date` DATE NOT NULL,
    `end_date` DATE,
    `rent_cents` BIGINT NOT NULL,
    `charges_cents` BIGINT NOT NULL DEFAULT 0,
    `deposit_cents` BIGINT NOT NULL DEFAULT 0,
    `due_day` BIGINT NOT NULL DEFAULT 1,
    `status` VARCHAR(255) NOT NULL DEFAULT 'ACTIVE',
    `notes` TEXT,
    `template` TEXT,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(company_id,id),
    FOREIGN KEY(company_id,property_id) REFERENCES properties(company_id,id),
    FOREIGN KEY (`company_id`) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE contract_residents (
    `company_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `contract_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `contact_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY(contract_id,contact_id),
    FOREIGN KEY(company_id,contract_id) REFERENCES contracts(company_id,id),
    FOREIGN KEY(company_id,contact_id) REFERENCES contacts(company_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE rent_changes (
    `id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    `company_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `contract_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `effective_date` DATE NOT NULL,
    `rent_cents` BIGINT NOT NULL,
    UNIQUE(contract_id,effective_date),
    FOREIGN KEY(company_id,contract_id) REFERENCES contracts(company_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE deposit_entries (
    `id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    `company_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `contract_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `kind` VARCHAR(100) NOT NULL,
    `amount_cents` BIGINT NOT NULL,
    `reason` TEXT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(company_id,contract_id) REFERENCES contracts(company_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE inspections (
    `id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    `company_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `contract_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `kind` VARCHAR(100) NOT NULL,
    `notes` TEXT,
    `items` TEXT,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(company_id,contract_id) REFERENCES contracts(company_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE charges (
    `id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    `company_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `contract_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `period` VARCHAR(100) NOT NULL,
    `due_date` DATE NOT NULL,
    `amount_cents` BIGINT NOT NULL,
    `confirmed` BIGINT NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(contract_id,period),
    UNIQUE(company_id,id),
    FOREIGN KEY(company_id,contract_id) REFERENCES contracts(company_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE payments (
    `id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    `company_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `charge_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `amount_cents` BIGINT NOT NULL,
    `kind` VARCHAR(100) NOT NULL,
    `method` VARCHAR(100) NOT NULL,
    `reason` VARCHAR(255) NOT NULL DEFAULT '',
    `external_id` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin UNIQUE,
    `idempotency_key` VARCHAR(512) NOT NULL UNIQUE,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(company_id,charge_id) REFERENCES charges(company_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE checkouts (
    `id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    `company_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `charge_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `stripe_session_id` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin UNIQUE,
    `payment_intent` TEXT,
    `amount_cents` BIGINT NOT NULL,
    `status` VARCHAR(255) NOT NULL DEFAULT 'CREATING',
    `url` TEXT,
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(company_id,id),
    FOREIGN KEY(company_id,charge_id) REFERENCES charges(company_id,id),
    active_charge_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin GENERATED ALWAYS AS (CASE WHEN status IN ('CREATING','OPEN','PROCESSING') THEN charge_id ELSE NULL END) STORED,
    UNIQUE(active_charge_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE webhook_events (
    `id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    `company_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin,
    `type` VARCHAR(100) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE tickets (
    `id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    `company_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `property_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `reporter_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `assignee_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin REFERENCES users(id),
    `provider_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin,
    `title` TEXT NOT NULL,
    `description` TEXT NOT NULL,
    `category` VARCHAR(255) NOT NULL DEFAULT 'OTHER',
    `priority` VARCHAR(255) NOT NULL DEFAULT 'NORMAL',
    `status` VARCHAR(255) NOT NULL DEFAULT 'NEW',
    `due_at` DATETIME,
    `scheduled_at` DATETIME,
    `budget_cents` BIGINT,
    `budget_approved` BIGINT NOT NULL DEFAULT 0,
    `final_cost_cents` BIGINT,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(company_id,id),
    FOREIGN KEY(company_id,property_id) REFERENCES properties(company_id,id),
    FOREIGN KEY(company_id,provider_id) REFERENCES contacts(company_id,id),
    FOREIGN KEY (`reporter_id`) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE ticket_notes (
    `id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    `company_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `ticket_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `author_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `body` TEXT NOT NULL,
    `internal` BIGINT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(company_id,ticket_id) REFERENCES tickets(company_id,id),
    FOREIGN KEY (`author_id`) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE expenses (
    `id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    `company_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `property_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `ticket_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin,
    `description` TEXT NOT NULL,
    `amount_cents` BIGINT NOT NULL,
    `date` DATE NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(company_id,property_id) REFERENCES properties(company_id,id),
    FOREIGN KEY(company_id,ticket_id) REFERENCES tickets(company_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE conversations (
    `id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    `company_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `property_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin,
    `subject` TEXT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(company_id,id),
    FOREIGN KEY(company_id,property_id) REFERENCES properties(company_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE participants (
    `company_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `conversation_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `user_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY(conversation_id,user_id),
    FOREIGN KEY(company_id,conversation_id) REFERENCES conversations(company_id,id),
    FOREIGN KEY (`user_id`) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE messages (
    message_sequence BIGINT NOT NULL AUTO_INCREMENT UNIQUE,
    `id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    `company_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `conversation_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `author_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `body` TEXT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(company_id,id),
    FOREIGN KEY(company_id,conversation_id) REFERENCES conversations(company_id,id),
    FOREIGN KEY (`author_id`) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE message_reads (
    `message_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `user_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `read_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(message_id,user_id),
    FOREIGN KEY (`message_id`) REFERENCES messages(id),
    FOREIGN KEY (`user_id`) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE notifications (
    `id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    `company_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `user_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `charge_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin,
    `title` TEXT NOT NULL,
    `body` TEXT NOT NULL,
    `email_status` VARCHAR(255) NOT NULL DEFAULT 'PENDING',
    `is_read` BIGINT NOT NULL DEFAULT 0,
    `dedupe_key` VARCHAR(512) NOT NULL UNIQUE,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(company_id,charge_id) REFERENCES charges(company_id,id),
    FOREIGN KEY (`company_id`) REFERENCES companies(id),
    FOREIGN KEY (`user_id`) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE jobs (
    `id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    `company_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin,
    `type` VARCHAR(100) NOT NULL,
    `payload` TEXT NOT NULL,
    `dedupe_key` VARCHAR(512) NOT NULL UNIQUE,
    `status` VARCHAR(255) NOT NULL DEFAULT 'PENDING',
    `attempts` BIGINT NOT NULL DEFAULT 0,
    `available_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `locked_at` DATETIME,
    `last_error` TEXT,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE documents (
    `id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    `company_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `entity_type` VARCHAR(100) NOT NULL,
    `entity_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `name` TEXT NOT NULL,
    `storage_path` VARCHAR(512) NOT NULL UNIQUE,
    `mime_type` VARCHAR(100) NOT NULL,
    `size` BIGINT NOT NULL,
    `author_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`company_id`) REFERENCES companies(id),
    FOREIGN KEY (`author_id`) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE devices (
    `id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    `company_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `user_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `token` TEXT NOT NULL,
    `platform` VARCHAR(100) NOT NULL,
    token_digest BINARY(32) GENERATED ALWAYS AS (UNHEX(SHA2(token,256))) STORED,
    UNIQUE(company_id,user_id,token_digest),
    FOREIGN KEY (`company_id`) REFERENCES companies(id),
    FOREIGN KEY (`user_id`) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE audit (
    `id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    `company_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `user_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `action` TEXT NOT NULL,
    `entity_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`company_id`) REFERENCES companies(id),
    FOREIGN KEY (`user_id`) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE INDEX checkouts_charge_id ON checkouts(charge_id);

CREATE INDEX idx_properties_company ON properties(company_id);

CREATE INDEX idx_contracts_property ON contracts(company_id,property_id,start_date,end_date);

CREATE INDEX idx_contacts_company ON contacts(company_id,user_id);

CREATE INDEX idx_charges_company ON charges(company_id,due_date);

CREATE INDEX idx_payments_charge ON payments(company_id,charge_id);

CREATE INDEX idx_tickets_company ON tickets(company_id,assignee_id,status);

CREATE INDEX idx_notifications_user ON notifications(company_id,user_id,is_read);

CREATE INDEX idx_jobs_pending ON jobs(status,available_at);

CREATE INDEX idx_docs_entity ON documents(company_id,entity_type,entity_id);

CREATE INDEX idx_sessions_expiry ON sessions(expires_at);

CREATE INDEX idx_memberships_user ON memberships(user_id,active);

DELIMITER $$

CREATE TRIGGER companies_check_insert BEFORE INSERT ON companies
FOR EACH ROW
BEGIN
    SET NEW.`description` = COALESCE(NEW.`description`, '');
    SET NEW.`email_template` = COALESCE(NEW.`email_template`, 'A renda de {{amount}} vence em {{date}}. Consulte o portal para pagar.');
    IF NOT ((NEW.description IS NOT NULL) AND (NEW.email_template IS NOT NULL)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid companies values';
    END IF;
END$$

CREATE TRIGGER companies_check_update BEFORE UPDATE ON companies
FOR EACH ROW
BEGIN
    IF NOT ((NEW.description IS NOT NULL) AND (NEW.email_template IS NOT NULL)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid companies values';
    END IF;
END$$

CREATE TRIGGER memberships_check_insert BEFORE INSERT ON memberships
FOR EACH ROW
BEGIN
    IF NOT ((NEW.role IN ('ADMIN','MANAGER','FINANCE','TECHNICIAN','OWNER','RESIDENT'))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid memberships values';
    END IF;
END$$

CREATE TRIGGER memberships_check_update BEFORE UPDATE ON memberships
FOR EACH ROW
BEGIN
    IF NOT ((NEW.role IN ('ADMIN','MANAGER','FINANCE','TECHNICIAN','OWNER','RESIDENT'))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid memberships values';
    END IF;
END$$

CREATE TRIGGER properties_check_insert BEFORE INSERT ON properties
FOR EACH ROW
BEGIN
    SET NEW.`description` = COALESCE(NEW.`description`, '');
    SET NEW.`photos` = COALESCE(NEW.`photos`, '[]');
    SET NEW.`amenities` = COALESCE(NEW.`amenities`, '[]');
    IF NOT ((NEW.type IN ('BUILDING','APARTMENT','ROOM','COMMON')) AND (NEW.description IS NOT NULL) AND (NEW.rent_cents>=0) AND (NEW.photos IS NOT NULL) AND (NEW.amenities IS NOT NULL)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid properties values';
    END IF;
END$$

CREATE TRIGGER properties_check_update BEFORE UPDATE ON properties
FOR EACH ROW
BEGIN
    IF NOT ((NEW.type IN ('BUILDING','APARTMENT','ROOM','COMMON')) AND (NEW.description IS NOT NULL) AND (NEW.rent_cents>=0) AND (NEW.photos IS NOT NULL) AND (NEW.amenities IS NOT NULL)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid properties values';
    END IF;
END$$

CREATE TRIGGER contacts_check_insert BEFORE INSERT ON contacts
FOR EACH ROW
BEGIN
    SET NEW.`tags` = COALESCE(NEW.`tags`, '[]');
    SET NEW.`notes` = COALESCE(NEW.`notes`, '');
    IF NOT ((NEW.tags IS NOT NULL) AND (NEW.notes IS NOT NULL)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid contacts values';
    END IF;
END$$

CREATE TRIGGER contacts_check_update BEFORE UPDATE ON contacts
FOR EACH ROW
BEGIN
    IF NOT ((NEW.tags IS NOT NULL) AND (NEW.notes IS NOT NULL)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid contacts values';
    END IF;
END$$

CREATE TRIGGER leads_check_insert BEFORE INSERT ON leads
FOR EACH ROW
BEGIN
    SET NEW.`notes` = COALESCE(NEW.`notes`, '');
    IF NOT ((NEW.notes IS NOT NULL)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid leads values';
    END IF;
END$$

CREATE TRIGGER leads_check_update BEFORE UPDATE ON leads
FOR EACH ROW
BEGIN
    IF NOT ((NEW.notes IS NOT NULL)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid leads values';
    END IF;
END$$

CREATE TRIGGER contracts_check_insert BEFORE INSERT ON contracts
FOR EACH ROW
BEGIN
    SET NEW.`notes` = COALESCE(NEW.`notes`, '');
    SET NEW.`template` = COALESCE(NEW.`template`, 'Contrato de arrendamento entre {{company}} e {{residents}}, relativo a {{property}}, com início em {{start}} e renda mensal de {{rent}}.');
    IF NOT ((NEW.rent_cents>0) AND (NEW.charges_cents>=0) AND (NEW.deposit_cents>=0) AND (NEW.due_day BETWEEN 1 AND 31) AND (NEW.status IN ('ACTIVE','ENDED','CANCELLED')) AND (NEW.notes IS NOT NULL) AND (NEW.template IS NOT NULL) AND (NEW.end_date IS NULL OR NEW.end_date>=NEW.start_date)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid contracts values';
    END IF;
END$$

CREATE TRIGGER contracts_check_update BEFORE UPDATE ON contracts
FOR EACH ROW
BEGIN
    IF NOT ((NEW.rent_cents>0) AND (NEW.charges_cents>=0) AND (NEW.deposit_cents>=0) AND (NEW.due_day BETWEEN 1 AND 31) AND (NEW.status IN ('ACTIVE','ENDED','CANCELLED')) AND (NEW.notes IS NOT NULL) AND (NEW.template IS NOT NULL) AND (NEW.end_date IS NULL OR NEW.end_date>=NEW.start_date)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid contracts values';
    END IF;
END$$

CREATE TRIGGER rent_changes_check_insert BEFORE INSERT ON rent_changes
FOR EACH ROW
BEGIN
    IF NOT ((NEW.rent_cents>0)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid rent_changes values';
    END IF;
END$$

CREATE TRIGGER rent_changes_check_update BEFORE UPDATE ON rent_changes
FOR EACH ROW
BEGIN
    IF NOT ((NEW.rent_cents>0)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid rent_changes values';
    END IF;
END$$

CREATE TRIGGER deposit_entries_check_insert BEFORE INSERT ON deposit_entries
FOR EACH ROW
BEGIN
    IF NOT ((NEW.kind IN ('RECEIVED','RETURNED','DEDUCTED')) AND (NEW.amount_cents>0)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid deposit_entries values';
    END IF;
END$$

CREATE TRIGGER deposit_entries_check_update BEFORE UPDATE ON deposit_entries
FOR EACH ROW
BEGIN
    IF NOT ((NEW.kind IN ('RECEIVED','RETURNED','DEDUCTED')) AND (NEW.amount_cents>0)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid deposit_entries values';
    END IF;
END$$

CREATE TRIGGER inspections_check_insert BEFORE INSERT ON inspections
FOR EACH ROW
BEGIN
    SET NEW.`notes` = COALESCE(NEW.`notes`, '');
    SET NEW.`items` = COALESCE(NEW.`items`, '[]');
    IF NOT ((NEW.kind IN ('ENTRY','EXIT')) AND (NEW.notes IS NOT NULL) AND (NEW.items IS NOT NULL)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid inspections values';
    END IF;
END$$

CREATE TRIGGER inspections_check_update BEFORE UPDATE ON inspections
FOR EACH ROW
BEGIN
    IF NOT ((NEW.kind IN ('ENTRY','EXIT')) AND (NEW.notes IS NOT NULL) AND (NEW.items IS NOT NULL)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid inspections values';
    END IF;
END$$

CREATE TRIGGER charges_check_insert BEFORE INSERT ON charges
FOR EACH ROW
BEGIN
    IF NOT ((NEW.amount_cents>0)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid charges values';
    END IF;
END$$

CREATE TRIGGER charges_check_update BEFORE UPDATE ON charges
FOR EACH ROW
BEGIN
    IF NOT ((NEW.amount_cents>0)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid charges values';
    END IF;
END$$

CREATE TRIGGER payments_check_insert BEFORE INSERT ON payments
FOR EACH ROW
BEGIN
    IF NOT ((NEW.amount_cents>0) AND (NEW.kind IN ('PAYMENT','REFUND','ADJUSTMENT','DISPUTE','DISPUTE_WON'))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid payments values';
    END IF;
END$$

CREATE TRIGGER payments_check_update BEFORE UPDATE ON payments
FOR EACH ROW
BEGIN
    IF NOT ((NEW.amount_cents>0) AND (NEW.kind IN ('PAYMENT','REFUND','ADJUSTMENT','DISPUTE','DISPUTE_WON'))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid payments values';
    END IF;
END$$

CREATE TRIGGER expenses_check_insert BEFORE INSERT ON expenses
FOR EACH ROW
BEGIN
    IF NOT ((NEW.amount_cents>0)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid expenses values';
    END IF;
END$$

CREATE TRIGGER expenses_check_update BEFORE UPDATE ON expenses
FOR EACH ROW
BEGIN
    IF NOT ((NEW.amount_cents>0)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid expenses values';
    END IF;
END$$

DELIMITER ;

CREATE TABLE trial_writer_lock (id INT PRIMARY KEY) ENGINE=InnoDB;
INSERT INTO trial_writer_lock (id) VALUES (1);
