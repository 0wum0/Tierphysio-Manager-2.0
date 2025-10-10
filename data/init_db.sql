-- Tierphysio Manager 2.0
-- SQLite Database Schema

-- Owners table
CREATE TABLE IF NOT EXISTS tp_owners (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_number VARCHAR(20) UNIQUE,
    salutation VARCHAR(10),
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    company VARCHAR(100),
    phone VARCHAR(20),
    mobile VARCHAR(20),
    email VARCHAR(100),
    street VARCHAR(100),
    house_number VARCHAR(10),
    postal_code VARCHAR(10),
    city VARCHAR(100),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Patients table
CREATE TABLE IF NOT EXISTS tp_patients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    patient_number VARCHAR(20) UNIQUE,
    owner_id INTEGER,
    name VARCHAR(100) NOT NULL,
    species VARCHAR(50),
    breed VARCHAR(50),
    color VARCHAR(50),
    gender VARCHAR(20),
    birth_date DATE,
    weight DECIMAL(5,2),
    microchip VARCHAR(50),
    medical_history TEXT,
    allergies TEXT,
    medications TEXT,
    notes TEXT,
    image VARCHAR(255),
    is_active BOOLEAN DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES tp_owners(id)
);

-- Appointments table
CREATE TABLE IF NOT EXISTS tp_appointments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    patient_id INTEGER,
    appointment_date DATE,
    start_time TIME,
    status VARCHAR(20) DEFAULT 'scheduled',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES tp_patients(id)
);

-- Invoices table
CREATE TABLE IF NOT EXISTS tp_invoices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    patient_id INTEGER,
    status VARCHAR(20) DEFAULT 'open',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES tp_patients(id)
);

-- Users table
CREATE TABLE IF NOT EXISTS tp_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    email VARCHAR(100) UNIQUE,
    password VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Treatments table
CREATE TABLE IF NOT EXISTS tp_treatments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    patient_id INTEGER,
    therapist_id INTEGER,
    treatment_date DATE,
    description TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES tp_patients(id),
    FOREIGN KEY (therapist_id) REFERENCES tp_users(id)
);

-- Notes table
CREATE TABLE IF NOT EXISTS tp_notes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    patient_id INTEGER,
    type VARCHAR(20),
    title VARCHAR(200),
    content TEXT,
    created_by INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES tp_patients(id),
    FOREIGN KEY (created_by) REFERENCES tp_users(id)
);

-- Documents table
CREATE TABLE IF NOT EXISTS tp_documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    patient_id INTEGER,
    type VARCHAR(20),
    title VARCHAR(200),
    file_path VARCHAR(500),
    uploaded_by INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES tp_patients(id),
    FOREIGN KEY (uploaded_by) REFERENCES tp_users(id)
);

-- Insert test data
INSERT OR IGNORE INTO tp_owners (id, customer_number, first_name, last_name, email, phone, city) 
VALUES 
(1, 'KD-001', 'Max', 'Mustermann', 'max@example.com', '0123456789', 'Berlin'),
(2, 'KD-002', 'Anna', 'Schmidt', 'anna@example.com', '0987654321', 'München'),
(3, 'KD-003', 'Peter', 'Müller', 'peter@example.com', '0456789123', 'Hamburg');

INSERT OR IGNORE INTO tp_patients (id, patient_number, owner_id, name, species, breed, is_active) 
VALUES 
(1, 'P-001', 1, 'Bello', 'Hund', 'Labrador', 1),
(2, 'P-002', 2, 'Mimi', 'Katze', 'Perser', 1),
(3, 'P-003', 3, 'Thunder', 'Pferd', 'Haflinger', 1),
(4, 'P-004', 1, 'Luna', 'Hund', 'Golden Retriever', 1),
(5, 'P-005', NULL, 'Rex', 'Hund', 'Schäferhund', 1);  -- Patient ohne Owner zum Testen