-- Run once against the production MySQL/MariaDB database before enabling registration.

CREATE TABLE registrations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_reference VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status_token_hash BINARY(32) NOT NULL,
    client_idempotency_hash BINARY(32) NOT NULL,
    request_fingerprint BINARY(32) NOT NULL,
    event_code VARCHAR(40) NOT NULL,
    event_name VARCHAR(180) NOT NULL,
    event_date_label VARCHAR(80) NOT NULL,
    package_code VARCHAR(60) NOT NULL,
    package_name VARCHAR(160) NOT NULL,
    package_quantity TINYINT UNSIGNED NOT NULL DEFAULT 1,
    package_unit_amount_cents INT UNSIGNED NOT NULL,
    base_amount_cents INT UNSIGNED NOT NULL,
    addon_amount_cents INT UNSIGNED NOT NULL DEFAULT 0,
    addons_json TEXT NOT NULL,
    amount_cents INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    benefit_description VARCHAR(1000) NOT NULL,
    fair_market_value_cents INT UNSIGNED NOT NULL,
    deductible_amount_cents INT UNSIGNED NOT NULL,
    participant_capacity TINYINT UNSIGNED NOT NULL DEFAULT 0,
    payer_first_name VARCHAR(80) NOT NULL,
    payer_last_name VARCHAR(80) NOT NULL,
    payer_company VARCHAR(150) NULL,
    payer_email VARCHAR(254) NOT NULL,
    payer_phone VARCHAR(20) NOT NULL,
    payer_address_line1 VARCHAR(180) NOT NULL,
    payer_city VARCHAR(100) NOT NULL,
    payer_state CHAR(2) NOT NULL,
    payer_postal_code VARCHAR(10) NOT NULL,
    team_name VARCHAR(150) NULL,
    sponsor_display VARCHAR(180) NULL,
    contest_choice VARCHAR(40) NULL,
    notes TEXT NULL,
    status VARCHAR(40) NOT NULL,
    consent_at DATETIME(6) NOT NULL,
    consent_ip_hash CHAR(64) NOT NULL,
    terms_version VARCHAR(40) NOT NULL,
    square_idempotency_key CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    square_location_id VARCHAR(192) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    square_payment_link_id VARCHAR(192) CHARACTER SET ascii COLLATE ascii_bin NULL,
    square_order_id VARCHAR(192) CHARACTER SET ascii COLLATE ascii_bin NULL,
    checkout_url VARCHAR(2048) NULL,
    checkout_error_code VARCHAR(255) NULL,
    paid_at DATETIME(6) NULL,
    refunded_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_registrations_reference (public_reference),
    UNIQUE KEY uq_registrations_status_token (status_token_hash),
    UNIQUE KEY uq_registrations_client_idempotency (client_idempotency_hash),
    UNIQUE KEY uq_registrations_idempotency (square_idempotency_key),
    UNIQUE KEY uq_registrations_square_order (square_order_id),
    KEY idx_registrations_event_status (event_code, status),
    KEY idx_registrations_created (created_at),
    CONSTRAINT chk_registrations_package_quantity CHECK (package_quantity BETWEEN 1 AND 10),
    CONSTRAINT chk_registrations_participant_capacity CHECK (participant_capacity BETWEEN 0 AND 40)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE registration_teams (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    registration_id BIGINT UNSIGNED NOT NULL,
    position TINYINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    participant_capacity TINYINT UNSIGNED NOT NULL DEFAULT 4,
    addons_json TEXT NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_registration_teams_position (registration_id, position),
    UNIQUE KEY uq_registration_teams_registration_id (registration_id, id),
    CONSTRAINT chk_registration_teams_position CHECK (position BETWEEN 1 AND 10),
    CONSTRAINT chk_registration_teams_capacity CHECK (participant_capacity BETWEEN 1 AND 4),
    CONSTRAINT fk_registration_teams_registration
        FOREIGN KEY (registration_id) REFERENCES registrations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE registration_ticket_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    registration_id BIGINT UNSIGNED NOT NULL,
    position TINYINT UNSIGNED NOT NULL,
    participant_capacity TINYINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_registration_ticket_groups_position (registration_id, position),
    UNIQUE KEY uq_registration_ticket_groups_registration_id (registration_id, id),
    CONSTRAINT chk_registration_ticket_groups_position CHECK (position BETWEEN 1 AND 10),
    CONSTRAINT chk_registration_ticket_groups_capacity CHECK (participant_capacity BETWEEN 1 AND 2),
    CONSTRAINT fk_registration_ticket_groups_registration
        FOREIGN KEY (registration_id) REFERENCES registrations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE participants (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    registration_id BIGINT UNSIGNED NOT NULL,
    team_id BIGINT UNSIGNED NULL,
    ticket_group_id BIGINT UNSIGNED NULL,
    position TINYINT UNSIGNED NOT NULL,
    team_position TINYINT UNSIGNED NULL,
    ticket_group_position TINYINT UNSIGNED NULL,
    name VARCHAR(160) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_participants_registration_position (registration_id, position),
    UNIQUE KEY uq_participants_team_position (team_id, team_position),
    UNIQUE KEY uq_participants_ticket_group_position (ticket_group_id, ticket_group_position),
    KEY idx_participants_registration_team (registration_id, team_id),
    KEY idx_participants_registration_ticket_group (registration_id, ticket_group_id),
    CONSTRAINT chk_participants_position CHECK (position BETWEEN 1 AND 40),
    CONSTRAINT chk_participants_roster_link CHECK (
        (
            team_id IS NULL AND team_position IS NULL
            AND ticket_group_id IS NULL AND ticket_group_position IS NULL
        )
        OR (
            team_id IS NOT NULL AND team_position IS NOT NULL AND team_position BETWEEN 1 AND 4
            AND ticket_group_id IS NULL AND ticket_group_position IS NULL
        )
        OR (
            team_id IS NULL AND team_position IS NULL
            AND ticket_group_id IS NOT NULL AND ticket_group_position IS NOT NULL
            AND ticket_group_position BETWEEN 1 AND 2
        )
    ),
    CONSTRAINT fk_participants_registration
        FOREIGN KEY (registration_id) REFERENCES registrations (id) ON DELETE CASCADE,
    CONSTRAINT fk_participants_registration_team
        FOREIGN KEY (registration_id, team_id) REFERENCES registration_teams (registration_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_participants_registration_ticket_group
        FOREIGN KEY (registration_id, ticket_group_id)
        REFERENCES registration_ticket_groups (registration_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    registration_id BIGINT UNSIGNED NOT NULL,
    square_payment_id VARCHAR(192) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    square_order_id VARCHAR(192) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(40) NOT NULL,
    amount_cents INT UNSIGNED NOT NULL,
    refunded_amount_cents INT UNSIGNED NOT NULL DEFAULT 0,
    currency CHAR(3) NOT NULL,
    receipt_url VARCHAR(2048) NULL,
    completed_at DATETIME(6) NULL,
    square_updated_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payments_square_payment (square_payment_id),
    KEY idx_payments_registration (registration_id),
    KEY idx_payments_square_order (square_order_id),
    CONSTRAINT fk_payments_registration
        FOREIGN KEY (registration_id) REFERENCES registrations (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE webhook_events (
    event_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_type VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    object_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    merchant_id VARCHAR(192) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    processing_status VARCHAR(20) NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME(6) NOT NULL,
    locked_at DATETIME(6) NULL,
    result VARCHAR(100) NULL,
    last_error VARCHAR(255) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    processed_at DATETIME(6) NULL,
    PRIMARY KEY (event_id),
    KEY idx_webhook_events_delivery (processing_status, available_at),
    KEY idx_webhook_events_locked (processing_status, locked_at)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE email_outbox (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    registration_id BIGINT UNSIGNED NOT NULL,
    message_type VARCHAR(60) NOT NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    recipient_email VARCHAR(254) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    text_body MEDIUMTEXT NOT NULL,
    html_body MEDIUMTEXT NOT NULL,
    status VARCHAR(20) NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME(6) NOT NULL,
    locked_at DATETIME(6) NULL,
    sent_at DATETIME(6) NULL,
    scrubbed_at DATETIME(6) NULL,
    last_error VARCHAR(1000) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email_outbox_idempotency (idempotency_key),
    KEY idx_email_outbox_delivery (status, available_at),
    KEY idx_email_outbox_scrub (status, scrubbed_at, sent_at),
    CONSTRAINT fk_email_outbox_registration
        FOREIGN KEY (registration_id) REFERENCES registrations (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE rate_limits (
    action VARCHAR(60) NOT NULL,
    key_hash CHAR(64) NOT NULL,
    window_started_at DATETIME(6) NOT NULL,
    hit_count INT UNSIGNED NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (action, key_hash),
    KEY idx_rate_limits_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARACTER SET ascii COLLATE ascii_general_ci;
