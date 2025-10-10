-- Create tp_notes table if it doesn't exist
CREATE TABLE IF NOT EXISTS tp_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    title VARCHAR(255),
    content TEXT,
    type VARCHAR(50) DEFAULT 'general',
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES tp_patients(id) ON DELETE CASCADE,
    INDEX idx_patient (patient_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert sample notes for testing (optional)
-- INSERT INTO tp_notes (patient_id, title, content, type) 
-- VALUES 
-- (1, 'Erste Untersuchung', 'Patient zeigt gute Fortschritte', 'general'),
-- (1, 'Nachkontrolle', 'Heilung verläuft planmäßig', 'general');