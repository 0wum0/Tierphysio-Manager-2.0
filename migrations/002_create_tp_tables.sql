-- Tierphysio Manager 2.0 - Create tp_ prefixed tables
-- This script creates the tp_ prefixed tables with proper structure

USE tierphysio_db;

-- Create tp_owners table if not exists
CREATE TABLE IF NOT EXISTS tp_owners (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_number VARCHAR(50) UNIQUE,
    salutation VARCHAR(10) DEFAULT 'Herr',
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    company VARCHAR(200),
    phone VARCHAR(50),
    mobile VARCHAR(50),
    email VARCHAR(100),
    street VARCHAR(200),
    house_number VARCHAR(20),
    postal_code VARCHAR(10),
    city VARCHAR(100),
    notes TEXT,
    created_by INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customer_number (customer_number),
    INDEX idx_name (last_name, first_name),
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create tp_patients table if not exists
CREATE TABLE IF NOT EXISTS tp_patients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_number VARCHAR(50) UNIQUE,
    owner_id INT,
    name VARCHAR(100) NOT NULL,
    species VARCHAR(50),
    breed VARCHAR(100),
    birth_date DATE,
    gender VARCHAR(20) DEFAULT 'unknown',
    weight DECIMAL(8,2),
    microchip VARCHAR(50),
    color VARCHAR(50),
    notes TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_by INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES tp_owners(id) ON DELETE SET NULL,
    INDEX idx_patient_number (patient_number),
    INDEX idx_owner_id (owner_id),
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrate data from old tables if they exist
-- Migrate owners to tp_owners
INSERT IGNORE INTO tp_owners (
    first_name, last_name, email, phone, city, created_at
)
SELECT 
    first_name, 
    last_name, 
    email, 
    phone,
    SUBSTRING_INDEX(address, ',', -1) as city,
    created_at
FROM owners
WHERE NOT EXISTS (
    SELECT 1 FROM tp_owners 
    WHERE tp_owners.email = owners.email 
    AND tp_owners.first_name = owners.first_name 
    AND tp_owners.last_name = owners.last_name
);

-- Update customer numbers for migrated records
UPDATE tp_owners 
SET customer_number = CONCAT('K', LPAD(id, 6, '0'))
WHERE customer_number IS NULL;

-- Migrate patients to tp_patients (if owners were migrated)
INSERT IGNORE INTO tp_patients (
    owner_id, name, species, breed, birth_date, gender, weight, microchip, color, notes, created_at
)
SELECT 
    tp.id as owner_id,
    p.name,
    p.species,
    p.breed,
    p.birthdate,
    p.gender,
    p.weight,
    p.microchip,
    p.color,
    p.notes,
    p.created_at
FROM patients p
JOIN owners o ON p.owner_id = o.id
JOIN tp_owners tp ON o.first_name = tp.first_name 
    AND o.last_name = tp.last_name 
    AND (o.email = tp.email OR (o.email IS NULL AND tp.email IS NULL))
WHERE NOT EXISTS (
    SELECT 1 FROM tp_patients 
    WHERE tp_patients.name = p.name 
    AND tp_patients.owner_id = tp.id
);

-- Update patient numbers for migrated records
UPDATE tp_patients 
SET patient_number = CONCAT('P', LPAD(id, 6, '0'))
WHERE patient_number IS NULL;

-- Add some test data if tables are empty
INSERT INTO tp_owners (customer_number, salutation, first_name, last_name, email, phone, city, postal_code, street)
SELECT 
    CONCAT('K', LPAD((SELECT IFNULL(MAX(id), 0) + 1 FROM tp_owners), 6, '0')),
    'Herr',
    'Test',
    'Besitzer',
    'test@example.com',
    '+49 123 456789',
    'Berlin',
    '10115',
    'Teststraße 1'
FROM dual
WHERE NOT EXISTS (SELECT 1 FROM tp_owners LIMIT 1);

-- Display summary
SELECT 'tp_ tables created/updated!' as Status;
SELECT COUNT(*) as 'Total tp_owners' FROM tp_owners;
SELECT COUNT(*) as 'Total tp_patients' FROM tp_patients;