-- Tierphysio Manager 2.0 - Database Setup Script
-- This script creates the basic tables and inserts dummy data

-- Create database if not exists
CREATE DATABASE IF NOT EXISTS tierphysio_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE tierphysio_db;

-- Drop existing tables to start fresh
DROP TABLE IF EXISTS patients;
DROP TABLE IF EXISTS owners;

-- Create owners table (simplified)
CREATE TABLE owners (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    phone VARCHAR(50),
    email VARCHAR(100),
    address TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create patients table (simplified)
CREATE TABLE patients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    species VARCHAR(50),
    breed VARCHAR(100),
    birthdate DATE,
    gender VARCHAR(20) DEFAULT 'unknown',
    weight DECIMAL(5,2),
    microchip VARCHAR(50),
    color VARCHAR(50),
    notes TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES owners(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert dummy owners
INSERT INTO owners (first_name, last_name, phone, email, address) VALUES
('Max', 'Mustermann', '0171-1234567', 'max@example.com', 'Musterstraße 1, 12345 Berlin'),
('Anna', 'Schmidt', '0172-2345678', 'anna.schmidt@example.com', 'Hauptstraße 15, 10115 Berlin'),
('Thomas', 'Weber', '0173-3456789', 'thomas.weber@example.com', 'Gartenweg 8, 10178 Berlin'),
('Sarah', 'Meyer', '0174-4567890', 'sarah.meyer@example.com', 'Parkstraße 22, 10435 Berlin'),
('Michael', 'Wagner', '0175-5678901', 'michael.wagner@example.com', 'Waldweg 5, 10999 Berlin'),
('Julia', 'Becker', '0176-6789012', 'julia.becker@example.com', 'Seestraße 18, 12047 Berlin'),
('Stefan', 'Schulz', '0177-7890123', 'stefan.schulz@example.com', 'Bergstraße 9, 13347 Berlin'),
('Laura', 'Hoffmann', '0178-8901234', 'laura.hoffmann@example.com', 'Talweg 3, 14195 Berlin'),
('Markus', 'Schäfer', '0179-9012345', 'markus.schaefer@example.com', 'Wiesenstraße 7, 10827 Berlin'),
('Nina', 'Koch', '0170-0123456', 'nina.koch@example.com', 'Feldweg 12, 10965 Berlin');

-- Insert dummy patients
INSERT INTO patients (owner_id, name, species, breed, birthdate, gender, weight, microchip, color, notes) VALUES
(1, 'Bella', 'dog', 'Labrador Retriever', '2018-03-15', 'female', 28.5, '276098106234567', 'Golden', 'Sehr freundlich, mag Wasser'),
(1, 'Max', 'cat', 'Maine Coon', '2020-07-22', 'male', 6.2, '276098106234568', 'Grau getigert', 'Scheu bei Fremden'),
(2, 'Luna', 'dog', 'Golden Retriever', '2019-11-08', 'female', 30.0, '276098106234569', 'Creme', 'Hüftdysplasie, regelmäßige Kontrolle'),
(3, 'Charlie', 'dog', 'Beagle', '2021-02-14', 'male', 12.8, '276098106234570', 'Tricolor', 'Sehr verspielt'),
(3, 'Mimi', 'cat', 'Perser', '2017-09-30', 'female', 4.5, '276098106234571', 'Weiß', 'Langhaar, benötigt regelmäßige Pflege'),
(4, 'Rocky', 'dog', 'Deutscher Schäferhund', '2016-05-20', 'male', 35.2, '276098106234572', 'Schwarz-braun', 'Ausgebildeter Schutzhund'),
(5, 'Emma', 'horse', 'Hannoveraner', '2012-04-10', 'female', 520.0, '276098106234573', 'Braun', 'Springpferd, Turniere'),
(6, 'Felix', 'cat', 'Europäisch Kurzhaar', '2019-12-01', 'male', 5.1, '276098106234574', 'Schwarz', 'Freigänger'),
(7, 'Buddy', 'dog', 'Jack Russell Terrier', '2020-08-17', 'male', 7.5, '276098106234575', 'Weiß mit braunen Flecken', 'Sehr energiegeladen'),
(8, 'Nala', 'rabbit', 'Zwergwidder', '2021-03-25', 'female', 1.8, NULL, 'Grau', 'Wohnungshaltung'),
(9, 'Oscar', 'dog', 'Mops', '2018-10-12', 'male', 8.9, '276098106234576', 'Beige', 'Atemprobleme, benötigt Spezialbehandlung'),
(10, 'Coco', 'bird', 'Wellensittich', '2022-01-05', 'unknown', 0.04, NULL, 'Grün-gelb', 'Paar mit Kiwi'),
(10, 'Kiwi', 'bird', 'Wellensittich', '2022-01-05', 'unknown', 0.04, NULL, 'Blau-weiß', 'Paar mit Coco'),
(2, 'Shadow', 'cat', 'Britisch Kurzhaar', '2020-06-18', 'male', 5.8, '276098106234577', 'Blau', 'Ruhig, verschmust'),
(5, 'Duke', 'dog', 'Rottweiler', '2017-11-29', 'male', 45.0, '276098106234578', 'Schwarz mit braun', 'Gut sozialisiert, kinderfreundlich');

-- Create index for better performance
CREATE INDEX idx_patients_owner ON patients(owner_id);
CREATE INDEX idx_owners_name ON owners(last_name, first_name);

-- Display summary
SELECT 'Database setup completed!' as Status;
SELECT COUNT(*) as 'Total Owners' FROM owners;
SELECT COUNT(*) as 'Total Patients' FROM patients;