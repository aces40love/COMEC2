-- Apply after 001_event_registrations.sql on an already-installed database.
-- Existing registrations retain their original benefit/FMV snapshots. New
-- registrations explicitly persist the mode selected by the application.
-- The guarded DDL makes this migration safe to resume or re-run.

SET @comec_schema_name = DATABASE();

SET @comec_has_disclosure_mode = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @comec_schema_name
      AND TABLE_NAME = 'registrations'
      AND COLUMN_NAME = 'disclosure_mode'
);
SET @comec_add_disclosure_mode = IF(
    @comec_has_disclosure_mode = 0,
    'ALTER TABLE registrations ADD COLUMN disclosure_mode VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER currency',
    'SELECT 1'
);
PREPARE comec_statement FROM @comec_add_disclosure_mode;
EXECUTE comec_statement;
DEALLOCATE PREPARE comec_statement;

-- Rows created before this migration already contain the complete legacy tax
-- snapshot, so they remain in benefit_fmv mode without changing their values.
UPDATE registrations
SET disclosure_mode = 'benefit_fmv'
WHERE disclosure_mode IS NULL;

ALTER TABLE registrations
    MODIFY disclosure_mode VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin
        NOT NULL DEFAULT 'payment_confirmation_only',
    MODIFY benefit_description VARCHAR(1000) NULL,
    MODIFY fair_market_value_cents INT UNSIGNED NULL,
    MODIFY deductible_amount_cents INT UNSIGNED NULL;

SET @comec_has_mode_check = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = @comec_schema_name
      AND TABLE_NAME = 'registrations'
      AND CONSTRAINT_NAME = 'chk_registrations_disclosure_mode'
);
SET @comec_add_mode_check = IF(
    @comec_has_mode_check = 0,
    'ALTER TABLE registrations ADD CONSTRAINT chk_registrations_disclosure_mode CHECK (disclosure_mode IN (''payment_confirmation_only'', ''benefit_fmv''))',
    'SELECT 1'
);
PREPARE comec_statement FROM @comec_add_mode_check;
EXECUTE comec_statement;
DEALLOCATE PREPARE comec_statement;

SET @comec_has_values_check = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = @comec_schema_name
      AND TABLE_NAME = 'registrations'
      AND CONSTRAINT_NAME = 'chk_registrations_disclosure_values'
);
SET @comec_add_values_check = IF(
    @comec_has_values_check = 0,
    'ALTER TABLE registrations ADD CONSTRAINT chk_registrations_disclosure_values CHECK ((disclosure_mode = ''payment_confirmation_only'' AND benefit_description IS NULL AND fair_market_value_cents IS NULL AND deductible_amount_cents IS NULL) OR (disclosure_mode = ''benefit_fmv'' AND benefit_description IS NOT NULL AND fair_market_value_cents IS NOT NULL AND deductible_amount_cents IS NOT NULL))',
    'SELECT 1'
);
PREPARE comec_statement FROM @comec_add_values_check;
EXECUTE comec_statement;
DEALLOCATE PREPARE comec_statement;
