-- Create database
CREATE DATABASE IF NOT EXISTS invoice_management_system;
USE invoice_management_system;

-- Admins table
CREATE TABLE admins (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    last_login DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Clients table
CREATE TABLE clients (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    company VARCHAR(100),
    contact_person VARCHAR(100),
    phone VARCHAR(20),
    email VARCHAR(100),
    address TEXT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_client_name (name),
    INDEX idx_client_email (email)
);

-- Projects table
CREATE TABLE projects (
    id INT PRIMARY KEY AUTO_INCREMENT,
    client_id INT NOT NULL,
    project_name VARCHAR(200) NOT NULL,
    description TEXT,
    department ENUM('Web Dev', 'SEO', 'Marketing', 'Design', 'Social Media', 'Other') DEFAULT 'Other',
    project_type ENUM('One-time', 'Monthly') DEFAULT 'One-time',
    status ENUM('In Progress', 'Hold', 'Completed') DEFAULT 'In Progress',
    payment_status ENUM('Pending', 'Partial', 'Paid') DEFAULT 'Pending',
    start_date DATE,
    end_date DATE,
    cost DECIMAL(12,2),
    monthly_fee DECIMAL(12,2),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    INDEX idx_project_client (client_id),
    INDEX idx_project_status (status),
    INDEX idx_project_type (project_type)
);

-- Invoices table
CREATE TABLE invoices (
    id INT PRIMARY KEY AUTO_INCREMENT,
    invoice_number VARCHAR(50) UNIQUE NOT NULL,
    client_id INT NOT NULL,
    project_id INT NOT NULL,
    invoice_date DATE NOT NULL,
    due_date DATE NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    tax DECIMAL(12,2) DEFAULT 0,
    discount DECIMAL(12,2) DEFAULT 0,
    total DECIMAL(12,2) NOT NULL,
    paid_amount DECIMAL(12,2) DEFAULT 0,
    remaining_amount DECIMAL(12,2) NOT NULL,
    payment_status ENUM('Paid', 'Partial', 'Pending', 'Overdue') DEFAULT 'Pending',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id),
    FOREIGN KEY (project_id) REFERENCES projects(id),
    INDEX idx_invoice_number (invoice_number),
    INDEX idx_invoice_client (client_id),
    INDEX idx_invoice_status (payment_status),
    INDEX idx_invoice_date (invoice_date)
);

-- Payments table
CREATE TABLE payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    invoice_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_date DATE NOT NULL,
    payment_method VARCHAR(50),
    reference_number VARCHAR(100),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    INDEX idx_payment_invoice (invoice_id),
    INDEX idx_payment_date (payment_date)
);

-- Activity logs table
CREATE TABLE activity_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    admin_id INT,
    action VARCHAR(100) NOT NULL,
    description TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES admins(id),
    INDEX idx_activity_admin (admin_id),
    INDEX idx_activity_date (created_at)
);

-- Insert default admin (password: Admin@123)
INSERT INTO admins (username, password, email, full_name) VALUES 
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@example.com', 'Administrator');

-- Create indexes for better performance
CREATE INDEX idx_invoices_due_date ON invoices(due_date);
CREATE INDEX idx_payments_amount ON payments(amount);
CREATE INDEX idx_projects_department ON projects(department);