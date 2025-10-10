-- MySQL migration to add missing columns to tp_documents table
-- Ensures compatibility with document upload functionality

-- Add missing columns if they don't exist
ALTER TABLE tp_documents 
ADD COLUMN IF NOT EXISTS file_name VARCHAR(255) AFTER title,
ADD COLUMN IF NOT EXISTS mime_type VARCHAR(100) AFTER file_size;

-- Update any NULL values with defaults
UPDATE tp_documents 
SET file_name = SUBSTRING_INDEX(file_path, '/', -1) 
WHERE file_name IS NULL;

UPDATE tp_documents 
SET mime_type = 'application/octet-stream' 
WHERE mime_type IS NULL;