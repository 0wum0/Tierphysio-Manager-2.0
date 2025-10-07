-- Tierphysio Manager 2.0 - Initial Database Schema
-- Version: 2.0.0
-- Author: TierphysioManager Team

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

-- --------------------------------------------------------
-- Table structure for users
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `tp_users` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL UNIQUE,
  `email` varchar(100) NOT NULL UNIQUE,
  `password` varchar(255) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `role` enum('admin','employee','guest') NOT NULL DEFAULT 'employee',
  `avatar` varchar(255) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login` datetime DEFAULT NULL,
  `login_attempts` int(11) DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `language` varchar(5) DEFAULT 'de',
  `theme` varchar(10) DEFAULT 'light',
  `two_factor_enabled` tinyint(1) DEFAULT 0,
  `two_factor_secret` varchar(255) DEFAULT NULL,
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_token_expires` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`),
  KEY `idx_username` (`username`),
  KEY `idx_role` (`role`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for owners
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `tp_owners` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_number` varchar(20) NOT NULL UNIQUE,
  `salutation` enum('Herr','Frau','Divers','Firma') DEFAULT 'Herr',
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `company` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `mobile` varchar(30) DEFAULT NULL,
  `street` varchar(100) DEFAULT NULL,
  `house_number` varchar(10) DEFAULT NULL,
  `postal_code` varchar(10) DEFAULT NULL,
  `city` varchar(50) DEFAULT NULL,
  `country` varchar(50) DEFAULT 'Deutschland',
  `notes` text DEFAULT NULL,
  `newsletter` tinyint(1) DEFAULT 0,
  `invoice_email` varchar(100) DEFAULT NULL,
  `payment_method` enum('cash','transfer','card','paypal','sepa') DEFAULT 'transfer',
  `iban` varchar(34) DEFAULT NULL,
  `bic` varchar(11) DEFAULT NULL,
  `tax_number` varchar(50) DEFAULT NULL,
  `created_by` int(11) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_customer_number` (`customer_number`),
  KEY `idx_name` (`last_name`, `first_name`),
  KEY `idx_email` (`email`),
  KEY `idx_created_by` (`created_by`),
  CONSTRAINT `fk_owner_created_by` FOREIGN KEY (`created_by`) REFERENCES `tp_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for patients
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `tp_patients` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `patient_number` varchar(20) NOT NULL UNIQUE,
  `owner_id` int(11) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `species` enum('dog','cat','horse','rabbit','bird','reptile','other') NOT NULL,
  `breed` varchar(100) DEFAULT NULL,
  `color` varchar(50) DEFAULT NULL,
  `gender` enum('male','female','neutered_male','spayed_female','unknown') DEFAULT 'unknown',
  `birth_date` date DEFAULT NULL,
  `weight` decimal(6,2) DEFAULT NULL,
  `microchip` varchar(50) DEFAULT NULL,
  `insurance_name` varchar(100) DEFAULT NULL,
  `insurance_number` varchar(50) DEFAULT NULL,
  `veterinarian` varchar(100) DEFAULT NULL,
  `veterinarian_phone` varchar(30) DEFAULT NULL,
  `medical_history` text DEFAULT NULL,
  `allergies` text DEFAULT NULL,
  `medications` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `deceased_date` date DEFAULT NULL,
  `created_by` int(11) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_patient_number` (`patient_number`),
  KEY `idx_owner` (`owner_id`),
  KEY `idx_name` (`name`),
  KEY `idx_species` (`species`),
  KEY `idx_active` (`is_active`),
  KEY `idx_created_by` (`created_by`),
  CONSTRAINT `fk_patient_owner` FOREIGN KEY (`owner_id`) REFERENCES `tp_owners` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_patient_created_by` FOREIGN KEY (`created_by`) REFERENCES `tp_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for appointments
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `tp_appointments` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `patient_id` int(11) UNSIGNED NOT NULL,
  `therapist_id` int(11) UNSIGNED NOT NULL,
  `appointment_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `type` enum('initial','followup','control','emergency','home_visit') DEFAULT 'followup',
  `status` enum('scheduled','confirmed','in_progress','completed','cancelled','no_show') DEFAULT 'scheduled',
  `treatment_type` varchar(100) DEFAULT NULL,
  `room` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `reminder_sent` tinyint(1) DEFAULT 0,
  `reminder_sent_at` datetime DEFAULT NULL,
  `cancelled_reason` text DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by` int(11) UNSIGNED DEFAULT NULL,
  `created_by` int(11) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_patient` (`patient_id`),
  KEY `idx_therapist` (`therapist_id`),
  KEY `idx_date` (`appointment_date`),
  KEY `idx_datetime` (`appointment_date`, `start_time`),
  KEY `idx_status` (`status`),
  KEY `idx_created_by` (`created_by`),
  CONSTRAINT `fk_appointment_patient` FOREIGN KEY (`patient_id`) REFERENCES `tp_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_appointment_therapist` FOREIGN KEY (`therapist_id`) REFERENCES `tp_users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_appointment_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `tp_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_appointment_created_by` FOREIGN KEY (`created_by`) REFERENCES `tp_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for treatments
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `tp_treatments` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `appointment_id` int(11) UNSIGNED DEFAULT NULL,
  `patient_id` int(11) UNSIGNED NOT NULL,
  `therapist_id` int(11) UNSIGNED NOT NULL,
  `treatment_date` date NOT NULL,
  `duration_minutes` int(11) NOT NULL DEFAULT 30,
  `diagnosis` text DEFAULT NULL,
  `symptoms` text DEFAULT NULL,
  `treatment_goals` text DEFAULT NULL,
  `treatment_methods` text DEFAULT NULL,
  `exercises_homework` text DEFAULT NULL,
  `progress_notes` text DEFAULT NULL,
  `next_steps` text DEFAULT NULL,
  `pain_level_before` int(2) DEFAULT NULL CHECK (pain_level_before >= 0 AND pain_level_before <= 10),
  `pain_level_after` int(2) DEFAULT NULL CHECK (pain_level_after >= 0 AND pain_level_after <= 10),
  `attachments` text DEFAULT NULL,
  `created_by` int(11) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_appointment` (`appointment_id`),
  KEY `idx_patient` (`patient_id`),
  KEY `idx_therapist` (`therapist_id`),
  KEY `idx_date` (`treatment_date`),
  KEY `idx_created_by` (`created_by`),
  CONSTRAINT `fk_treatment_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `tp_appointments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_treatment_patient` FOREIGN KEY (`patient_id`) REFERENCES `tp_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_treatment_therapist` FOREIGN KEY (`therapist_id`) REFERENCES `tp_users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_treatment_created_by` FOREIGN KEY (`created_by`) REFERENCES `tp_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for invoices
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `tp_invoices` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_number` varchar(20) NOT NULL UNIQUE,
  `owner_id` int(11) UNSIGNED NOT NULL,
  `patient_id` int(11) UNSIGNED DEFAULT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date NOT NULL,
  `status` enum('draft','sent','paid','partially_paid','overdue','cancelled') DEFAULT 'draft',
  `payment_method` enum('cash','transfer','card','paypal','sepa') DEFAULT NULL,
  `payment_date` date DEFAULT NULL,
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tax_rate` decimal(5,2) DEFAULT 19.00,
  `tax_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount_percent` decimal(5,2) DEFAULT 0.00,
  `discount_amount` decimal(10,2) DEFAULT 0.00,
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(10,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `internal_notes` text DEFAULT NULL,
  `reminder_count` int(11) DEFAULT 0,
  `last_reminder_date` date DEFAULT NULL,
  `pdf_path` varchar(255) DEFAULT NULL,
  `created_by` int(11) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_invoice_number` (`invoice_number`),
  KEY `idx_owner` (`owner_id`),
  KEY `idx_patient` (`patient_id`),
  KEY `idx_date` (`invoice_date`),
  KEY `idx_due_date` (`due_date`),
  KEY `idx_status` (`status`),
  KEY `idx_created_by` (`created_by`),
  CONSTRAINT `fk_invoice_owner` FOREIGN KEY (`owner_id`) REFERENCES `tp_owners` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_invoice_patient` FOREIGN KEY (`patient_id`) REFERENCES `tp_patients` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_invoice_created_by` FOREIGN KEY (`created_by`) REFERENCES `tp_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for invoice_items
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `tp_invoice_items` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_id` int(11) UNSIGNED NOT NULL,
  `treatment_id` int(11) UNSIGNED DEFAULT NULL,
  `description` varchar(255) NOT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT 1.00,
  `unit` varchar(20) DEFAULT 'Stück',
  `price` decimal(10,2) NOT NULL,
  `tax_rate` decimal(5,2) DEFAULT 19.00,
  `discount_percent` decimal(5,2) DEFAULT 0.00,
  `total` decimal(10,2) NOT NULL,
  `position` int(11) DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_invoice` (`invoice_id`),
  KEY `idx_treatment` (`treatment_id`),
  CONSTRAINT `fk_invoice_item_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `tp_invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_invoice_item_treatment` FOREIGN KEY (`treatment_id`) REFERENCES `tp_treatments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for notes
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `tp_notes` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `patient_id` int(11) UNSIGNED DEFAULT NULL,
  `appointment_id` int(11) UNSIGNED DEFAULT NULL,
  `treatment_id` int(11) UNSIGNED DEFAULT NULL,
  `owner_id` int(11) UNSIGNED DEFAULT NULL,
  `type` enum('general','medical','billing','reminder','important') DEFAULT 'general',
  `title` varchar(255) DEFAULT NULL,
  `content` text NOT NULL,
  `is_pinned` tinyint(1) DEFAULT 0,
  `is_private` tinyint(1) DEFAULT 0,
  `reminder_date` datetime DEFAULT NULL,
  `attachments` text DEFAULT NULL,
  `created_by` int(11) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_patient` (`patient_id`),
  KEY `idx_appointment` (`appointment_id`),
  KEY `idx_treatment` (`treatment_id`),
  KEY `idx_owner` (`owner_id`),
  KEY `idx_type` (`type`),
  KEY `idx_pinned` (`is_pinned`),
  KEY `idx_reminder` (`reminder_date`),
  KEY `idx_created_by` (`created_by`),
  CONSTRAINT `fk_note_patient` FOREIGN KEY (`patient_id`) REFERENCES `tp_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_note_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `tp_appointments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_note_treatment` FOREIGN KEY (`treatment_id`) REFERENCES `tp_treatments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_note_owner` FOREIGN KEY (`owner_id`) REFERENCES `tp_owners` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_note_created_by` FOREIGN KEY (`created_by`) REFERENCES `tp_users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for documents
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `tp_documents` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `patient_id` int(11) UNSIGNED DEFAULT NULL,
  `owner_id` int(11) UNSIGNED DEFAULT NULL,
  `treatment_id` int(11) UNSIGNED DEFAULT NULL,
  `type` enum('image','pdf','document','report','xray','lab','other') DEFAULT 'document',
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` int(11) DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `uploaded_by` int(11) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_patient` (`patient_id`),
  KEY `idx_owner` (`owner_id`),
  KEY `idx_treatment` (`treatment_id`),
  KEY `idx_type` (`type`),
  KEY `idx_uploaded_by` (`uploaded_by`),
  CONSTRAINT `fk_document_patient` FOREIGN KEY (`patient_id`) REFERENCES `tp_patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_document_owner` FOREIGN KEY (`owner_id`) REFERENCES `tp_owners` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_document_treatment` FOREIGN KEY (`treatment_id`) REFERENCES `tp_treatments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_document_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `tp_users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for settings
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `tp_settings` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `category` varchar(50) NOT NULL,
  `key` varchar(100) NOT NULL,
  `value` text DEFAULT NULL,
  `type` enum('string','number','boolean','json','array') DEFAULT 'string',
  `description` text DEFAULT NULL,
  `is_system` tinyint(1) DEFAULT 0,
  `updated_by` int(11) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_category_key` (`category`, `key`),
  KEY `idx_category` (`category`),
  KEY `idx_updated_by` (`updated_by`),
  CONSTRAINT `fk_setting_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `tp_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for activity_log
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `tp_activity_log` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(11) UNSIGNED DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` int(11) UNSIGNED DEFAULT NULL,
  `old_values` text DEFAULT NULL,
  `new_values` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_entity` (`entity_type`, `entity_id`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `fk_activity_user` FOREIGN KEY (`user_id`) REFERENCES `tp_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for sessions
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `tp_sessions` (
  `id` varchar(128) NOT NULL,
  `user_id` int(11) UNSIGNED DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` text NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_last_activity` (`last_activity`),
  CONSTRAINT `fk_session_user` FOREIGN KEY (`user_id`) REFERENCES `tp_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for migrations
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `tp_migrations` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL,
  `name` varchar(255) NOT NULL,
  `executed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_version` (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Insert initial settings
-- --------------------------------------------------------

INSERT INTO `tp_settings` (`category`, `key`, `value`, `type`, `description`, `is_system`) VALUES
('general', 'practice_name', 'Tierphysiotherapie Praxis', 'string', 'Name der Praxis', 0),
('general', 'practice_email', 'info@tierphysio.de', 'string', 'E-Mail-Adresse der Praxis', 0),
('general', 'practice_phone', '+49 123 456789', 'string', 'Telefonnummer der Praxis', 0),
('general', 'practice_address', 'Musterstraße 1, 12345 Musterstadt', 'string', 'Adresse der Praxis', 0),
('general', 'practice_website', 'https://www.tierphysio.de', 'string', 'Website der Praxis', 0),
('general', 'practice_logo', NULL, 'string', 'Logo der Praxis', 0),
('general', 'currency', 'EUR', 'string', 'Währung', 1),
('general', 'currency_symbol', '€', 'string', 'Währungssymbol', 1),
('general', 'date_format', 'd.m.Y', 'string', 'Datumsformat', 1),
('general', 'time_format', 'H:i', 'string', 'Zeitformat', 1),
('general', 'timezone', 'Europe/Berlin', 'string', 'Zeitzone', 1),
('general', 'language', 'de', 'string', 'Standardsprache', 1),
('invoice', 'next_invoice_number', '10001', 'string', 'Nächste Rechnungsnummer', 1),
('invoice', 'invoice_prefix', 'RE-', 'string', 'Rechnungspräfix', 0),
('invoice', 'invoice_tax_rate', '19', 'number', 'Standard-Steuersatz', 0),
('invoice', 'invoice_payment_terms', '14', 'number', 'Zahlungsziel in Tagen', 0),
('invoice', 'invoice_footer', 'Vielen Dank für Ihr Vertrauen!', 'string', 'Rechnungsfußzeile', 0),
('appointments', 'appointment_duration', '30', 'number', 'Standard-Termindauer in Minuten', 0),
('appointments', 'appointment_buffer', '15', 'number', 'Pufferzeit zwischen Terminen in Minuten', 0),
('appointments', 'working_hours_start', '08:00', 'string', 'Arbeitsbeginn', 0),
('appointments', 'working_hours_end', '18:00', 'string', 'Arbeitsende', 0),
('appointments', 'working_days', '["monday","tuesday","wednesday","thursday","friday"]', 'json', 'Arbeitstage', 0),
('notifications', 'email_notifications', '1', 'boolean', 'E-Mail-Benachrichtigungen aktiviert', 0),
('notifications', 'appointment_reminder', '1', 'boolean', 'Terminerinnerungen aktiviert', 0),
('notifications', 'reminder_hours_before', '24', 'number', 'Erinnerung Stunden vor Termin', 0),
('security', 'password_min_length', '8', 'number', 'Minimale Passwortlänge', 1),
('security', 'max_login_attempts', '5', 'number', 'Maximale Login-Versuche', 1),
('security', 'lockout_duration', '15', 'number', 'Sperrzeit in Minuten', 1),
('security', 'session_lifetime', '60', 'number', 'Session-Lebensdauer in Minuten', 1),
('security', 'two_factor_auth', '0', 'boolean', 'Zwei-Faktor-Authentifizierung', 0),
('backup', 'auto_backup', '0', 'boolean', 'Automatische Backups aktiviert', 0),
('backup', 'backup_schedule', 'daily', 'string', 'Backup-Zeitplan', 0),
('backup', 'backup_retention', '30', 'number', 'Backup-Aufbewahrung in Tagen', 0);

-- --------------------------------------------------------
-- Insert migration record
-- --------------------------------------------------------

INSERT INTO `tp_migrations` (`version`, `name`) VALUES ('001', 'initial_schema');

COMMIT;