<?php
/**
 * Install script for patient tabs feature
 * Run this once to create necessary tables
 */

require_once __DIR__ . '/../includes/db.php';

try {
    $pdo = get_pdo();
    
    // Create tp_notes table for patient notes and medical records
    $sql1 = "CREATE TABLE IF NOT EXISTS tp_notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        patient_id INT NOT NULL,
        type VARCHAR(20) DEFAULT 'general',
        title VARCHAR(200),
        content TEXT NOT NULL,
        created_by INT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (patient_id) REFERENCES tp_patients(id) ON DELETE CASCADE,
        INDEX idx_patient_id (patient_id),
        INDEX idx_type (type),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $pdo->exec($sql1);
    echo "tp_notes table created/verified.\n";
    
    // Create tp_documents table for uploaded files
    $sql2 = "CREATE TABLE IF NOT EXISTS tp_documents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        patient_id INT NOT NULL,
        type VARCHAR(20),
        title VARCHAR(255) NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        file_size INT,
        uploaded_by INT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (patient_id) REFERENCES tp_patients(id) ON DELETE CASCADE,
        INDEX idx_patient_id (patient_id),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $pdo->exec($sql2);
    echo "tp_documents table created/verified.\n";
    
    // Add medical history fields to tp_patients if not exists
    $columns = $pdo->query("SHOW COLUMNS FROM tp_patients")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('medical_history', $columns)) {
        $pdo->exec("ALTER TABLE tp_patients ADD COLUMN medical_history TEXT");
        echo "Added medical_history column to tp_patients.\n";
    }
    
    if (!in_array('allergies', $columns)) {
        $pdo->exec("ALTER TABLE tp_patients ADD COLUMN allergies TEXT");
        echo "Added allergies column to tp_patients.\n";
    }
    
    if (!in_array('medications', $columns)) {
        $pdo->exec("ALTER TABLE tp_patients ADD COLUMN medications TEXT");
        echo "Added medications column to tp_patients.\n";
    }
    
    // Show summary
    $count_notes = $pdo->query("SELECT COUNT(*) FROM tp_notes")->fetchColumn();
    $count_docs = $pdo->query("SELECT COUNT(*) FROM tp_documents")->fetchColumn();
    
    echo "\n=== Installation Complete ===\n";
    echo "Total notes: $count_notes\n";
    echo "Total documents: $count_docs\n";
    echo "\nPatient tabs feature is ready to use!\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>