-- ============================================================
--  Invoice Management System — Full Database Schema
--  Ozbix IT Solutions
--  Generated: 2026-06-12
--  
--  Run this file once on a fresh server to set up everything.
-- ============================================================

CREATE DATABASE IF NOT EXISTS invoice_management_system;
USE invoice_management_system;

-- ── Admins ────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS admins (
    id         INT          PRIMARY KEY AUTO_INCREMENT,
    username   VARCHAR(50)  UNIQUE NOT NULL,
    password   VARCHAR(255) NOT NULL,
    email      VARCHAR(100) NOT NULL,
    full_name  VARCHAR(100) NOT NULL,
    last_login DATETIME,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);

-- ── Clients ───────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS clients (
    id             INT          PRIMARY KEY AUTO_INCREMENT,
    name           VARCHAR(100) NOT NULL,
    company        VARCHAR(100),
    contact_person VARCHAR(100),
    phone          VARCHAR(20),
    email          VARCHAR(100),
    client_type    ENUM('prepaid', 'postpaid') NOT NULL DEFAULT 'postpaid',
    address        TEXT,
    notes          TEXT,
    deleted_at     DATETIME     NULL DEFAULT NULL,
    created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_client_name     (name),
    INDEX idx_client_email    (email),
    INDEX idx_client_deleted  (deleted_at)
);

-- ── Projects ──────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS projects (
    id             INT            PRIMARY KEY AUTO_INCREMENT,
    client_id      INT            NOT NULL,
    project_name   VARCHAR(200)   NOT NULL,
    description    TEXT,
    department     ENUM('Web Dev','SEO','Marketing','Design','Social Media','Other') DEFAULT 'Other',
    project_type   ENUM('One-time','Monthly')                                        DEFAULT 'One-time',
    status         ENUM('In Progress','Hold','Completed')                            DEFAULT 'In Progress',
    payment_status ENUM('Pending','Partial','Paid','Overdue')                        DEFAULT 'Pending',
    start_date     DATE,
    end_date       DATE,
    cost           DECIMAL(12,2)  DEFAULT 0.00,
    monthly_fee    DECIMAL(12,2)  DEFAULT 0.00,
    notes          TEXT,
    deleted_at     DATETIME       NULL DEFAULT NULL,
    created_at     TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    INDEX idx_project_client     (client_id),
    INDEX idx_project_status     (status),
    INDEX idx_project_type       (project_type),
    INDEX idx_project_department (department),
    INDEX idx_project_deleted    (deleted_at)
);

-- ── Invoices ──────────────────────────────────────────────────────────────────
-- project_id is NULL for combined multi-project invoices
CREATE TABLE IF NOT EXISTS invoices (
    id               INT           PRIMARY KEY AUTO_INCREMENT,
    invoice_number   VARCHAR(50)   UNIQUE NOT NULL,
    client_id        INT           NOT NULL,
    project_id       INT           DEFAULT NULL,
    invoice_date     DATE          NOT NULL,
    due_date         DATE          NOT NULL,
    amount           DECIMAL(12,2) NOT NULL,
    tax              DECIMAL(12,2) DEFAULT 0.00,
    discount         DECIMAL(12,2) DEFAULT 0.00,
    total            DECIMAL(12,2) NOT NULL,
    paid_amount      DECIMAL(12,2) DEFAULT 0.00,
    remaining_amount DECIMAL(12,2) NOT NULL,
    payment_status   ENUM('Pending','Partial','Paid','Overdue') DEFAULT 'Pending',
    notes            TEXT,
    view_token       VARCHAR(64)   NULL DEFAULT NULL,
    deleted_at       DATETIME      NULL DEFAULT NULL,
    created_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id)  REFERENCES clients(id),
    FOREIGN KEY (project_id) REFERENCES projects(id),
    INDEX idx_invoice_number (invoice_number),
    INDEX idx_invoice_client (client_id),
    INDEX idx_invoice_status (payment_status),
    INDEX idx_invoice_date   (invoice_date),
    INDEX idx_invoice_due    (due_date),
    INDEX idx_invoice_token  (view_token),
    INDEX idx_invoice_deleted (deleted_at)
);

-- ── Invoice Items ─────────────────────────────────────────────────────────────
-- One row per line item on an invoice (project fee, domain renewal, etc.)
-- project_id is NULL for carried-over balance rows
CREATE TABLE IF NOT EXISTS invoice_items (
    id          INT           PRIMARY KEY AUTO_INCREMENT,
    invoice_id  INT           NOT NULL,
    project_id  INT           DEFAULT NULL,
    description VARCHAR(255)  NOT NULL,
    amount      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    INDEX idx_item_invoice (invoice_id),
    INDEX idx_item_project (project_id)
);

-- ── Payments ──────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS payments (
    id               INT           PRIMARY KEY AUTO_INCREMENT,
    invoice_id       INT           NOT NULL,
    amount           DECIMAL(12,2) NOT NULL,
    payment_date     DATE          NOT NULL,
    payment_method   VARCHAR(50),
    reference_number VARCHAR(100),
    notes            TEXT,
    deleted_at       DATETIME      NULL DEFAULT NULL,
    created_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    INDEX idx_payment_invoice (invoice_id),
    INDEX idx_payment_date    (payment_date),
    INDEX idx_payment_deleted (deleted_at)
);

-- ── Trash Settings ────────────────────────────────────────────────────────────
-- Stores configuration for auto-cleanup of soft-deleted items
CREATE TABLE IF NOT EXISTS trash_settings (
    id                  INT PRIMARY KEY AUTO_INCREMENT,
    retention_days      INT DEFAULT 90,
    auto_cleanup_enabled TINYINT DEFAULT 1,
    last_cleanup        DATETIME NULL,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ── Insert Default Trash Settings ─────────────────────────────────────────────
INSERT IGNORE INTO trash_settings (id, retention_days, auto_cleanup_enabled) VALUES (1, 90, 1);
-- ── Cron Log ──────────────────────────────────────────────────────────────────
-- Tracks pseudo-cron job runs (monthly invoice generation, overdue checks)
CREATE TABLE IF NOT EXISTS cron_log (
    id        INT          PRIMARY KEY AUTO_INCREMENT,
    job_name  VARCHAR(100) NOT NULL,
    run_month VARCHAR(7)   NOT NULL,
    ran_at    DATETIME     NOT NULL,
    result    TEXT,
    UNIQUE KEY unique_job_month (job_name, run_month)
);

-- ── Activity Logs ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS activity_logs (
    id          INT          PRIMARY KEY AUTO_INCREMENT,
    admin_id    INT,
    action      VARCHAR(100) NOT NULL,
    description TEXT,
    ip_address  VARCHAR(45),
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES admins(id),
    INDEX idx_activity_admin (admin_id),
    INDEX idx_activity_date  (created_at)
);



-- ── Default Admin ─────────────────────────────────────────────────────────────
-- Default password: Admin@123
-- IMPORTANT: Change this password immediately after first login
INSERT IGNORE INTO admins (username, password, email, full_name) VALUES 
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@ozbix.com', 'Administrator');