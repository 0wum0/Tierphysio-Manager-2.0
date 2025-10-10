-- SQLite migration for tp_documents table
-- Adds missing columns for document management

-- Check if tp_documents table exists, create if not
CREATE TABLE IF NOT EXISTS tp_documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    patient_id INTEGER NOT NULL,
    type VARCHAR(20),
    title VARCHAR(255) NOT NULL,
    file_name VARCHAR(255),
    file_path VARCHAR(500) NOT NULL,
    file_size INTEGER,
    mime_type VARCHAR(100),
    uploaded_by INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES tp_patients(id) ON DELETE CASCADE
);

-- Create indexes
CREATE INDEX IF NOT EXISTS idx_documents_patient_id ON tp_documents(patient_id);
CREATE INDEX IF NOT EXISTS idx_documents_created_at ON tp_documents(created_at);