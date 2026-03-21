-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 21, 2026 at 11:06 AM
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
(1, 'Bank of ceylon', '2026-03-14 05:53:08', '1456333', 'bashitha'),
(2, 'sampath', '2026-03-18 11:33:04', '123444444444', 'my crystal'),
(3, 'HNB', '2026-03-18 11:34:10', '121212111', 'hnb acc'),
(4, 'commercial', '2026-03-21 06:56:13', '1238790', 'nim'),
(5, 'NDB', '2026-03-21 09:57:23', '12221', '21212');

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
(2, '15mm'),
(4, '17mm'),
(1, '18mm'),
(3, 'TestBrand Generator');

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
(1, '0001', '2026-03-13', 1, 70000.00, 45000.00, 120, 10, 636.36, 'china', '2026-03-13 14:45:20'),
(2, '0002', '2026-03-13', 1, 60000.00, 45000.00, 60, 10, 1200.00, 'Dubai', '2026-03-13 14:46:05'),
(3, '0003', '2026-03-18', 1, 5000.00, 5000.00, 500, 0, 10.00, 'Test Delivery 1', '2026-03-18 08:40:34'),
(4, '0004', '2026-03-18', 1, 5000.00, 5000.00, 500, 0, 10.00, 'Test Delivery 2', '2026-03-18 08:40:40'),
(5, '0005', '2026-03-18', 1, 5000.00, 5000.00, 500, 0, 10.00, 'Test Delivery 3', '2026-03-18 08:40:46'),
(6, '0006', '2026-03-18', 1, 157000.00, 45000.00, 1500, 15, 105.72, 'Dubai', '2026-03-18 08:40:52'),
(9, '0009', '2026-01-05', 1, 139000.00, 45000.00, 1000, 25, 142.56, 'china', '2026-03-18 08:41:11');

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
(1, 1, 'Transport', 15000.00),
(2, 1, 'Duty Charge', 10000.00),
(3, 2, 'Transport', 10000.00),
(4, 2, 'Duty Charge', 5000.00),
(33, 6, 'Transport', 47000.00),
(34, 6, 'Duty Charge', 65000.00),
(41, 9, 'Tax', 24000.00),
(42, 9, 'Transport', 25000.00),
(43, 9, 'Insuarance', 45000.00);

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
(1, 1, 1, 10, 12, 120, 115),
(2, 2, 2, 10, 6, 60, 10),
(3, 3, 3, 10, 50, 500, 0),
(4, 4, 3, 10, 50, 500, 0),
(5, 5, 3, 10, 50, 500, 0),
(37, 6, 2, 75, 20, 1500, 110),
(40, 9, 4, 20, 50, 1000, 500);

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
(1, 1, 'EXPENSE', 'Added Expense: Transport', NULL, 'Rs. 15,000.00', 1, '2026-03-13 14:45:20'),
(2, 1, 'EXPENSE', 'Added Expense: Duty Charge', NULL, 'Rs. 10,000.00', 1, '2026-03-13 14:45:20'),
(3, 1, 'PAYMENT', 'Payment Method: Cheque', NULL, 'Rs. 35,000.00', 1, '2026-03-13 14:45:20'),
(4, 1, 'CREATED', NULL, NULL, NULL, 1, '2026-03-13 14:45:20'),
(5, 2, 'EXPENSE', 'Added Expense: Transport', NULL, 'Rs. 10,000.00', 1, '2026-03-13 14:46:05'),
(6, 2, 'EXPENSE', 'Added Expense: Duty Charge', NULL, 'Rs. 5,000.00', 1, '2026-03-13 14:46:05'),
(7, 2, 'CREATED', NULL, NULL, NULL, 1, '2026-03-13 14:46:05'),
(8, 3, 'CREATED', NULL, NULL, NULL, 1, '2026-03-18 08:40:34'),
(9, 4, 'CREATED', NULL, NULL, NULL, 1, '2026-03-18 08:40:40'),
(10, 5, 'CREATED', NULL, NULL, NULL, 1, '2026-03-18 08:40:46'),
(11, 6, 'CREATED', NULL, NULL, NULL, 1, '2026-03-18 08:40:52'),
(14, 9, 'CREATED', NULL, NULL, NULL, 1, '2026-03-18 08:41:11'),
(18, 9, 'UPDATE', 'total_qty', '500', '1000', 1, '2026-03-18 08:44:25'),
(19, 9, 'UPDATE', 'damaged_qty', '0', '25', 1, '2026-03-18 08:44:25'),
(20, 9, 'UPDATE', 'total_expenses', '5000.00', '139000', 1, '2026-03-18 08:44:25'),
(21, 9, 'UPDATE', 'country', 'Test Delivery 7', 'china', 1, '2026-03-18 08:44:25'),
(22, 9, 'UPDATE', 'container_cost', '5000.00', '45000', 1, '2026-03-18 08:44:25'),
(23, 9, 'EXPENSE', 'Added Expense: Tax', NULL, 'Rs. 24,000.00', 1, '2026-03-18 08:44:25'),
(24, 9, 'EXPENSE', 'Added Expense: Transport', NULL, 'Rs. 25,000.00', 1, '2026-03-18 08:44:25'),
(25, 9, 'EXPENSE', 'Added Expense: Insuarance', NULL, 'Rs. 45,000.00', 1, '2026-03-18 08:44:25'),
(26, 9, 'PAYMENT', 'Payment Method: Cash', NULL, 'Rs. 60,000.00', 1, '2026-03-18 08:44:25'),
(27, 9, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-18 08:44:25'),
(28, 9, 'EXPENSE', 'Added Expense: Tax', NULL, 'Rs. 24,000.00', 1, '2026-03-18 08:44:54'),
(29, 9, 'EXPENSE', 'Added Expense: Transport', NULL, 'Rs. 25,000.00', 1, '2026-03-18 08:44:54'),
(30, 9, 'EXPENSE', 'Added Expense: Insuarance', NULL, 'Rs. 45,000.00', 1, '2026-03-18 08:44:54'),
(31, 9, 'PAYMENT', 'Payment Method: Cash', NULL, 'Rs. 60,000.00', 1, '2026-03-18 08:44:54'),
(32, 9, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-18 08:44:54'),
(33, 6, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-18 08:45:19'),
(34, 6, 'UPDATE', 'country', 'Test Delivery 4', 'Dubai', 1, '2026-03-18 08:45:22'),
(35, 6, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-18 08:45:22'),
(36, 6, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-18 08:45:25'),
(37, 6, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-18 08:45:26'),
(38, 6, 'UPDATE', 'total_qty', '500', '3750', 1, '2026-03-18 08:45:33'),
(39, 6, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-18 08:45:33'),
(40, 6, 'UPDATE', 'total_qty', '3750', '1500', 1, '2026-03-18 08:45:35'),
(41, 6, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-18 08:45:35'),
(42, 6, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-18 08:45:38'),
(43, 6, 'UPDATE', 'total_expenses', '5000.00', '45000', 1, '2026-03-18 08:45:40'),
(44, 6, 'UPDATE', 'container_cost', '5000.00', '45000', 1, '2026-03-18 08:45:40'),
(45, 6, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-18 08:45:40'),
(46, 6, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-18 08:45:41'),
(47, 6, 'UPDATE', 'total_expenses', '45000.00', '49700', 1, '2026-03-18 08:45:44'),
(48, 6, 'EXPENSE', 'Added Expense: Transport', NULL, 'Rs. 4,700.00', 1, '2026-03-18 08:45:44'),
(49, 6, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-18 08:45:44'),
(50, 6, 'UPDATE', 'total_expenses', '49700.00', '92000', 1, '2026-03-18 08:45:45'),
(51, 6, 'EXPENSE', 'Added Expense: Transport', NULL, 'Rs. 47,000.00', 1, '2026-03-18 08:45:45'),
(52, 6, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-18 08:45:45'),
(53, 6, 'EXPENSE', 'Added Expense: Transport', NULL, 'Rs. 47,000.00', 1, '2026-03-18 08:45:47'),
(54, 6, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-18 08:45:47'),
(55, 6, 'EXPENSE', 'Added Expense: Transport', NULL, 'Rs. 47,000.00', 1, '2026-03-18 08:45:49'),
(56, 6, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-18 08:45:49'),
(57, 6, 'UPDATE', 'total_expenses', '92000.00', '157000', 1, '2026-03-18 08:45:51'),
(58, 6, 'EXPENSE', 'Added Expense: Transport', NULL, 'Rs. 47,000.00', 1, '2026-03-18 08:45:51'),
(59, 6, 'EXPENSE', 'Added Expense: Duty Charge', NULL, 'Rs. 65,000.00', 1, '2026-03-18 08:45:51'),
(60, 6, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-18 08:45:51'),
(61, 6, 'EXPENSE', 'Added Expense: Transport', NULL, 'Rs. 47,000.00', 1, '2026-03-18 08:45:54'),
(62, 6, 'EXPENSE', 'Added Expense: Duty Charge', NULL, 'Rs. 65,000.00', 1, '2026-03-18 08:45:54'),
(63, 6, 'PAYMENT', 'Payment Method: Cash', NULL, 'Rs. 157,000.00', 1, '2026-03-18 08:45:54'),
(64, 6, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-18 08:45:54'),
(65, 6, 'EXPENSE', 'Added Expense: Transport', NULL, 'Rs. 47,000.00', 1, '2026-03-18 08:45:57'),
(66, 6, 'EXPENSE', 'Added Expense: Duty Charge', NULL, 'Rs. 65,000.00', 1, '2026-03-18 08:45:57'),
(67, 6, 'PAYMENT', 'Payment Method: Cash', NULL, 'Rs. 157,000.00', 1, '2026-03-18 08:45:57'),
(68, 6, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-18 08:45:57'),
(69, 6, 'EXPENSE', 'Added Expense: Transport', NULL, 'Rs. 47,000.00', 1, '2026-03-18 08:46:05'),
(70, 6, 'EXPENSE', 'Added Expense: Duty Charge', NULL, 'Rs. 65,000.00', 1, '2026-03-18 08:46:05'),
(71, 6, 'PAYMENT', 'Payment Method: Cash', NULL, 'Rs. 45,000.00', 1, '2026-03-18 08:46:05'),
(72, 6, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-18 08:46:05'),
(73, 6, 'EXPENSE', 'Added Expense: Transport', NULL, 'Rs. 47,000.00', 1, '2026-03-18 08:46:06'),
(74, 6, 'EXPENSE', 'Added Expense: Duty Charge', NULL, 'Rs. 65,000.00', 1, '2026-03-18 08:46:06'),
(75, 6, 'PAYMENT', 'Payment Method: Cash', NULL, 'Rs. 45,000.00', 1, '2026-03-18 08:46:06'),
(76, 6, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-18 08:46:06'),
(77, 6, 'EXPENSE', 'Added Expense: Transport', NULL, 'Rs. 47,000.00', 1, '2026-03-18 08:46:12'),
(78, 6, 'EXPENSE', 'Added Expense: Duty Charge', NULL, 'Rs. 65,000.00', 1, '2026-03-18 08:46:12'),
(79, 6, 'PAYMENT', 'Payment Method: Cash', NULL, 'Rs. 45,000.00', 1, '2026-03-18 08:46:12'),
(80, 6, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-18 08:46:12'),
(81, 6, 'EXPENSE', 'Added Expense: Transport', NULL, 'Rs. 47,000.00', 1, '2026-03-18 08:46:15'),
(82, 6, 'EXPENSE', 'Added Expense: Duty Charge', NULL, 'Rs. 65,000.00', 1, '2026-03-18 08:46:15'),
(83, 6, 'PAYMENT', 'Payment Method: Cash', NULL, 'Rs. 45,000.00', 1, '2026-03-18 08:46:15'),
(84, 6, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-18 08:46:15'),
(85, 6, 'UPDATE', 'damaged_qty', '0', '25', 1, '2026-03-18 08:46:16'),
(86, 6, 'EXPENSE', 'Added Expense: Transport', NULL, 'Rs. 47,000.00', 1, '2026-03-18 08:46:16'),
(87, 6, 'EXPENSE', 'Added Expense: Duty Charge', NULL, 'Rs. 65,000.00', 1, '2026-03-18 08:46:16'),
(88, 6, 'PAYMENT', 'Payment Method: Cash', NULL, 'Rs. 45,000.00', 1, '2026-03-18 08:46:16'),
(89, 6, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-18 08:46:16'),
(90, 6, 'UPDATE', 'damaged_qty', '25', '15', 1, '2026-03-18 08:46:18'),
(91, 6, 'EXPENSE', 'Added Expense: Transport', NULL, 'Rs. 47,000.00', 1, '2026-03-18 08:46:18'),
(92, 6, 'EXPENSE', 'Added Expense: Duty Charge', NULL, 'Rs. 65,000.00', 1, '2026-03-18 08:46:18'),
(93, 6, 'PAYMENT', 'Payment Method: Cash', NULL, 'Rs. 45,000.00', 1, '2026-03-18 08:46:18'),
(94, 6, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-18 08:46:18'),
(95, 6, 'EXPENSE', 'Added Expense: Transport', NULL, 'Rs. 47,000.00', 1, '2026-03-18 08:46:19'),
(96, 6, 'EXPENSE', 'Added Expense: Duty Charge', NULL, 'Rs. 65,000.00', 1, '2026-03-18 08:46:19'),
(97, 6, 'PAYMENT', 'Payment Method: Cash', NULL, 'Rs. 45,000.00', 1, '2026-03-18 08:46:19'),
(98, 6, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-18 08:46:19'),
(99, 9, 'EXPENSE', 'Added Expense: Tax', NULL, 'Rs. 24,000.00', 1, '2026-03-18 08:46:23'),
(100, 9, 'EXPENSE', 'Added Expense: Transport', NULL, 'Rs. 25,000.00', 1, '2026-03-18 08:46:23'),
(101, 9, 'EXPENSE', 'Added Expense: Insuarance', NULL, 'Rs. 45,000.00', 1, '2026-03-18 08:46:23'),
(102, 9, 'PAYMENT', 'Payment Method: Cash', NULL, 'Rs. 60,000.00', 1, '2026-03-18 08:46:23'),
(103, 9, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-18 08:46:23'),
(104, 9, 'EXPENSE', 'Added Expense: Tax', NULL, 'Rs. 24,000.00', 1, '2026-03-18 08:46:26'),
(105, 9, 'EXPENSE', 'Added Expense: Transport', NULL, 'Rs. 25,000.00', 1, '2026-03-18 08:46:26'),
(106, 9, 'EXPENSE', 'Added Expense: Insuarance', NULL, 'Rs. 45,000.00', 1, '2026-03-18 08:46:26'),
(107, 9, 'PAYMENT', 'Payment Method: Cash', NULL, 'Rs. 60,000.00', 1, '2026-03-18 08:46:26'),
(108, 9, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-18 08:46:26'),
(109, 9, 'EXPENSE', 'Added Expense: Tax', NULL, 'Rs. 24,000.00', 1, '2026-03-18 08:46:27'),
(110, 9, 'EXPENSE', 'Added Expense: Transport', NULL, 'Rs. 25,000.00', 1, '2026-03-18 08:46:27'),
(111, 9, 'EXPENSE', 'Added Expense: Insuarance', NULL, 'Rs. 45,000.00', 1, '2026-03-18 08:46:27'),
(112, 9, 'PAYMENT', 'Payment Method: Cash', NULL, 'Rs. 60,000.00', 1, '2026-03-18 08:46:27'),
(113, 9, 'UPDATED', NULL, NULL, NULL, 1, '2026-03-18 08:46:27');

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
(1, 1, 'TX-645280', 'paid advance', 'Cheque', 35000.00, '', '2026-03-13 14:45:20'),
(12, 6, 'TX-178467', 'paid for container', 'Cash', 45000.00, '', '2026-03-18 08:46:19'),
(15, 9, 'TX-340895', 'advance paid to container', 'Cash', 60000.00, '', '2026-03-18 08:46:27');

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
(1, 'bashitha', '223232', 'Colombo', '2026-03-13 14:52:00'),
(2, 'nimesh 123', '22222', 'colombo', '2026-03-14 03:55:37'),
(3, 'sunil', '478965', 'Colombo', '2026-03-18 08:57:22'),
(4, 'kamal', '23232323', 'Bambalapitiya', '2026-03-18 08:58:33');

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
(1, '2026-03-13', 30000.00, 67500.00, 1, 'pending', '2026-03-13 14:58:01'),
(2, '2026-03-18', 31000.00, 325000.00, 1, 'pending', '2026-03-18 08:59:29');

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
  `payment_status` enum('pending','completed') DEFAULT 'pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `delivery_customers`
--

INSERT INTO `delivery_customers` (`id`, `delivery_id`, `customer_id`, `subtotal`, `status`, `created_at`, `discount`, `payment_status`) VALUES
(6, 1, 1, 67500.00, 'pending', '2026-03-14 06:00:48', 0.00, 'completed'),
(7, 2, 3, 310000.00, 'pending', '2026-03-18 08:59:29', 0.00, 'completed'),
(8, 2, 4, 15000.00, 'pending', '2026-03-18 08:59:29', 0.00, 'completed');

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
(1, 2),
(1, 3),
(2, 3);

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
(12, 1, 'Fuel', 30000.00),
(29, 2, 'Meals', 1000.00),
(30, 2, 'Fuel', 30000.00);

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
(8, 6, 1, 100, 636.36, 750.00, 67500.00, 10, '1773468513_0_route_bill_WhatsApp Image 2026-02-18 at 15.26.43.jpeg'),
(25, 7, 2, 10, 1200.00, 1600.00, 16000.00, 0, NULL),
(26, 7, 40, 500, 142.56, 600.00, 294000.00, 10, NULL),
(27, 8, 1, 15, 636.36, 1000.00, 15000.00, 0, NULL);

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
(1, 1, 'CREATED', 'Route started for 2026-03-13.', 1, '2026-03-13 14:58:01'),
(2, 1, '', 'Route details modified for 2026-03-13.', 1, '2026-03-14 05:42:47'),
(3, 1, '', 'Route details modified for 2026-03-13.', 1, '2026-03-14 05:43:13'),
(4, 1, 'EDITED', 'Route details modified for 2026-03-13.', 1, '2026-03-14 06:00:09'),
(5, 1, 'EDITED', 'Route details modified for 2026-03-13.', 1, '2026-03-14 06:00:48'),
(6, 1, 'EDITED', 'Route details modified for 2026-03-13.', 1, '2026-03-14 06:05:06'),
(7, 1, 'EDITED', 'Route details modified for 2026-03-13.', 1, '2026-03-14 06:08:33'),
(8, 1, 'EDITED', 'Route details modified for 2026-03-13.', 1, '2026-03-14 06:17:57'),
(9, 2, 'CREATED', 'Route details started for 2026-03-18.', 1, '2026-03-18 08:59:29'),
(10, 2, 'EDITED', 'Route details modified for 2026-03-18.', 1, '2026-03-18 09:31:31'),
(11, 2, 'EDITED', 'Route details modified for 2026-03-18.', 1, '2026-03-18 10:06:40'),
(12, 2, 'EDITED', 'Route details modified for 2026-03-18.', 1, '2026-03-18 10:27:39'),
(13, 2, 'EDITED', 'Route details modified for 2026-03-18.', 1, '2026-03-18 10:28:17'),
(14, 2, 'EDITED', 'Route details modified for 2026-03-18.', 1, '2026-03-18 10:37:45'),
(15, 2, 'EDITED', 'Route details modified for 2026-03-18.', 1, '2026-03-18 10:46:56'),
(16, 2, 'EDITED', 'Route details modified for 2026-03-18.', 1, '2026-03-18 10:47:08'),
(17, 2, 'EDITED', 'Route details modified for 2026-03-18.', 1, '2026-03-18 12:00:20');

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
(3, 6, 3500.00, 'Cash', NULL, NULL, NULL, NULL, '2026-03-14', 1, '2026-03-14 06:04:46', NULL, NULL),
(4, 6, 64000.00, 'Account Transfer', 1, NULL, NULL, '1773469107_WhatsApp Image 2026-02-18 at 15.26.43.jpeg', '2026-03-14', 1, '2026-03-14 06:18:27', NULL, NULL),
(6, 7, 14000.00, 'Cheque', 1, '12345678', NULL, '1773825448_WhatsApp Image 2026-03-16 at 12.25.38 (1).jpeg', '2026-03-18', 1, '2026-03-18 09:17:28', NULL, 3),
(8, 7, 2000.00, 'Account Transfer', 2, NULL, NULL, NULL, '2026-03-18', 1, '2026-03-18 11:33:06', NULL, 3),
(9, 8, 5000.00, 'Account Transfer', 3, NULL, NULL, NULL, '2026-03-18', 1, '2026-03-18 11:34:12', NULL, NULL),
(10, 8, 10000.00, 'Cheque', 4, '7895662', NULL, NULL, '2026-03-21', 1, '2026-03-21 06:58:32', NULL, 4);

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
(2, 7, '1773829659_0_0_route_bill_Screenshot 2025-12-19 205009.png', 1, '2026-03-18 10:27:39'),
(3, 7, '1773829697_0_0_route_bill_Screenshot (4).png', 1, '2026-03-18 10:28:17'),
(5, 7, '1773830824_4219_proof_Screenshot2025-12-26170150.png', 1, '2026-03-18 10:47:08'),
(6, 7, '1773830828_8817_proof_Screenshot2025-12-19205035.png', 1, '2026-03-18 10:47:08');

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
(1, 2, 3, 2026, 1, 30000.00, '2026-03-18', 'paid', '2026-03-18 10:38:31', 1, '2026-03-18 10:38:31', '2026-03-18 10:38:31');

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
(1, 2, 30000.00, '2026-03-18 10:38:31', '2026-03-18 10:38:31');

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
(1, 'POS-20260321-3513', '2026-03-21', 2, 1, 7900.00, 500.00, 0.00, 'fixed', 7900.00, 'Cash', 'completed', NULL, '2026-03-21 09:31:53', '2026-03-21 09:31:53'),
(2, 'POS-20260321-1604', '2026-03-21', 2, 1, 18000.00, 0.00, 0.00, 'fixed', 18000.00, 'Account Transfer', 'pending', NULL, '2026-03-21 09:55:38', '2026-03-21 09:57:33');

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
(1, 1, 'CREATED', NULL, NULL, NULL, 'New sale created â€” Bill Total: LKR 7,900.00', 1, '2026-03-21 09:31:53'),
(2, 1, 'PAYMENT_ADDED', NULL, NULL, NULL, 'Payment of LKR 7,900.00 via Cash added.', 1, '2026-03-21 09:31:53'),
(3, 2, 'CREATED', NULL, NULL, NULL, 'New sale created â€” Bill Total: LKR 0.00', 1, '2026-03-21 09:55:38'),
(4, 2, 'EDITED', NULL, NULL, NULL, 'Sale updated â€” Bill Total: LKR 0.00', 1, '2026-03-21 09:55:41'),
(5, 2, 'EDITED', NULL, NULL, NULL, 'Sale updated â€” Bill Total: LKR 180.00', 1, '2026-03-21 09:55:54'),
(6, 2, 'EDITED', NULL, NULL, NULL, 'Sale updated â€” Bill Total: LKR 18,000.00', 1, '2026-03-21 09:55:58'),
(7, 2, 'EDITED', NULL, NULL, NULL, 'Sale updated â€” Bill Total: LKR 18,000.00', 1, '2026-03-21 09:56:25'),
(8, 2, 'EDITED', NULL, NULL, NULL, 'Sale updated â€” Bill Total: LKR 18,000.00', 1, '2026-03-21 09:57:33'),
(9, 2, 'PAYMENT_ADDED', NULL, NULL, NULL, 'Payment of LKR 17,000.00 via Account Transfer added.', 1, '2026-03-21 09:57:33');

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
(1, 1, 37, 10, 4, 105.72, 1400.00, 500.00, 7900.00),
(6, 2, 37, 100, 0, 105.72, 180.00, 0.00, 18000.00);

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
(1, 1, 7900.00, 'Cash', NULL, '', '', NULL, '2026-03-21', 1, '2026-03-21 09:31:53'),
(2, 2, 17000.00, 'Account Transfer', 5, '', '', NULL, '2026-03-21', 1, '2026-03-21 09:57:33');

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
(2, NULL, NULL, 'employee', 'Kamal Perera', '1235555', '', 'gampaha', '', '2026-03-13 14:43:40'),
(3, NULL, NULL, 'employee', 'sugath perera', '23232323', '', '', '', '2026-03-13 14:43:50'),
(4, NULL, NULL, 'employee', 'nimes', '5554', NULL, NULL, NULL, '2026-03-13 14:51:41');

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `brands`
--
ALTER TABLE `brands`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `containers`
--
ALTER TABLE `containers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT for table `container_expenses`
--
ALTER TABLE `container_expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- AUTO_INCREMENT for table `container_items`
--
ALTER TABLE `container_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT for table `container_ledger`
--
ALTER TABLE `container_ledger`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=114;

--
-- AUTO_INCREMENT for table `container_payments`
--
ALTER TABLE `container_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `deliveries`
--
ALTER TABLE `deliveries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `delivery_customers`
--
ALTER TABLE `delivery_customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `delivery_expenses`
--
ALTER TABLE `delivery_expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `delivery_field_expenses`
--
ALTER TABLE `delivery_field_expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `delivery_items`
--
ALTER TABLE `delivery_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `delivery_item_damages`
--
ALTER TABLE `delivery_item_damages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `delivery_ledger`
--
ALTER TABLE `delivery_ledger`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `delivery_payments`
--
ALTER TABLE `delivery_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `delivery_proof_photos`
--
ALTER TABLE `delivery_proof_photos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `employee_salary_payments`
--
ALTER TABLE `employee_salary_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `employee_salary_settings`
--
ALTER TABLE `employee_salary_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `pos_sales`
--
ALTER TABLE `pos_sales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `pos_sale_audits`
--
ALTER TABLE `pos_sale_audits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `pos_sale_items`
--
ALTER TABLE `pos_sale_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `pos_sale_payments`
--
ALTER TABLE `pos_sale_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

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
