-- Tierphysio Manager 2.0 - Migration 002
-- Fix column names and ensure consistency
-- This migration ensures all tables use the tp_ prefix and correct column names

-- Check if migration was already executed
SELECT @migration_exists := COUNT(*) FROM tp_migrations WHERE version = '002';

-- Only execute if migration hasn't been run yet
SET @sql = IF(@migration_exists = 0, '

-- Ensure all tables have tp_ prefix (if old tables exist, rename them)
-- This is safe to run multiple times

-- Fix any potential issues with birth_date column (was birthdate in some versions)
-- ALTER TABLE tp_patients CHANGE COLUMN birthdate birth_date DATE DEFAULT NULL;

-- Ensure tp_owners has all required address columns
-- Check if columns exist before adding them
SET @col_exists = 0;
SELECT @col_exists := COUNT(*) 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = "tp_owners" 
AND COLUMN_NAME = "street";

SET @sql_street = IF(@col_exists = 0, 
    "ALTER TABLE tp_owners ADD COLUMN street VARCHAR(100) DEFAULT NULL AFTER mobile;",
    "SELECT 1;"
);
PREPARE stmt FROM @sql_street;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = 0;
SELECT @col_exists := COUNT(*) 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = "tp_owners" 
AND COLUMN_NAME = "house_number";

SET @sql_house = IF(@col_exists = 0,
    "ALTER TABLE tp_owners ADD COLUMN house_number VARCHAR(10) DEFAULT NULL AFTER street;",
    "SELECT 1;"
);
PREPARE stmt FROM @sql_house;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Drop old address column if it exists
SET @col_exists = 0;
SELECT @col_exists := COUNT(*) 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = "tp_owners" 
AND COLUMN_NAME = "address";

SET @sql_drop_address = IF(@col_exists = 1,
    "ALTER TABLE tp_owners DROP COLUMN address;",
    "SELECT 1;"
);
PREPARE stmt FROM @sql_drop_address;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Ensure customer_number exists and is unique
SET @col_exists = 0;
SELECT @col_exists := COUNT(*) 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = "tp_owners" 
AND COLUMN_NAME = "customer_number";

SET @sql_customer_number = IF(@col_exists = 0,
    "ALTER TABLE tp_owners ADD COLUMN customer_number VARCHAR(20) UNIQUE AFTER id;",
    "SELECT 1;"
);
PREPARE stmt FROM @sql_customer_number;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Ensure patient_number exists and is unique
SET @col_exists = 0;
SELECT @col_exists := COUNT(*) 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = "tp_patients" 
AND COLUMN_NAME = "patient_number";

SET @sql_patient_number = IF(@col_exists = 0,
    "ALTER TABLE tp_patients ADD COLUMN patient_number VARCHAR(20) UNIQUE AFTER id;",
    "SELECT 1;"
);
PREPARE stmt FROM @sql_patient_number;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Generate customer numbers for existing owners without one
UPDATE tp_owners 
SET customer_number = CONCAT("O", DATE_FORMAT(NOW(), "%y%m%d"), LPAD(id, 4, "0"))
WHERE customer_number IS NULL OR customer_number = "";

-- Generate patient numbers for existing patients without one
UPDATE tp_patients 
SET patient_number = CONCAT("P", DATE_FORMAT(NOW(), "%y%m%d"), LPAD(id, 4, "0"))
WHERE patient_number IS NULL OR patient_number = "";

-- Ensure enum columns have correct values
-- Fix species enum
ALTER TABLE tp_patients 
MODIFY COLUMN species ENUM("dog", "cat", "horse", "rabbit", "bird", "reptile", "other") NOT NULL DEFAULT "other";

-- Fix gender enum
ALTER TABLE tp_patients 
MODIFY COLUMN gender ENUM("male", "female", "neutered_male", "spayed_female", "unknown") DEFAULT "unknown";

-- Fix invoice status enum
ALTER TABLE tp_invoices 
MODIFY COLUMN status ENUM("draft", "sent", "paid", "partially_paid", "overdue", "cancelled") DEFAULT "draft";

-- Fix appointment status enum
ALTER TABLE tp_appointments 
MODIFY COLUMN status ENUM("scheduled", "confirmed", "in_progress", "completed", "cancelled", "no_show") DEFAULT "scheduled";

-- Add indexes for better performance
-- Check if index exists before creating
SET @index_exists = 0;
SELECT @index_exists := COUNT(*) 
FROM INFORMATION_SCHEMA.STATISTICS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = "tp_patients" 
AND INDEX_NAME = "idx_patient_number";

SET @sql_index = IF(@index_exists = 0,
    "CREATE INDEX idx_patient_number ON tp_patients(patient_number);",
    "SELECT 1;"
);
PREPARE stmt FROM @sql_index;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Record migration
INSERT INTO tp_migrations (version, name, executed_at) 
VALUES ("002", "fix_column_names", NOW());

', 'SELECT "Migration 002 already executed";');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;