-- Test Data for Tierphysio Manager 2.0
-- This creates sample data for testing

-- Create database if not exists
CREATE DATABASE IF NOT EXISTS tierphysio_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE tierphysio_db;

-- Create owners table if not exists
CREATE TABLE IF NOT EXISTS tp_owners (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_number VARCHAR(50) UNIQUE,
    salutation VARCHAR(20),
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    company VARCHAR(200),
    phone VARCHAR(50),
    mobile VARCHAR(50),
    email VARCHAR(150),
    street VARCHAR(200),
    house_number VARCHAR(20),
    postal_code VARCHAR(10),
    city VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Create patients table if not exists
CREATE TABLE IF NOT EXISTS tp_patients (
    id INT PRIMARY KEY AUTO_INCREMENT,
    patient_number VARCHAR(50) UNIQUE,
    owner_id INT,
    name VARCHAR(100) NOT NULL,
    species VARCHAR(50),
    breed VARCHAR(100),
    color VARCHAR(50),
    gender VARCHAR(20),
    birth_date DATE,
    weight DECIMAL(10,2),
    microchip VARCHAR(100),
    medical_history TEXT,
    allergies TEXT,
    medications TEXT,
    notes TEXT,
    is_active BOOLEAN DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES tp_owners(id) ON DELETE SET NULL
);

-- Insert test owners
INSERT IGNORE INTO tp_owners (customer_number, first_name, last_name, email, phone, city) VALUES
('K20241001', 'Anna', 'Müller', 'anna.mueller@example.com', '0351-123456', 'Dresden'),
('K20241002', 'Thomas', 'Schmidt', 'thomas.schmidt@example.com', '0351-234567', 'Dresden'),
('K20241003', 'Maria', 'Wagner', 'maria.wagner@example.com', '0351-345678', 'Leipzig'),
('K20241004', 'Michael', 'Becker', 'michael.becker@example.com', '0351-456789', 'Chemnitz');

-- Insert test patients with owners
INSERT IGNORE INTO tp_patients (patient_number, owner_id, name, species, breed, gender, birth_date) VALUES
('P20241001', 1, 'Bello', 'dog', 'Labrador', 'male', '2020-03-15'),
('P20241002', 1, 'Luna', 'cat', 'Europäisch Kurzhaar', 'female', '2019-07-22'),
('P20241003', 2, 'Max', 'dog', 'Schäferhund', 'male', '2018-11-05'),
('P20241004', 3, 'Felix', 'cat', 'Maine Coon', 'male', '2021-02-28'),
('P20241005', 4, 'Stella', 'horse', 'Haflinger', 'female', '2015-06-10');

-- Insert a patient without owner for testing
INSERT IGNORE INTO tp_patients (patient_number, owner_id, name, species, breed, gender) VALUES
('P20241006', NULL, 'Streuner', 'cat', 'Mischling', 'unknown');