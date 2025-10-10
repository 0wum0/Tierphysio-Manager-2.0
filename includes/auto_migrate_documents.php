<?php
/**
 * Auto-migration for document management system
 * Ensures tp_documents table exists with all required columns
 */

require_once __DIR__ . '/db.php';

function ensureDocumentsTable() {
    $pdo = get_pdo();
    
    try {
        // Check database type
        $dbType = defined('DB_TYPE') ? DB_TYPE : 'mysql';
        
        if ($dbType === 'sqlite') {
            // SQLite migration
            $sql = "
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
                )
            ";
            $pdo->exec($sql);
            
            // Create indexes
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_documents_patient_id ON tp_documents(patient_id)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_documents_created_at ON tp_documents(created_at)");
            
        } else {
            // MySQL migration
            $sql = "
                CREATE TABLE IF NOT EXISTS tp_documents (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    patient_id INT NOT NULL,
                    type VARCHAR(20),
                    title VARCHAR(255) NOT NULL,
                    file_name VARCHAR(255),
                    file_path VARCHAR(500) NOT NULL,
                    file_size INT,
                    mime_type VARCHAR(100),
                    uploaded_by INT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (patient_id) REFERENCES tp_patients(id) ON DELETE CASCADE,
                    INDEX idx_patient_id (patient_id),
                    INDEX idx_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ";
            $pdo->exec($sql);
            
            // Add missing columns if table already exists
            try {
                $pdo->exec("ALTER TABLE tp_documents ADD COLUMN IF NOT EXISTS file_name VARCHAR(255) AFTER title");
            } catch (Exception $e) {
                // Column might already exist
            }
            
            try {
                $pdo->exec("ALTER TABLE tp_documents ADD COLUMN IF NOT EXISTS mime_type VARCHAR(100) AFTER file_size");
            } catch (Exception $e) {
                // Column might already exist
            }
        }
        
        return true;
    } catch (Exception $e) {
        error_log('Documents table migration error: ' . $e->getMessage());
        return false;
    }
}

// Run migration automatically
ensureDocumentsTable();