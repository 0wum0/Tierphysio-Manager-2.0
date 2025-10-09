-- Admin Panel Migration
-- Idempotent schema creation for Tierphysio Manager 2.0 Admin Panel
-- Date: 2025-10-09

-- Roles table
CREATE TABLE IF NOT EXISTS tp_roles (
    id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(50) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissions table
CREATE TABLE IF NOT EXISTS tp_permissions (
    id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `key` VARCHAR(100) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Role-Permission mapping
CREATE TABLE IF NOT EXISTS tp_role_permissions (
    role_id INT(11) UNSIGNED NOT NULL,
    permission_id INT(11) UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    KEY fk_permission_id (permission_id),
    CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES tp_roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES tp_permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User-Role mapping
CREATE TABLE IF NOT EXISTS tp_user_roles (
    user_id INT(11) UNSIGNED NOT NULL,
    role_id INT(11) UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, role_id),
    KEY fk_role_id (role_id),
    CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES tp_users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES tp_roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email templates
CREATE TABLE IF NOT EXISTS tp_email_templates (
    id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `key` VARCHAR(100) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body_html TEXT,
    body_text TEXT,
    is_active TINYINT(1) DEFAULT 1,
    updated_by INT(11) UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_key (`key`),
    KEY fk_updated_by (updated_by),
    CONSTRAINT fk_et_user FOREIGN KEY (updated_by) REFERENCES tp_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cron jobs
CREATE TABLE IF NOT EXISTS tp_cron_jobs (
    id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `key` VARCHAR(100) NOT NULL,
    expression VARCHAR(100) DEFAULT NULL,
    last_run TIMESTAMP NULL DEFAULT NULL,
    last_result VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cron logs
CREATE TABLE IF NOT EXISTS tp_cron_logs (
    id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id INT(11) UNSIGNED NOT NULL,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    finished_at TIMESTAMP NULL DEFAULT NULL,
    status VARCHAR(20) DEFAULT 'running',
    message TEXT,
    PRIMARY KEY (id),
    KEY fk_job_id (job_id),
    KEY idx_started_at (started_at),
    CONSTRAINT fk_cl_job FOREIGN KEY (job_id) REFERENCES tp_cron_jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backups
CREATE TABLE IF NOT EXISTS tp_backups (
    id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    file_name VARCHAR(255) NOT NULL,
    size_bytes BIGINT UNSIGNED DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT(11) UNSIGNED DEFAULT NULL,
    PRIMARY KEY (id),
    KEY fk_created_by (created_by),
    KEY idx_created_at (created_at),
    CONSTRAINT fk_b_user FOREIGN KEY (created_by) REFERENCES tp_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Modules
CREATE TABLE IF NOT EXISTS tp_modules (
    id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `key` VARCHAR(100) NOT NULL,
    name VARCHAR(255) NOT NULL,
    version VARCHAR(20) DEFAULT NULL,
    enabled TINYINT(1) DEFAULT 0,
    config JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Finance items
CREATE TABLE IF NOT EXISTS tp_finance_items (
    id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Invoice design (singleton)
CREATE TABLE IF NOT EXISTS tp_invoice_design (
    id INT(11) UNSIGNED NOT NULL DEFAULT 1,
    logo_path VARCHAR(255) DEFAULT NULL,
    color_primary VARCHAR(7) DEFAULT '#9b5de5',
    color_accent VARCHAR(7) DEFAULT '#7C4DFF',
    header_text TEXT,
    footer_text TEXT,
    updated_by INT(11) UNSIGNED DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_id_user FOREIGN KEY (updated_by) REFERENCES tp_users(id) ON DELETE SET NULL,
    CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrations tracking table
CREATE TABLE IF NOT EXISTS tp_migrations (
    id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    migration VARCHAR(255) NOT NULL,
    executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_migration (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed Data
-- Insert roles
INSERT IGNORE INTO tp_roles (name, description) VALUES
('admin', 'Administrator mit vollständigen Berechtigungen'),
('therapist', 'Therapeut mit eingeschränkten Berechtigungen'),
('finance', 'Finanzverwaltung und Rechnungswesen');

-- Insert permissions
INSERT IGNORE INTO tp_permissions (`key`, description) VALUES
('admin.*', 'Vollzugriff auf alle Admin-Funktionen'),
('settings.view', 'Einstellungen anzeigen'),
('settings.update', 'Einstellungen ändern'),
('users.manage', 'Benutzer verwalten'),
('email.manage', 'E-Mail-Einstellungen verwalten'),
('cron.manage', 'Cron-Jobs verwalten'),
('backup.manage', 'Backups verwalten'),
('update.manage', 'System-Updates durchführen'),
('finance.manage', 'Finanzen verwalten'),
('invoice.design', 'Rechnungsdesign anpassen'),
('modules.manage', 'Module verwalten');

-- Bind admin role to admin.* permission
INSERT IGNORE INTO tp_role_permissions (role_id, permission_id)
SELECT r.id, p.id 
FROM tp_roles r, tp_permissions p 
WHERE r.name = 'admin' AND p.`key` = 'admin.*';

-- Bind therapist role to specific permissions
INSERT IGNORE INTO tp_role_permissions (role_id, permission_id)
SELECT r.id, p.id 
FROM tp_roles r, tp_permissions p 
WHERE r.name = 'therapist' 
AND p.`key` IN ('settings.view', 'invoice.design');

-- Bind finance role to finance permissions
INSERT IGNORE INTO tp_role_permissions (role_id, permission_id)
SELECT r.id, p.id 
FROM tp_roles r, tp_permissions p 
WHERE r.name = 'finance' 
AND p.`key` IN ('finance.manage', 'invoice.design', 'settings.view');

-- Assign admin role to existing admin users (users with role='admin' in tp_users)
INSERT IGNORE INTO tp_user_roles (user_id, role_id)
SELECT u.id, r.id 
FROM tp_users u, tp_roles r 
WHERE u.role = 'admin' AND r.name = 'admin';

-- Insert default email templates
INSERT IGNORE INTO tp_email_templates (`key`, subject, body_html, body_text) VALUES
('appointment_reminder', 'Terminerinnerung: {{appointment_date}}', 
 '<h2>Guten Tag {{patient_name}},</h2><p>Dies ist eine Erinnerung an Ihren Termin am {{appointment_date}} um {{appointment_time}}.</p><p>Mit freundlichen Grüßen<br>Ihr Praxis-Team</p>',
 'Guten Tag {{patient_name}},\n\nDies ist eine Erinnerung an Ihren Termin am {{appointment_date}} um {{appointment_time}}.\n\nMit freundlichen Grüßen\nIhr Praxis-Team'),
('birthday_greeting', 'Herzlichen Glückwunsch zum Geburtstag!',
 '<h2>Liebe/r {{patient_name}},</h2><p>Wir wünschen Ihnen alles Gute zum Geburtstag!</p><p>Ihr Praxis-Team</p>',
 'Liebe/r {{patient_name}},\n\nWir wünschen Ihnen alles Gute zum Geburtstag!\n\nIhr Praxis-Team'),
('invoice_sent', 'Ihre Rechnung Nr. {{invoice_number}}',
 '<h2>Guten Tag {{patient_name}},</h2><p>anbei erhalten Sie Ihre Rechnung Nr. {{invoice_number}} über {{invoice_amount}} €.</p><p>Mit freundlichen Grüßen<br>Ihr Praxis-Team</p>',
 'Guten Tag {{patient_name}},\n\nanbei erhalten Sie Ihre Rechnung Nr. {{invoice_number}} über {{invoice_amount}} €.\n\nMit freundlichen Grüßen\nIhr Praxis-Team'),
('welcome', 'Willkommen in unserer Praxis',
 '<h2>Herzlich Willkommen {{patient_name}},</h2><p>Wir freuen uns, Sie in unserer Praxis begrüßen zu dürfen.</p><p>Ihr Praxis-Team</p>',
 'Herzlich Willkommen {{patient_name}},\n\nWir freuen uns, Sie in unserer Praxis begrüßen zu dürfen.\n\nIhr Praxis-Team');

-- Insert default cron jobs
INSERT IGNORE INTO tp_cron_jobs (`key`, expression, is_active) VALUES
('appointment_reminders', '0 9 * * *', 1),
('birthday_greetings', '0 8 * * *', 1),
('backup_database', '0 2 * * *', 1),
('cleanup_logs', '0 3 * * 0', 1);

-- Insert default invoice design
INSERT IGNORE INTO tp_invoice_design (id, color_primary, color_accent, header_text, footer_text) VALUES
(1, '#9b5de5', '#7C4DFF', 
 'Tierphysiotherapie Praxis\nMusterstraße 123\n12345 Musterstadt',
 'Bankverbindung: Musterbank | IBAN: DE12 3456 7890 1234 5678 90 | BIC: MUSTDEFF');

-- Record this migration
INSERT IGNORE INTO tp_migrations (migration) VALUES ('2025_10_09_admin_panel.sql');