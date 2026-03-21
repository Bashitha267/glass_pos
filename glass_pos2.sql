-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 21, 2026 at 05:37 PM
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

--
-- Dumping data for table `banks`
--

INSERT INTO `banks` (`id`, `name`, `created_at`, `account_number`, `account_name`) VALUES
(1, 'Bank of ceylon', '2026-03-21 16:29:03', '122121', 'myboc');

-- --------------------------------------------------------

--
-- Table structure for table `brands`
--

CREATE TABLE `brands` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `brands`
--

INSERT INTO `brands` (`id`, `name`) VALUES
(2, '1'),
(1, '15mm'),
(3, '17mm');

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

--
-- Dumping data for table `containers`
--

INSERT INTO `containers` (`id`, `container_number`, `arrival_date`, `added_by`, `total_expenses`, `container_cost`, `total_qty`, `damaged_qty`, `per_item_cost`, `country`, `created_at`) VALUES
(1, '0001', '2026-03-21', 1, 90000.00, 25000.00, 120, 10, 818.18, 'china', '2026-03-21 16:13:14'),
(21, '0002', '2026-03-21', 1, 75000.00, 50000.00, 400, 5, 189.87, 'Dubai', '2026-03-21 16:14:03');

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

--
-- Dumping data for table `container_expenses`
--

INSERT INTO `container_expenses` (`id`, `container_id`, `expense_name`, `amount`) VALUES
(12, 1, 'Transport', 45000.00),
(13, 1, 'Duty Charge', 20000.00),
(21, 21, 'Transport', 25000.00);

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
  `total_qty` int(11) NOT NULL,
  `sold_qty` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `container_items`
--

INSERT INTO `container_items` (`id`, `container_id`, `brand_id`, `pallets`, `qty_per_pallet`, `total_qty`, `sold_qty`) VALUES
(17, 1, 1, 10, 12, 120, 110),
(34, 21, 3, 20, 20, 400, 340);

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

--
-- Dumping data for table `container_ledger`
--

