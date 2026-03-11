CREATE DATABASE IF NOT EXISTS glass_pos;
USE glass_pos;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'employee') NOT NULL DEFAULT 'employee',
    contact_number VARCHAR(15),
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
