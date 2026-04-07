-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 07, 2026 at 05:46 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `glass_pos`
--

-- --------------------------------------------------------

--
-- Table structure for table `banks`
--

CREATE TABLE `banks` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `account_number` varchar(100) DEFAULT NULL,
  `account_name` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `brands`
--

CREATE TABLE `brands` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `containers`
--

CREATE TABLE `containers` (
  `id` int(11) NOT NULL,
  `container_number` varchar(50) NOT NULL,
  `arrival_date` date NOT NULL,
  `added_by` int(11) NOT NULL,
  `total_expenses` decimal(15,2) DEFAULT 0.00,
  `container_cost` decimal(15,2) DEFAULT 0.00,
  `total_qty` int(11) DEFAULT 0,
  `damaged_qty` int(11) DEFAULT 0,
  `per_item_cost` decimal(15,2) DEFAULT 0.00,
  `country` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `container_expenses`
--

CREATE TABLE `container_expenses` (
  `id` int(11) NOT NULL,
  `container_id` int(11) NOT NULL,
  `expense_name` varchar(100) NOT NULL,
  `amount` decimal(15,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `container_items`
--

CREATE TABLE `container_items` (
  `id` int(11) NOT NULL,
  `container_id` int(11) NOT NULL,
  `brand_id` int(11) NOT NULL,
  `pallets` int(11) NOT NULL,
  `qty_per_pallet` int(11) NOT NULL,
  `square_feet` double DEFAULT 0,
  `total_qty` int(11) NOT NULL,
  `sold_qty` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `container_ledger`
--

CREATE TABLE `container_ledger` (
  `id` int(11) NOT NULL,
  `container_id` int(11) NOT NULL,
  `action_type` varchar(50) NOT NULL,
  `field_name` varchar(50) DEFAULT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `changed_by` int(11) NOT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `container_payments`
--

CREATE TABLE `container_payments` (
  `id` int(11) NOT NULL,
  `container_id` int(11) NOT NULL,
  `payment_id` varchar(50) NOT NULL,
  `payment_type` varchar(100) NOT NULL,
  `method` enum('Cash','Card','Cheque') NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `description` text DEFAULT NULL,
  `payment_date` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `contact_number` varchar(15) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `deliveries`
--

CREATE TABLE `deliveries` (
  `id` int(11) NOT NULL,
  `delivery_date` date NOT NULL,
  `total_expenses` decimal(15,2) DEFAULT 0.00,
  `total_sales` decimal(15,2) DEFAULT 0.00,
  `created_by` int(11) NOT NULL,
  `status` enum('pending','completed') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `delivery_customers`
--

CREATE TABLE `delivery_customers` (
  `id` int(11) NOT NULL,
  `delivery_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `subtotal` decimal(15,2) DEFAULT 0.00,
  `status` enum('pending','delivered') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `discount` decimal(15,2) DEFAULT 0.00,
  `payment_status` enum('pending','completed') DEFAULT 'pending',
  `bill_number` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `delivery_employees`
--

CREATE TABLE `delivery_employees` (
  `delivery_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `delivery_expenses`
--

CREATE TABLE `delivery_expenses` (
  `id` int(11) NOT NULL,
  `delivery_id` int(11) NOT NULL,
  `expense_name` varchar(100) NOT NULL,
  `amount` decimal(15,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `delivery_field_expenses`
--

CREATE TABLE `delivery_field_expenses` (
  `id` int(11) NOT NULL,
  `delivery_id` int(11) NOT NULL,
  `expense_name` varchar(100) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `added_by` int(11) NOT NULL,
  `added_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `delivery_items`
--

CREATE TABLE `delivery_items` (
  `id` int(11) NOT NULL,
  `delivery_customer_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `item_source` enum('container','other') NOT NULL DEFAULT 'container',
  `qty` int(11) NOT NULL,
  `cost_price` decimal(15,2) NOT NULL,
  `selling_price` decimal(15,2) NOT NULL,
  `total` decimal(15,2) NOT NULL,
  `damaged_qty` int(11) DEFAULT 0,
  `bill_image` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `delivery_item_damages`
--

CREATE TABLE `delivery_item_damages` (
  `id` int(11) NOT NULL,
  `delivery_item_id` int(11) NOT NULL,
  `damaged_qty` int(11) DEFAULT 0,
  `recorded_by` int(11) NOT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `delivery_ledger`
--

CREATE TABLE `delivery_ledger` (
  `id` int(11) NOT NULL,
  `delivery_id` int(11) NOT NULL,
  `action_type` enum('CREATED','DELETED','EDITED') NOT NULL,
  `notes` text DEFAULT NULL,
  `performed_by` int(11) NOT NULL,
  `performed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `delivery_payments`
--

CREATE TABLE `delivery_payments` (
  `id` int(11) NOT NULL,
  `delivery_customer_id` int(11) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `payment_type` enum('Cash','Account Transfer','Cheque','Card') NOT NULL,
  `bank_id` int(11) DEFAULT NULL,
  `cheque_number` varchar(50) DEFAULT NULL,
  `cheque_payer_name` varchar(255) DEFAULT NULL,
  `proof_image` varchar(255) DEFAULT NULL,
  `payment_date` date NOT NULL,
  `recorded_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `due_date` date DEFAULT NULL,
  `cheque_customer_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `delivery_proof_photos`
--

CREATE TABLE `delivery_proof_photos` (
  `id` int(11) NOT NULL,
  `delivery_customer_id` int(11) NOT NULL,
  `photo_path` varchar(255) NOT NULL,
  `uploaded_by` int(11) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `employee_salary_payments`
--

CREATE TABLE `employee_salary_payments` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `salary_month` tinyint(4) NOT NULL,
  `salary_year` smallint(6) NOT NULL,
  `deliveries_count` int(11) NOT NULL DEFAULT 0,
  `salary_amount` decimal(15,2) NOT NULL,
  `payment_date` date DEFAULT NULL,
  `status` enum('paid','nonpaid') NOT NULL DEFAULT 'nonpaid',
  `paid_at` timestamp NULL DEFAULT NULL,
  `recorded_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `employee_salary_settings`
--

CREATE TABLE `employee_salary_settings` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `monthly_salary` decimal(15,2) NOT NULL DEFAULT 0.00,
  `payment_frequency` enum('monthly','weekly') DEFAULT 'monthly',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `monthly_expenses`
--

CREATE TABLE `monthly_expenses` (
  `id` int(11) NOT NULL,
  `expense_name` varchar(255) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `expense_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `other_purchases`
--

CREATE TABLE `other_purchases` (
  `id` int(11) NOT NULL,
  `purchase_number` varchar(50) NOT NULL,
  `bill_number` varchar(50) DEFAULT NULL,
  `buyer_name` varchar(100) NOT NULL,
  `total_amount` decimal(15,2) NOT NULL,
  `discount` decimal(15,2) DEFAULT 0.00,
  `grand_total` decimal(15,2) NOT NULL,
  `purchase_date` date NOT NULL,
  `added_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `other_purchase_expenses`
--

CREATE TABLE `other_purchase_expenses` (
  `id` int(11) NOT NULL,
  `purchase_id` int(11) NOT NULL,
  `expense_name` varchar(100) NOT NULL,
  `amount` decimal(15,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `other_purchase_items`
--

CREATE TABLE `other_purchase_items` (
  `id` int(11) NOT NULL,
  `purchase_id` int(11) NOT NULL,
  `item_name` varchar(100) NOT NULL,
  `category` enum('Glass','Other') DEFAULT 'Other',
  `qty` double DEFAULT 0,
  `square_feet` double DEFAULT 0,
  `sold_qty` int(11) DEFAULT 0,
  `price_per_item` decimal(15,2) NOT NULL,
  `line_total` decimal(15,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `other_purchase_payments`
--

CREATE TABLE `other_purchase_payments` (
  `id` int(11) NOT NULL,
  `purchase_id` int(11) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `bank_name` varchar(255) DEFAULT NULL,
  `payment_type` enum('Cash','Account Transfer','Cheque','Card') DEFAULT NULL,
  `bank_id` int(11) DEFAULT NULL,
  `cheque_number` varchar(50) DEFAULT NULL,
  `payer_name` varchar(255) DEFAULT NULL,
  `proof_image` varchar(255) DEFAULT NULL,
  `payment_date` date NOT NULL,
  `cheque_payer_name` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `recorded_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pos_sales`
--

CREATE TABLE `pos_sales` (
  `id` int(11) NOT NULL,
  `bill_id` varchar(20) NOT NULL,
  `sale_date` date NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `subtotal` decimal(15,2) DEFAULT 0.00,
  `item_discount` decimal(15,2) DEFAULT 0.00,
  `bill_discount` decimal(15,2) DEFAULT 0.00,
  `bill_discount_type` varchar(20) DEFAULT NULL,
  `grand_total` decimal(15,2) DEFAULT 0.00,
  `payment_method` enum('Cash','Account Transfer','Cheque','Card','Later Payment','Multiple') DEFAULT 'Cash',
  `payment_status` enum('pending','completed') DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pos_sale_audits`
--

CREATE TABLE `pos_sale_audits` (
  `id` int(11) NOT NULL,
  `sale_id` int(11) DEFAULT NULL,
  `action_type` enum('CREATED','EDITED','DELETED','PAYMENT_ADDED','PAYMENT_DELETED') NOT NULL,
  `field_name` varchar(100) DEFAULT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `changed_by` int(11) NOT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pos_sale_items`
--

CREATE TABLE `pos_sale_items` (
  `id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `item_source` enum('container','other') NOT NULL DEFAULT 'container',
  `qty` int(11) NOT NULL,
  `damaged_qty` int(11) DEFAULT 0,
  `cost_price` decimal(15,2) NOT NULL,
  `selling_price` decimal(15,2) NOT NULL,
  `item_discount` decimal(15,2) DEFAULT 0.00,
  `line_total` decimal(15,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pos_sale_payments`
--

CREATE TABLE `pos_sale_payments` (
  `id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `payment_type` enum('Cash','Account Transfer','Cheque','Card') NOT NULL,
  `bank_id` int(11) DEFAULT NULL,
  `cheque_number` varchar(50) DEFAULT NULL,
  `cheque_payer_name` varchar(100) DEFAULT NULL,
  `proof_image` varchar(255) DEFAULT NULL,
  `payment_date` date NOT NULL,
  `recorded_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `role` enum('admin','employee') NOT NULL DEFAULT 'employee',
  `full_name` varchar(100) NOT NULL,
  `contact_number` varchar(15) NOT NULL,
  `nic_number` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `profile_pic` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `role`, `full_name`, `contact_number`, `nic_number`, `address`, `profile_pic`, `created_at`) VALUES
(1, 'admin', '$2y$12$vAAF0zK96l/T5JDQgqSoBuPIFc5AYEL7tWkS9Q7u1jhAObDIyEd2a', 'admin', 'System Administrator', '0712345678', NULL, NULL, NULL, '2026-03-13 14:20:18'),
(5, NULL, NULL, 'employee', 'nimesh', '1234555', NULL, NULL, NULL, '2026-03-21 16:16:13'),
(6, NULL, NULL, 'employee', 'sugath', '122', NULL, NULL, NULL, '2026-03-21 16:25:12'),
(7, NULL, NULL, 'employee', 'kamal', '122222', NULL, NULL, NULL, '2026-03-23 14:54:10'),
(8, NULL, NULL, 'employee', 'kumar', '2222', NULL, NULL, NULL, '2026-03-29 09:15:25');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `banks`
--
ALTER TABLE `banks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `brands`
--
ALTER TABLE `brands`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `containers`
--
ALTER TABLE `containers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `container_number` (`container_number`),
  ADD KEY `added_by` (`added_by`);

--
-- Indexes for table `container_expenses`
--
ALTER TABLE `container_expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `container_id` (`container_id`);

--
-- Indexes for table `container_items`
--
ALTER TABLE `container_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `container_id` (`container_id`),
  ADD KEY `brand_id` (`brand_id`);

--
-- Indexes for table `container_ledger`
--
ALTER TABLE `container_ledger`
  ADD PRIMARY KEY (`id`),
  ADD KEY `container_id` (`container_id`),
  ADD KEY `changed_by` (`changed_by`);

--
-- Indexes for table `container_payments`
--
ALTER TABLE `container_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `container_id` (`container_id`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `deliveries`
--
ALTER TABLE `deliveries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `delivery_customers`
--
ALTER TABLE `delivery_customers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `delivery_id` (`delivery_id`),
  ADD KEY `customer_id` (`customer_id`);

--
-- Indexes for table `delivery_employees`
--
ALTER TABLE `delivery_employees`
  ADD PRIMARY KEY (`delivery_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `delivery_expenses`
--
ALTER TABLE `delivery_expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `delivery_id` (`delivery_id`);

--
-- Indexes for table `delivery_field_expenses`
--
ALTER TABLE `delivery_field_expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `delivery_id` (`delivery_id`),
  ADD KEY `added_by` (`added_by`);

--
-- Indexes for table `delivery_items`
--
ALTER TABLE `delivery_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `delivery_customer_id` (`delivery_customer_id`),
  ADD KEY `container_item_id` (`item_id`);

--
-- Indexes for table `delivery_item_damages`
--
ALTER TABLE `delivery_item_damages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `delivery_item_id` (`delivery_item_id`),
  ADD KEY `recorded_by` (`recorded_by`);

--
-- Indexes for table `delivery_ledger`
--
ALTER TABLE `delivery_ledger`
  ADD PRIMARY KEY (`id`),
  ADD KEY `delivery_id` (`delivery_id`),
  ADD KEY `performed_by` (`performed_by`);

--
-- Indexes for table `delivery_payments`
--
ALTER TABLE `delivery_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `delivery_customer_id` (`delivery_customer_id`),
  ADD KEY `bank_id` (`bank_id`),
  ADD KEY `recorded_by` (`recorded_by`),
  ADD KEY `cheque_customer_id` (`cheque_customer_id`);

--
-- Indexes for table `delivery_proof_photos`
--
ALTER TABLE `delivery_proof_photos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `delivery_customer_id` (`delivery_customer_id`),
  ADD KEY `uploaded_by` (`uploaded_by`);

--
-- Indexes for table `employee_salary_payments`
--
ALTER TABLE `employee_salary_payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_employee_salary_month` (`user_id`,`salary_month`,`salary_year`),
  ADD KEY `recorded_by` (`recorded_by`);

--
-- Indexes for table `employee_salary_settings`
--
ALTER TABLE `employee_salary_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `monthly_expenses`
--
ALTER TABLE `monthly_expenses`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `other_purchases`
--
ALTER TABLE `other_purchases`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `purchase_number` (`purchase_number`),
  ADD KEY `added_by` (`added_by`);

--
-- Indexes for table `other_purchase_expenses`
--
ALTER TABLE `other_purchase_expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `purchase_id` (`purchase_id`);

--
-- Indexes for table `other_purchase_items`
--
ALTER TABLE `other_purchase_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `purchase_id` (`purchase_id`);

--
-- Indexes for table `other_purchase_payments`
--
ALTER TABLE `other_purchase_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `purchase_id` (`purchase_id`),
  ADD KEY `bank_id` (`bank_id`),
  ADD KEY `recorded_by` (`recorded_by`);

--
-- Indexes for table `pos_sales`
--
ALTER TABLE `pos_sales`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `bill_id` (`bill_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `pos_sale_audits`
--
ALTER TABLE `pos_sale_audits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sale_id` (`sale_id`),
  ADD KEY `changed_by` (`changed_by`);

--
-- Indexes for table `pos_sale_items`
--
ALTER TABLE `pos_sale_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sale_id` (`sale_id`),
  ADD KEY `container_item_id` (`item_id`);

--
-- Indexes for table `pos_sale_payments`
--
ALTER TABLE `pos_sale_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sale_id` (`sale_id`),
  ADD KEY `bank_id` (`bank_id`),
  ADD KEY `recorded_by` (`recorded_by`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `banks`
--
ALTER TABLE `banks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `brands`
--
ALTER TABLE `brands`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `containers`
--
ALTER TABLE `containers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `container_expenses`
--
ALTER TABLE `container_expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `container_items`
--
ALTER TABLE `container_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `container_ledger`
--
ALTER TABLE `container_ledger`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `container_payments`
--
ALTER TABLE `container_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `deliveries`
--
ALTER TABLE `deliveries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `delivery_customers`
--
ALTER TABLE `delivery_customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `delivery_expenses`
--
ALTER TABLE `delivery_expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `delivery_field_expenses`
--
ALTER TABLE `delivery_field_expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `delivery_items`
--
ALTER TABLE `delivery_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `delivery_item_damages`
--
ALTER TABLE `delivery_item_damages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `delivery_ledger`
--
ALTER TABLE `delivery_ledger`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `delivery_payments`
--
ALTER TABLE `delivery_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `delivery_proof_photos`
--
ALTER TABLE `delivery_proof_photos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employee_salary_payments`
--
ALTER TABLE `employee_salary_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employee_salary_settings`
--
ALTER TABLE `employee_salary_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `monthly_expenses`
--
ALTER TABLE `monthly_expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `other_purchases`
--
ALTER TABLE `other_purchases`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `other_purchase_expenses`
--
ALTER TABLE `other_purchase_expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `other_purchase_items`
--
ALTER TABLE `other_purchase_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `other_purchase_payments`
--
ALTER TABLE `other_purchase_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pos_sales`
--
ALTER TABLE `pos_sales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pos_sale_audits`
--
ALTER TABLE `pos_sale_audits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pos_sale_items`
--
ALTER TABLE `pos_sale_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pos_sale_payments`
--
ALTER TABLE `pos_sale_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `containers`
--
ALTER TABLE `containers`
  ADD CONSTRAINT `containers_ibfk_1` FOREIGN KEY (`added_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `container_expenses`
--
ALTER TABLE `container_expenses`
  ADD CONSTRAINT `container_expenses_ibfk_1` FOREIGN KEY (`container_id`) REFERENCES `containers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `container_items`
--
ALTER TABLE `container_items`
  ADD CONSTRAINT `container_items_ibfk_1` FOREIGN KEY (`container_id`) REFERENCES `containers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `container_items_ibfk_2` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`);

--
-- Constraints for table `container_ledger`
--
ALTER TABLE `container_ledger`
  ADD CONSTRAINT `container_ledger_ibfk_1` FOREIGN KEY (`container_id`) REFERENCES `containers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `container_ledger_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `container_payments`
--
ALTER TABLE `container_payments`
  ADD CONSTRAINT `container_payments_ibfk_1` FOREIGN KEY (`container_id`) REFERENCES `containers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `deliveries`
--
ALTER TABLE `deliveries`
  ADD CONSTRAINT `deliveries_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `delivery_customers`
--
ALTER TABLE `delivery_customers`
  ADD CONSTRAINT `delivery_customers_ibfk_1` FOREIGN KEY (`delivery_id`) REFERENCES `deliveries` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `delivery_customers_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`);

--
-- Constraints for table `delivery_employees`
--
ALTER TABLE `delivery_employees`
  ADD CONSTRAINT `delivery_employees_ibfk_1` FOREIGN KEY (`delivery_id`) REFERENCES `deliveries` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `delivery_employees_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `delivery_expenses`
--
ALTER TABLE `delivery_expenses`
  ADD CONSTRAINT `delivery_expenses_ibfk_1` FOREIGN KEY (`delivery_id`) REFERENCES `deliveries` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `delivery_field_expenses`
--
ALTER TABLE `delivery_field_expenses`
  ADD CONSTRAINT `delivery_field_expenses_ibfk_1` FOREIGN KEY (`delivery_id`) REFERENCES `deliveries` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `delivery_field_expenses_ibfk_2` FOREIGN KEY (`added_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `delivery_items`
--
ALTER TABLE `delivery_items`
  ADD CONSTRAINT `delivery_items_ibfk_1` FOREIGN KEY (`delivery_customer_id`) REFERENCES `delivery_customers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `delivery_item_damages`
--
ALTER TABLE `delivery_item_damages`
  ADD CONSTRAINT `delivery_item_damages_ibfk_1` FOREIGN KEY (`delivery_item_id`) REFERENCES `delivery_items` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `delivery_item_damages_ibfk_2` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `delivery_ledger`
--
ALTER TABLE `delivery_ledger`
  ADD CONSTRAINT `delivery_ledger_ibfk_1` FOREIGN KEY (`delivery_id`) REFERENCES `deliveries` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `delivery_ledger_ibfk_2` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `delivery_payments`
--
ALTER TABLE `delivery_payments`
  ADD CONSTRAINT `delivery_payments_ibfk_1` FOREIGN KEY (`delivery_customer_id`) REFERENCES `delivery_customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `delivery_payments_ibfk_2` FOREIGN KEY (`bank_id`) REFERENCES `banks` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `delivery_payments_ibfk_3` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `delivery_payments_ibfk_4` FOREIGN KEY (`cheque_customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `delivery_proof_photos`
--
ALTER TABLE `delivery_proof_photos`
  ADD CONSTRAINT `delivery_proof_photos_ibfk_1` FOREIGN KEY (`delivery_customer_id`) REFERENCES `delivery_customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `delivery_proof_photos_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `employee_salary_payments`
--
ALTER TABLE `employee_salary_payments`
  ADD CONSTRAINT `employee_salary_payments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `employee_salary_payments_ibfk_2` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `employee_salary_settings`
--
ALTER TABLE `employee_salary_settings`
  ADD CONSTRAINT `employee_salary_settings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `other_purchases`
--
ALTER TABLE `other_purchases`
  ADD CONSTRAINT `other_purchases_ibfk_1` FOREIGN KEY (`added_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `other_purchase_expenses`
--
ALTER TABLE `other_purchase_expenses`
  ADD CONSTRAINT `other_purchase_expenses_ibfk_1` FOREIGN KEY (`purchase_id`) REFERENCES `other_purchases` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `other_purchase_items`
--
ALTER TABLE `other_purchase_items`
  ADD CONSTRAINT `other_purchase_items_ibfk_1` FOREIGN KEY (`purchase_id`) REFERENCES `other_purchases` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `other_purchase_payments`
--
ALTER TABLE `other_purchase_payments`
  ADD CONSTRAINT `other_purchase_payments_ibfk_1` FOREIGN KEY (`purchase_id`) REFERENCES `other_purchases` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `other_purchase_payments_ibfk_2` FOREIGN KEY (`bank_id`) REFERENCES `banks` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `other_purchase_payments_ibfk_3` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `pos_sales`
--
ALTER TABLE `pos_sales`
  ADD CONSTRAINT `pos_sales_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `pos_sales_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `pos_sale_audits`
--
ALTER TABLE `pos_sale_audits`
  ADD CONSTRAINT `pos_sale_audits_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `pos_sales` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `pos_sale_audits_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `pos_sale_items`
--
ALTER TABLE `pos_sale_items`
  ADD CONSTRAINT `pos_sale_items_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `pos_sales` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `pos_sale_payments`
--
ALTER TABLE `pos_sale_payments`
  ADD CONSTRAINT `pos_sale_payments_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `pos_sales` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `pos_sale_payments_ibfk_2` FOREIGN KEY (`bank_id`) REFERENCES `banks` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `pos_sale_payments_ibfk_3` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
