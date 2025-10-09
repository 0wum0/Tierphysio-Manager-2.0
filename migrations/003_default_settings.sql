-- Default settings for TierPhysio Manager 2.0

-- General settings
INSERT INTO `tp_settings` (`category`, `key`, `value`, `type`, `description`, `is_system`) VALUES
('general', 'practice_name', 'TierPhysio Praxis', 'string', 'Name der Praxis', 0),
('general', 'practice_email', 'info@tierphysio-praxis.de', 'string', 'E-Mail-Adresse der Praxis', 0),
('general', 'practice_phone', '+49 123 456789', 'string', 'Telefonnummer der Praxis', 0),
('general', 'practice_address', 'Musterstraße 1, 12345 Musterstadt', 'string', 'Adresse der Praxis', 0),
('general', 'practice_website', 'https://www.tierphysio-praxis.de', 'string', 'Website der Praxis', 0),
('general', 'practice_logo', '', 'string', 'Logo-Pfad', 0),
('general', 'currency', 'EUR', 'string', 'Währung', 0),
('general', 'currency_symbol', '€', 'string', 'Währungssymbol', 0),
('general', 'language', 'de', 'string', 'Sprache', 0),
('general', 'timezone', 'Europe/Berlin', 'string', 'Zeitzone', 0)
ON DUPLICATE KEY UPDATE 
    value = VALUES(value),
    description = VALUES(description);

-- Theme settings
INSERT INTO `tp_settings` (`category`, `key`, `value`, `type`, `description`, `is_system`) VALUES
('theme', 'primaryColor', '#9b5de5', 'string', 'Primärfarbe', 0),
('theme', 'secondaryColor', '#7C4DFF', 'string', 'Sekundärfarbe', 0),
('theme', 'accentColor', '#6c63ff', 'string', 'Akzentfarbe', 0),
('theme', 'gradientStyle', 'lilac', 'string', 'Gradient-Stil', 0)
ON DUPLICATE KEY UPDATE 
    value = VALUES(value),
    description = VALUES(description);

-- System settings (read-only)
INSERT INTO `tp_settings` (`category`, `key`, `value`, `type`, `description`, `is_system`) VALUES
('system', 'version', '2.0.0', 'string', 'System-Version', 1),
('system', 'last_backup', NULL, 'string', 'Letztes Backup', 0),
('system', 'maintenance_mode', '0', 'boolean', 'Wartungsmodus', 1),
('system', 'max_upload_size', '10485760', 'number', 'Maximale Upload-Größe in Bytes', 1),
('system', 'session_lifetime', '1440', 'number', 'Session-Lebensdauer in Minuten', 1)
ON DUPLICATE KEY UPDATE 
    value = VALUES(value),
    description = VALUES(description),
    is_system = VALUES(is_system);