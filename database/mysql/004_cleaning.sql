CREATE TABLE IF NOT EXISTS cleaning_plans (
    id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    company_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    property_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    assignee_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE,
    interval_days INT NOT NULL,
    visit_time CHAR(5) NOT NULL,
    INDEX cleaning_plans_company (company_id, assignee_id),
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (property_id) REFERENCES properties(id),
    FOREIGN KEY (assignee_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS cleaning_visits (
    id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    plan_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    occurrence_date DATE NOT NULL,
    scheduled_date DATE NOT NULL,
    completed_at DATETIME,
    completed_by VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin,
    UNIQUE (plan_id, occurrence_date),
    INDEX cleaning_visits_agenda (plan_id, scheduled_date),
    FOREIGN KEY (plan_id) REFERENCES cleaning_plans(id),
    FOREIGN KEY (completed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
