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

-- Insert initial admin user
-- Password is 'admin123'
INSERT INTO users (username, password, role) VALUES 
('admin', '$2y$12$uoFO9mg6BhgMBV78l0cT/emOYnChtx4f47b33zTFVxUe1f4EgCsji', 'admin');
