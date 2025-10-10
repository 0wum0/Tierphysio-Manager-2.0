-- SQLite Setup Script for Tierphysio Manager 2.0

-- Users table
CREATE TABLE IF NOT EXISTS tp_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username VARCHAR(100) UNIQUE NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    role VARCHAR(50) DEFAULT 'user',
    is_active INTEGER DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Owners table
CREATE TABLE IF NOT EXISTS tp_owners (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_number VARCHAR(20) UNIQUE,
    salutation VARCHAR(20),
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    company VARCHAR(255),
    street VARCHAR(255),
    house_number VARCHAR(20),
    postal_code VARCHAR(10),
    city VARCHAR(100),
    country VARCHAR(100) DEFAULT 'Deutschland',
    phone VARCHAR(50),
    mobile VARCHAR(50),
    email VARCHAR(255),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Patients table
CREATE TABLE IF NOT EXISTS tp_patients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    patient_number VARCHAR(20) UNIQUE,
    owner_id INTEGER,
    name VARCHAR(100) NOT NULL,
    species VARCHAR(50),
    breed VARCHAR(100),
    color VARCHAR(100),
    gender VARCHAR(20),
    birth_date DATE,
    weight DECIMAL(10,2),
    microchip VARCHAR(50),
    image TEXT,
    medical_history TEXT,
    allergies TEXT,
    medications TEXT,
    notes TEXT,
    is_active INTEGER DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES tp_owners(id) ON DELETE SET NULL
);

-- Appointments table
CREATE TABLE IF NOT EXISTS tp_appointments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    patient_id INTEGER NOT NULL,
    therapist_id INTEGER,
    appointment_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME,
    type VARCHAR(100),
    status VARCHAR(50) DEFAULT 'scheduled',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES tp_patients(id) ON DELETE CASCADE,
    FOREIGN KEY (therapist_id) REFERENCES tp_users(id) ON DELETE SET NULL
);

-- Treatments table
CREATE TABLE IF NOT EXISTS tp_treatments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    patient_id INTEGER NOT NULL,
    therapist_id INTEGER,
    treatment_date DATE NOT NULL,
    treatment_type VARCHAR(100),
    description TEXT,
    duration_minutes INTEGER,
    price DECIMAL(10,2),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES tp_patients(id) ON DELETE CASCADE,
    FOREIGN KEY (therapist_id) REFERENCES tp_users(id) ON DELETE SET NULL
);

-- Invoices table
CREATE TABLE IF NOT EXISTS tp_invoices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    invoice_number VARCHAR(50) UNIQUE,
    patient_id INTEGER,
    owner_id INTEGER,
    invoice_date DATE NOT NULL,
    due_date DATE,
    status VARCHAR(50) DEFAULT 'draft',
    subtotal DECIMAL(10,2),
    tax_rate DECIMAL(5,2) DEFAULT 19.00,
    tax_amount DECIMAL(10,2),
    total DECIMAL(10,2),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES tp_patients(id) ON DELETE SET NULL,
    FOREIGN KEY (owner_id) REFERENCES tp_owners(id) ON DELETE SET NULL
);

-- Notes table
CREATE TABLE IF NOT EXISTS tp_notes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    patient_id INTEGER NOT NULL,
    type VARCHAR(50) DEFAULT 'general',
    title VARCHAR(255),
    content TEXT,
    created_by INTEGER,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES tp_patients(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES tp_users(id) ON DELETE SET NULL
);

-- Documents table (already created, but including for completeness)
CREATE TABLE IF NOT EXISTS tp_documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    patient_id INTEGER NOT NULL,
    title VARCHAR(255),
    file_name VARCHAR(255),
    file_path TEXT,
    file_size INTEGER,
    mime_type VARCHAR(100),
    uploaded_by INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES tp_patients(id) ON DELETE CASCADE
);

-- Settings table
CREATE TABLE IF NOT EXISTS tp_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create indexes for better performance
CREATE INDEX IF NOT EXISTS idx_patients_owner_id ON tp_patients(owner_id);
CREATE INDEX IF NOT EXISTS idx_appointments_patient_id ON tp_appointments(patient_id);
CREATE INDEX IF NOT EXISTS idx_appointments_date ON tp_appointments(appointment_date);
CREATE INDEX IF NOT EXISTS idx_treatments_patient_id ON tp_treatments(patient_id);
CREATE INDEX IF NOT EXISTS idx_invoices_patient_id ON tp_invoices(patient_id);
CREATE INDEX IF NOT EXISTS idx_notes_patient_id ON tp_notes(patient_id);
CREATE INDEX IF NOT EXISTS idx_documents_patient_id ON tp_documents(patient_id);

-- Insert default settings
INSERT OR IGNORE INTO tp_settings (setting_key, setting_value, description) VALUES
('company_name', 'Tierphysiotherapie Praxis', 'Name der Praxis'),
('company_address', 'Musterstraße 1, 12345 Musterstadt', 'Adresse der Praxis'),
('company_phone', '+49 123 456789', 'Telefonnummer'),
('company_email', 'info@tierphysio.de', 'E-Mail-Adresse'),
('tax_rate', '19', 'Standard Steuersatz in %'),
('currency', 'EUR', 'Währung'),
('session_duration', '45', 'Standard Behandlungsdauer in Minuten'),
('invoice_prefix', 'RE-', 'Rechnungsnummer-Präfix'),
('patient_prefix', 'P-', 'Patientennummer-Präfix'),
('customer_prefix', 'K-', 'Kundennummer-Präfix');

-- Insert test user (password: admin123)
INSERT OR IGNORE INTO tp_users (username, email, password_hash, first_name, last_name, role, is_active) VALUES
('admin', 'admin@tierphysio.de', '$2y$10$YourHashHere', 'Admin', 'User', 'admin', 1);

-- Insert test owner
INSERT OR IGNORE INTO tp_owners (customer_number, salutation, first_name, last_name, street, house_number, postal_code, city, phone, email) VALUES
('K-00001', 'Herr', 'Max', 'Mustermann', 'Beispielstraße', '42', '12345', 'Musterstadt', '0123-456789', 'max@example.com');

-- Insert test patient
INSERT OR IGNORE INTO tp_patients (patient_number, owner_id, name, species, breed, gender, birth_date, weight) VALUES
('P-00001', 1, 'Bello', 'dog', 'Labrador', 'male', '2020-05-15', 32.5);