INSERT INTO `container_ledger` (`id`, `container_id`, `action_type`, `field_name`, `old_value`, `new_value`, `changed_by`, `changed_at`) VALUES
(1, 1, 'CREATED', NULL, NULL, NULL, 1, '2026-03-21 16:13:14'),
(2, 1, 'UPDATE', 'country', '', 'china', 1, '2026-03-21 16:13:18'),
(3, 1, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-21 16:13:18'),
(4, 1, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-21 16:13:19'),
(5, 1, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-21 16:13:22'),
(6, 1, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-21 16:13:23'),
(7, 1, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-21 16:13:25'),
(8, 1, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-21 16:13:26'),
(9, 1, 'UPDATE', 'total_qty', '0', '120', 1, '2026-03-21 16:13:27'),
(10, 1, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-21 16:13:27'),
(11, 1, 'UPDATE', 'total_expenses', '0.00', '25000', 1, '2026-03-21 16:13:31'),
(12, 1, 'UPDATE', 'container_cost', '0.00', '25000', 1, '2026-03-21 16:13:31'),
(13, 1, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-21 16:13:31'),
(14, 1, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-21 16:13:33'),
(15, 1, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-21 16:13:35'),
(16, 1, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-21 16:13:36'),
(17, 1, 'UPDATE', 'total_expenses', '25000.00', '29500', 1, '2026-03-21 16:13:39'),
(18, 1, 'EXPENSE', 'Added Expense: Transport', NULL, 'Rs. 4,500.00', 1, '2026-03-21 16:13:39'),
(19, 1, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-21 16:13:39'),
(20, 1, 'UPDATE', 'total_expenses', '29500.00', '70000', 1, '2026-03-21 16:13:40'),
(21, 1, 'EXPENSE', 'Added Expense: Transport', NULL, 'Rs. 45,000.00', 1, '2026-03-21 16:13:40'),
(22, 1, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-21 16:13:40'),
(23, 1, 'EXPENSE', 'Added Expense: Transport', NULL, 'Rs. 45,000.00', 1, '2026-03-21 16:13:42'),
(24, 1, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-21 16:13:42'),
(25, 1, 'UPDATE', 'total_expenses', '70000.00', '90000', 1, '2026-03-21 16:13:44'),
(26, 1, 'EXPENSE', 'Added Expense: Transport', NULL, 'Rs. 45,000.00', 1, '2026-03-21 16:13:44'),
(27, 1, 'EXPENSE', 'Added Expense: Duty Charge', NULL, 'Rs. 20,000.00', 1, '2026-03-21 16:13:44'),
(28, 1, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-21 16:13:44'),
(29, 1, 'EXPENSE', 'Added Expense: Transport', NULL, 'Rs. 45,000.00', 1, '2026-03-21 16:13:47'),
(30, 1, 'EXPENSE', 'Added Expense: Duty Charge', NULL, 'Rs. 20,000.00', 1, '2026-03-21 16:13:47'),
(31, 1, 'PAYMENT', 'Payment Method: Cash', NULL, 'Rs. 90,000.00', 1, '2026-03-21 16:13:47'),
(32, 1, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-21 16:13:47'),
(33, 1, 'EXPENSE', 'Added Expense: Transport', NULL, 'Rs. 45,000.00', 1, '2026-03-21 16:13:51'),
(34, 1, 'EXPENSE', 'Added Expense: Duty Charge', NULL, 'Rs. 20,000.00', 1, '2026-03-21 16:13:51'),
(35, 1, 'PAYMENT', 'Payment Method: Cash', NULL, 'Rs. 45,000.00', 1, '2026-03-21 16:13:51'),
(36, 1, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-21 16:13:51'),
(37, 1, 'UPDATE', 'damaged_qty', '0', '10', 1, '2026-03-21 16:13:54'),
(38, 1, 'EXPENSE', 'Added Expense: Transport', NULL, 'Rs. 45,000.00', 1, '2026-03-21 16:13:54'),
(39, 1, 'EXPENSE', 'Added Expense: Duty Charge', NULL, 'Rs. 20,000.00', 1, '2026-03-21 16:13:54'),
(40, 1, 'PAYMENT', 'Payment Method: Cash', NULL, 'Rs. 45,000.00', 1, '2026-03-21 16:13:54'),
(41, 1, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-21 16:13:54'),
(42, 1, 'EXPENSE', 'Added Expense: Transport', NULL, 'Rs. 45,000.00', 1, '2026-03-21 16:13:58'),
(43, 1, 'EXPENSE', 'Added Expense: Duty Charge', NULL, 'Rs. 20,000.00', 1, '2026-03-21 16:13:58'),
(44, 1, 'PAYMENT', 'Payment Method: Cash', NULL, 'Rs. 45,000.00', 1, '2026-03-21 16:13:58'),
(45, 1, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-21 16:13:58'),
(46, 21, 'CREATED', NULL, NULL, NULL, 1, '2026-03-21 16:14:03'),
(47, 21, 'UPDATE', 'country', '', 'Dubai', 1, '2026-03-21 16:14:08'),
(48, 21, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-21 16:14:08'),
(49, 21, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-21 16:14:10'),
(50, 21, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-21 16:14:11'),
(51, 21, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-21 16:14:13'),
(52, 21, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-21 16:14:15'),
(53, 21, 'UPDATE', 'total_qty', '0', '280', 1, '2026-03-21 16:14:17'),
(54, 21, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-21 16:14:17'),
(55, 21, 'UPDATE', 'total_qty', '280', '400', 1, '2026-03-21 16:14:18'),
(56, 21, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-21 16:14:18'),
(57, 21, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-21 16:14:19'),
(58, 21, 'UPDATE', 'total_expenses', '0.00', '50000', 1, '2026-03-21 16:14:21'),
(59, 21, 'UPDATE', 'container_cost', '0.00', '50000', 1, '2026-03-21 16:14:21'),
(60, 21, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-21 16:14:21'),
(61, 21, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-21 16:14:23'),
(62, 21, 'UPDATE', 'total_expenses', '50000.00', '75000', 1, '2026-03-21 16:14:25'),
(63, 21, 'EXPENSE', 'Added Expense: Transport', NULL, 'Rs. 25,000.00', 1, '2026-03-21 16:14:25'),
(64, 21, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-21 16:14:25'),
(65, 21, 'EXPENSE', 'Added Expense: Transport', NULL, 'Rs. 25,000.00', 1, '2026-03-21 16:14:27'),
(66, 21, 'PAYMENT', 'Payment Method: Cash', NULL, 'Rs. 75,000.00', 1, '2026-03-21 16:14:27'),
(67, 21, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-21 16:14:27'),
(68, 21, 'EXPENSE', 'Added Expense: Transport', NULL, 'Rs. 25,000.00', 1, '2026-03-21 16:14:31'),
(69, 21, 'PAYMENT', 'Payment Method: Cash', NULL, 'Rs. 75,000.00', 1, '2026-03-21 16:14:31'),
(70, 21, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-21 16:14:31'),
(71, 21, 'EXPENSE', 'Added Expense: Transport', NULL, 'Rs. 25,000.00', 1, '2026-03-21 16:14:32'),
(72, 21, 'PAYMENT', 'Payment Method: Cash', NULL, 'Rs. 75,000.00', 1, '2026-03-21 16:14:32'),
(73, 21, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-21 16:14:32'),
(74, 21, 'UPDATE', 'damaged_qty', '0', '5', 1, '2026-03-21 16:14:34'),
(75, 21, 'EXPENSE', 'Added Expense: Transport', NULL, 'Rs. 25,000.00', 1, '2026-03-21 16:14:34'),
(76, 21, 'PAYMENT', 'Payment Method: Cash', NULL, 'Rs. 75,000.00', 1, '2026-03-21 16:14:34'),
(77, 21, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-21 16:14:34'),
(78, 21, 'EXPENSE', 'Added Expense: Transport', NULL, 'Rs. 25,000.00', 1, '2026-03-21 16:14:35'),
(79, 21, 'PAYMENT', 'Payment Method: Cash', NULL, 'Rs. 75,000.00', 1, '2026-03-21 16:14:35'),
(80, 21, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-21 16:14:35'),
(81, 21, 'EXPENSE', 'Added Expense: Transport', NULL, 'Rs. 25,000.00', 1, '2026-03-21 16:14:54'),
(82, 21, 'PAYMENT', 'Payment Method: Cash', NULL, 'Rs. 75,000.00', 1, '2026-03-21 16:14:54'),
(83, 21, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-21 16:14:54'),
(84, 21, 'EXPENSE', 'Added Expense: Transport', NULL, 'Rs. 25,000.00', 1, '2026-03-21 16:14:57'),
(85, 21, 'PAYMENT', 'Payment Method: Cash', NULL, 'Rs. 75,000.00', 1, '2026-03-21 16:14:57'),
(86, 21, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-21 16:14:57');

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

--
-- Dumping data for table `container_payments`
--

INSERT INTO `container_payments` (`id`, `container_id`, `payment_id`, `payment_type`, `method`, `amount`, `description`, `payment_date`) VALUES
(4, 1, 'TX-141268', '', 'Cash', 45000.00, '', '2026-03-21 16:13:58'),
(11, 21, 'TX-492743', 'paid fully', 'Cash', 75000.00, '', '2026-03-21 16:14:57');

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

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`id`, `name`, `contact_number`, `address`, `created_at`) VALUES
(1, 'kamal', '12212', 'Colombo', '2026-03-21 16:16:37'),
(2, 'laal', 'sdsds', 'dsdsd', '2026-03-21 16:18:04'),
(3, 'perera', '12222', '', '2026-03-21 16:19:49');

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

--
-- Dumping data for table `deliveries`
--

INSERT INTO `deliveries` (`id`, `delivery_date`, `total_expenses`, `total_sales`, `created_by`, `status`, `created_at`) VALUES
(1, '2026-03-21', 16200.00, 42056.00, 1, 'pending', '2026-03-21 16:17:13'),
(2, '2026-03-21', 14000.00, 127000.00, 1, 'pending', '2026-03-21 16:18:45'),
(3, '2026-03-21', 25000.00, 50000.00, 1, 'pending', '2026-03-21 16:25:47'),
(4, '2026-03-21', 10000.00, 42000.00, 1, 'pending', '2026-03-21 16:27:55');

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

--
-- Dumping data for table `delivery_customers`
--

INSERT INTO `delivery_customers` (`id`, `delivery_id`, `customer_id`, `subtotal`, `status`, `created_at`, `discount`, `payment_status`, `bill_number`) VALUES
(8, 2, 4, 15000.00, 'pending', '2026-03-18 08:59:29', 0.00, 'completed', NULL),
(9, 1, 1, 43028.00, 'pending', '2026-03-21 16:17:14', 972.00, 'pending', '12345'),
(10, 2, 2, 113000.00, 'pending', '2026-03-21 16:18:45', 1000.00, 'pending', '366'),
(11, 3, 1, 50000.00, 'pending', '2026-03-21 16:25:47', 0.00, 'pending', '2026'),
(12, 4, 1, 42000.00, 'pending', '2026-03-21 16:27:55', 1000.00, 'pending', '');

-- --------------------------------------------------------

--
-- Table structure for table `delivery_employees`
--

CREATE TABLE `delivery_employees` (
  `delivery_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `delivery_employees`
--

INSERT INTO `delivery_employees` (`delivery_id`, `user_id`) VALUES
(1, 5),
(2, 5),
(3, 6),
(4, 6);

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

--
-- Dumping data for table `delivery_expenses`
--

INSERT INTO `delivery_expenses` (`id`, `delivery_id`, `expense_name`, `amount`) VALUES
(1, 1, 'Fuel', 15000.00),
(2, 1, 'Meals', 1200.00),
(3, 2, 'Fuel', 14000.00),
(4, 3, 'Fuel', 25000.00),
(5, 4, 'Fuel', 10000.00);

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
  `container_item_id` int(11) NOT NULL,
  `qty` int(11) NOT NULL,
  `cost_price` decimal(15,2) NOT NULL,
  `selling_price` decimal(15,2) NOT NULL,
  `total` decimal(15,2) NOT NULL,
  `damaged_qty` int(11) DEFAULT 0,
  `bill_image` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `delivery_items`
--

INSERT INTO `delivery_items` (`id`, `delivery_customer_id`, `container_item_id`, `qty`, `cost_price`, `selling_price`, `total`, `damaged_qty`, `bill_image`) VALUES
(1, 9, 34, 120, 189.87, 400.00, 43028.00, 10, NULL),
(2, 10, 17, 100, 818.18, 1200.00, 113000.00, 5, NULL),
(3, 11, 34, 100, 189.87, 500.00, 50000.00, 0, NULL),
(4, 12, 34, 100, 189.87, 450.00, 40500.00, 10, NULL),
(5, 12, 17, 10, 818.18, 500.00, 1500.00, 5, NULL);

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

--
-- Dumping data for table `delivery_ledger`
--

INSERT INTO `delivery_ledger` (`id`, `delivery_id`, `action_type`, `notes`, `performed_by`, `performed_at`) VALUES
(1, 1, 'CREATED', 'Route details started for 2026-03-21.', 1, '2026-03-21 16:17:14'),
(2, 2, 'CREATED', 'Route details started for 2026-03-21.', 1, '2026-03-21 16:18:45'),
(3, 3, 'CREATED', 'Route details started for 2026-03-21.', 1, '2026-03-21 16:25:47'),
(4, 4, 'CREATED', 'Route details started for 2026-03-21.', 1, '2026-03-21 16:27:55');

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

--
-- Dumping data for table `delivery_payments`
--

INSERT INTO `delivery_payments` (`id`, `delivery_customer_id`, `amount`, `payment_type`, `bank_id`, `cheque_number`, `cheque_payer_name`, `proof_image`, `payment_date`, `recorded_by`, `created_at`, `due_date`, `cheque_customer_id`) VALUES
(1, 12, 35000.00, 'Cheque', 1, '2025', 'kamal', NULL, '2026-03-21', 1, '2026-03-21 16:29:07', NULL, NULL);

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

--
-- Dumping data for table `delivery_proof_photos`
--

INSERT INTO `delivery_proof_photos` (`id`, `delivery_customer_id`, `photo_path`, `uploaded_by`, `uploaded_at`) VALUES
(1, 9, '1774109832_1889_proof_Screenshot4.png', 1, '2026-03-21 16:17:14'),
(2, 10, '1774109892_1443_proof_amex.png', 1, '2026-03-21 16:18:45'),
(3, 12, '1774110447_3076_proof_Screenshot2025-12-19205009.png', 1, '2026-03-21 16:27:55');

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

--
-- Dumping data for table `employee_salary_payments`
--

INSERT INTO `employee_salary_payments` (`id`, `user_id`, `salary_month`, `salary_year`, `deliveries_count`, `salary_amount`, `payment_date`, `status`, `paid_at`, `recorded_by`, `created_at`, `updated_at`) VALUES
(1, 5, 2, 2026, 0, 25000.00, '2026-03-21', 'paid', '2026-03-21 16:31:04', 1, '2026-03-21 16:31:04', '2026-03-21 16:31:04');

-- --------------------------------------------------------

--
-- Table structure for table `employee_salary_settings`
--

CREATE TABLE `employee_salary_settings` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `monthly_salary` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `employee_salary_settings`
--

INSERT INTO `employee_salary_settings` (`id`, `user_id`, `monthly_salary`, `created_at`, `updated_at`) VALUES
(1, 5, 25000.00, '2026-03-21 16:16:13', '2026-03-21 16:16:13'),
(2, 6, 12000.00, '2026-03-21 16:25:12', '2026-03-21 16:25:12');

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

--
-- Dumping data for table `pos_sales`
--

INSERT INTO `pos_sales` (`id`, `bill_id`, `sale_date`, `customer_id`, `created_by`, `subtotal`, `item_discount`, `bill_discount`, `bill_discount_type`, `grand_total`, `payment_method`, `payment_status`, `notes`, `created_at`, `updated_at`) VALUES
(1, 'POS-20260321-3625', '2026-03-21', 3, 1, 8000.00, 100.00, 400.00, 'percent', 7600.00, 'Account Transfer', 'completed', NULL, '2026-03-21 16:19:51', '2026-03-21 16:20:52');

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

--
-- Dumping data for table `pos_sale_audits`
--

INSERT INTO `pos_sale_audits` (`id`, `sale_id`, `action_type`, `field_name`, `old_value`, `new_value`, `notes`, `changed_by`, `changed_at`) VALUES
(1, 1, 'CREATED', NULL, NULL, NULL, 'New sale created â€” Bill Total: LKR 0.00', 1, '2026-03-21 16:19:51'),
(2, 1, 'EDITED', NULL, NULL, NULL, 'Sale updated â€” Bill Total: LKR 0.00', 1, '2026-03-21 16:19:59'),
(3, 1, 'EDITED', NULL, NULL, NULL, 'Sale updated â€” Bill Total: LKR 0.00', 1, '2026-03-21 16:20:03'),
(4, 1, 'EDITED', NULL, NULL, NULL, 'Sale updated â€” Bill Total: LKR 0.00', 1, '2026-03-21 16:20:05'),
(5, 1, 'EDITED', NULL, NULL, NULL, 'Sale updated â€” Bill Total: LKR 9,000.00', 1, '2026-03-21 16:20:11'),
(6, 1, 'EDITED', NULL, NULL, NULL, 'Sale updated â€” Bill Total: LKR 8,900.00', 1, '2026-03-21 16:20:16'),
(7, 1, 'EDITED', NULL, NULL, NULL, 'Sale updated â€” Bill Total: LKR 8,000.00', 1, '2026-03-21 16:20:19'),
(8, 1, 'EDITED', NULL, NULL, NULL, 'Sale updated â€” Bill Total: LKR 7,600.00', 1, '2026-03-21 16:20:22'),
(9, 1, 'EDITED', NULL, NULL, NULL, 'Sale updated â€” Bill Total: LKR 7,600.00', 1, '2026-03-21 16:20:28'),
(10, 1, 'PAYMENT_ADDED', NULL, NULL, NULL, 'Payment of LKR 1,500.00 via Account Transfer added.', 1, '2026-03-21 16:20:28');

-- --------------------------------------------------------

--
-- Table structure for table `pos_sale_items`
--

CREATE TABLE `pos_sale_items` (
  `id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `container_item_id` int(11) NOT NULL,
  `qty` int(11) NOT NULL,
  `damaged_qty` int(11) DEFAULT 0,
  `cost_price` decimal(15,2) NOT NULL,
  `selling_price` decimal(15,2) NOT NULL,
  `item_discount` decimal(15,2) DEFAULT 0.00,
  `line_total` decimal(15,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pos_sale_items`
--

INSERT INTO `pos_sale_items` (`id`, `sale_id`, `container_item_id`, `qty`, `damaged_qty`, `cost_price`, `selling_price`, `item_discount`, `line_total`) VALUES
(8, 1, 34, 20, 2, 189.87, 450.00, 100.00, 8000.00);

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

--
-- Dumping data for table `pos_sale_payments`
--

INSERT INTO `pos_sale_payments` (`id`, `sale_id`, `amount`, `payment_type`, `bank_id`, `cheque_number`, `cheque_payer_name`, `proof_image`, `payment_date`, `recorded_by`, `created_at`) VALUES
(1, 1, 1500.00, 'Account Transfer', NULL, '', '', NULL, '2026-03-21', 1, '2026-03-21 16:20:28'),
(2, 1, 6100.00, 'Cash', NULL, NULL, NULL, NULL, '2026-03-21', 1, '2026-03-21 16:20:52');

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
(6, NULL, NULL, 'employee', 'sugath', '122', NULL, NULL, NULL, '2026-03-21 16:25:12');

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
  ADD KEY `container_item_id` (`container_item_id`);

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
  ADD KEY `container_item_id` (`container_item_id`);

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `brands`
--
ALTER TABLE `brands`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `containers`
--
ALTER TABLE `containers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- AUTO_INCREMENT for table `container_expenses`
--
ALTER TABLE `container_expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `container_items`
--
ALTER TABLE `container_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- AUTO_INCREMENT for table `container_ledger`
--
ALTER TABLE `container_ledger`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=87;

--
-- AUTO_INCREMENT for table `container_payments`
--
ALTER TABLE `container_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `deliveries`
--
ALTER TABLE `deliveries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `delivery_customers`
--
ALTER TABLE `delivery_customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `delivery_expenses`
--
ALTER TABLE `delivery_expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `delivery_field_expenses`
--
ALTER TABLE `delivery_field_expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `delivery_items`
--
ALTER TABLE `delivery_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `delivery_item_damages`
--
ALTER TABLE `delivery_item_damages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `delivery_ledger`
--
ALTER TABLE `delivery_ledger`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `delivery_payments`
--
ALTER TABLE `delivery_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `delivery_proof_photos`
--
ALTER TABLE `delivery_proof_photos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `employee_salary_payments`
--
ALTER TABLE `employee_salary_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `employee_salary_settings`
--
ALTER TABLE `employee_salary_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `pos_sales`
--
ALTER TABLE `pos_sales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `pos_sale_audits`
--
ALTER TABLE `pos_sale_audits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `pos_sale_items`
--
ALTER TABLE `pos_sale_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `pos_sale_payments`
--
ALTER TABLE `pos_sale_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

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
  ADD CONSTRAINT `delivery_items_ibfk_1` FOREIGN KEY (`delivery_customer_id`) REFERENCES `delivery_customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `delivery_items_ibfk_2` FOREIGN KEY (`container_item_id`) REFERENCES `container_items` (`id`);

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
  ADD CONSTRAINT `pos_sale_items_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `pos_sales` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `pos_sale_items_ibfk_2` FOREIGN KEY (`container_item_id`) REFERENCES `container_items` (`id`);

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
