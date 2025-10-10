-- Tierphysio Manager 2.0 - Patient tabs tables
-- This script creates tp_notes and tp_documents tables for patient records

USE tierphysio_db;

-- Create tp_notes table for patient notes and medical records
CREATE TABLE IF NOT EXISTS tp_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    type VARCHAR(20) DEFAULT 'general', -- 'general' for notes, 'medical' for records
    title VARCHAR(200),
    content TEXT NOT NULL,
    created_by INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES tp_patients(id) ON DELETE CASCADE,
    INDEX idx_patient_id (patient_id),
    INDEX idx_type (type),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create tp_documents table for uploaded files
CREATE TABLE IF NOT EXISTS tp_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    type VARCHAR(20), -- file extension
    title VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size INT,
    uploaded_by INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES tp_patients(id) ON DELETE CASCADE,
    INDEX idx_patient_id (patient_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add medical history fields to tp_patients if not exists
ALTER TABLE tp_patients 
ADD COLUMN IF NOT EXISTS medical_history TEXT,
ADD COLUMN IF NOT EXISTS allergies TEXT,
ADD COLUMN IF NOT EXISTS medications TEXT;

-- Display summary
SELECT 'Patient tabs tables created!' as Status;
SELECT COUNT(*) as 'Total tp_notes' FROM tp_notes;
SELECT COUNT(*) as 'Total tp_documents' FROM tp_documents;