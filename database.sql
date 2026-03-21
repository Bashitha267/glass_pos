CREATE DATABASE IF NOT EXISTS glass_pos;
USE glass_pos;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) DEFAULT NULL UNIQUE,
    password VARCHAR(255) DEFAULT NULL,
    role ENUM('admin', 'employee') NOT NULL DEFAULT 'employee',
    full_name VARCHAR(100) NOT NULL,
    contact_number VARCHAR(15) NOT NULL,
    nic_number VARCHAR(20) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    profile_pic VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS brands (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS containers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    container_number VARCHAR(50) NOT NULL UNIQUE,
    arrival_date DATE NOT NULL,
    added_by INT NOT NULL,
    total_expenses DECIMAL(15, 2) DEFAULT 0.00,
    container_cost DECIMAL(15, 2) DEFAULT 0.00,
    total_qty INT DEFAULT 0,
    damaged_qty INT DEFAULT 0,
    per_item_cost DECIMAL(15, 2) DEFAULT 0.00,
    country VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (added_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS container_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    container_id INT NOT NULL,
    brand_id INT NOT NULL,
    pallets INT NOT NULL,
    qty_per_pallet INT NOT NULL,
    total_qty INT NOT NULL,
    sold_qty INT DEFAULT 0,
    FOREIGN KEY (container_id) REFERENCES containers(id) ON DELETE CASCADE,
    FOREIGN KEY (brand_id) REFERENCES brands(id)
);

CREATE TABLE IF NOT EXISTS container_expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    container_id INT NOT NULL,
    expense_name VARCHAR(100) NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    FOREIGN KEY (container_id) REFERENCES containers(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS container_ledger (
    id INT AUTO_INCREMENT PRIMARY KEY,
    container_id INT NOT NULL,
    action_type VARCHAR(50) NOT NULL,
    field_name VARCHAR(50),
    old_value TEXT,
    new_value TEXT,
    changed_by INT NOT NULL,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (container_id) REFERENCES containers(id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS container_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    container_id INT NOT NULL,
    payment_id VARCHAR(50) NOT NULL,
    payment_type VARCHAR(100) NOT NULL,
    method ENUM('Cash', 'Card', 'Cheque') NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    description TEXT,
    payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (container_id) REFERENCES containers(id) ON DELETE CASCADE
);

-- Delivery System Tables
CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    contact_number VARCHAR(15),
    address TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS deliveries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    delivery_date DATE NOT NULL,
    total_expenses DECIMAL(15, 2) DEFAULT 0.00,
    total_sales DECIMAL(15, 2) DEFAULT 0.00,
    created_by INT NOT NULL,
    status ENUM('pending', 'completed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS delivery_employees (
    delivery_id INT NOT NULL,
    user_id INT NOT NULL,
    PRIMARY KEY (delivery_id, user_id),
    FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS delivery_expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    delivery_id INT NOT NULL,
    expense_name VARCHAR(100) NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS delivery_customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    delivery_id INT NOT NULL,
    customer_id INT NOT NULL,
    subtotal DECIMAL(15, 2) DEFAULT 0.00,
    discount DECIMAL(15, 2) DEFAULT 0.00,
    status ENUM('pending', 'delivered') DEFAULT 'pending',
    payment_status ENUM('pending', 'completed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id)
);

CREATE TABLE IF NOT EXISTS delivery_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    delivery_customer_id INT NOT NULL,
    container_item_id INT NOT NULL,
    qty INT NOT NULL,
    damaged_qty INT DEFAULT 0,
    cost_price DECIMAL(15, 2) NOT NULL,
    selling_price DECIMAL(15, 2) NOT NULL,
    total DECIMAL(15, 2) NOT NULL,
    FOREIGN KEY (delivery_customer_id) REFERENCES delivery_customers(id) ON DELETE CASCADE,
    FOREIGN KEY (container_item_id) REFERENCES container_items(id)
);

CREATE TABLE IF NOT EXISTS delivery_ledger (
    id INT AUTO_INCREMENT PRIMARY KEY,
    delivery_id INT NOT NULL,
    action_type ENUM('CREATED','DELETED','EDITED') NOT NULL,
    notes TEXT,
    performed_by INT NOT NULL,
    performed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE CASCADE,
    FOREIGN KEY (performed_by) REFERENCES users(id)
);

-- Field Operations (recorded by employees)
CREATE TABLE IF NOT EXISTS delivery_item_damages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    delivery_item_id INT NOT NULL,
    damaged_qty INT DEFAULT 0,
    recorded_by INT NOT NULL,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (delivery_item_id) REFERENCES delivery_items(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS delivery_field_expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    delivery_id INT NOT NULL,
    expense_name VARCHAR(100) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    added_by INT NOT NULL,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE CASCADE,
    FOREIGN KEY (added_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS delivery_proof_photos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    delivery_customer_id INT NOT NULL,
    photo_path VARCHAR(255) NOT NULL,
    uploaded_by INT NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (delivery_customer_id) REFERENCES delivery_customers(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
);
CREATE TABLE IF NOT EXISTS banks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    account_number VARCHAR(100) DEFAULT NULL,
    account_name VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS delivery_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    delivery_customer_id INT NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    payment_type ENUM('Cash', 'Account Transfer', 'Cheque', 'Card') NOT NULL,
    bank_id INT DEFAULT NULL,
    cheque_number VARCHAR(50) DEFAULT NULL,
    cheque_customer_id INT DEFAULT NULL,
    proof_image VARCHAR(255) DEFAULT NULL,
    payment_date DATE NOT NULL,
    due_date DATE DEFAULT NULL,
    recorded_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (delivery_customer_id) REFERENCES delivery_customers(id) ON DELETE CASCADE,
    FOREIGN KEY (bank_id) REFERENCES banks(id) ON DELETE SET NULL,
    FOREIGN KEY (cheque_customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (recorded_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS employee_salary_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    monthly_salary DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS employee_salary_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    salary_month TINYINT NOT NULL,
    salary_year SMALLINT NOT NULL,
    deliveries_count INT NOT NULL DEFAULT 0,
    salary_amount DECIMAL(15,2) NOT NULL,
    payment_date DATE DEFAULT NULL,
    status ENUM('paid','nonpaid') NOT NULL DEFAULT 'nonpaid',
    paid_at TIMESTAMP NULL DEFAULT NULL,
    recorded_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_employee_salary_month (user_id, salary_month, salary_year),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(id)
);

-- =====================================================
-- POS (Point of Sale) System Tables
-- =====================================================

CREATE TABLE IF NOT EXISTS pos_sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bill_id VARCHAR(20) NOT NULL UNIQUE,
    sale_date DATE NOT NULL,
    customer_id INT DEFAULT NULL,
    created_by INT NOT NULL,
    subtotal DECIMAL(15, 2) DEFAULT 0.00,
    item_discount DECIMAL(15, 2) DEFAULT 0.00,
    bill_discount DECIMAL(15, 2) DEFAULT 0.00,
    bill_discount_type VARCHAR(20) DEFAULT NULL,
    grand_total DECIMAL(15, 2) DEFAULT 0.00,
    payment_method ENUM('Cash', 'Account Transfer', 'Cheque', 'Card', 'Later Payment', 'Multiple') DEFAULT 'Cash',
    payment_status ENUM('pending', 'completed') DEFAULT 'pending',
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS pos_sale_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    container_item_id INT NOT NULL,
    qty INT NOT NULL,
    damaged_qty INT DEFAULT 0,
    cost_price DECIMAL(15, 2) NOT NULL,
    selling_price DECIMAL(15, 2) NOT NULL,
    item_discount DECIMAL(15, 2) DEFAULT 0.00,
    line_total DECIMAL(15, 2) NOT NULL,
    FOREIGN KEY (sale_id) REFERENCES pos_sales(id) ON DELETE CASCADE,
    FOREIGN KEY (container_item_id) REFERENCES container_items(id)
);

CREATE TABLE IF NOT EXISTS pos_sale_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    payment_type ENUM('Cash', 'Account Transfer', 'Cheque', 'Card') NOT NULL,
    bank_id INT DEFAULT NULL,
    cheque_number VARCHAR(50) DEFAULT NULL,
    cheque_payer_name VARCHAR(100) DEFAULT NULL,
    proof_image VARCHAR(255) DEFAULT NULL,
    payment_date DATE NOT NULL,
    recorded_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sale_id) REFERENCES pos_sales(id) ON DELETE CASCADE,
    FOREIGN KEY (bank_id) REFERENCES banks(id) ON DELETE SET NULL,
    FOREIGN KEY (recorded_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS pos_sale_audits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT DEFAULT NULL,
    action_type ENUM('CREATED','EDITED','DELETED','PAYMENT_ADDED','PAYMENT_DELETED') NOT NULL,
    field_name VARCHAR(100) DEFAULT NULL,
    old_value TEXT DEFAULT NULL,
    new_value TEXT DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    changed_by INT NOT NULL,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sale_id) REFERENCES pos_sales(id) ON DELETE SET NULL,
    FOREIGN KEY (changed_by) REFERENCES users(id)
);
