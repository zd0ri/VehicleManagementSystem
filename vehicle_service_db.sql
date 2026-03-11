-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 09, 2026 at 02:18 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `vehicle_service_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `appointments`
--

CREATE TABLE `appointments` (
  `appointment_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `service_id` int(11) DEFAULT NULL,
  `appointment_date` datetime NOT NULL,
  `status` enum('Pending','Approved','Completed','Cancelled') DEFAULT 'Pending',
  `appointment_type` enum('Online','Walk-In') DEFAULT 'Online',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `appointments`
--

INSERT INTO `appointments` (`appointment_id`, `client_id`, `vehicle_id`, `service_id`, `appointment_date`, `status`, `appointment_type`, `notes`, `created_by`, `created_at`) VALUES
(1, 2, 1, NULL, '2026-03-06 09:00:00', 'Completed', 'Online', NULL, 6, '2026-03-05 03:11:56'),
(2, 2, 1, NULL, '2026-03-06 14:00:00', 'Completed', 'Online', NULL, 6, '2026-03-05 03:13:21'),
(3, 2, 1, NULL, '2026-03-06 09:00:00', 'Pending', 'Online', NULL, 6, '2026-03-05 13:12:26'),
(4, 2, 1, 6, '2026-03-08 09:00:00', 'Approved', 'Online', NULL, 6, '2026-03-07 13:45:56'),
(5, 2, 1, 2, '2026-03-08 10:00:00', 'Approved', 'Online', NULL, 6, '2026-03-07 14:04:54'),
(6, 2, 1, 1, '2026-03-09 12:00:00', 'Approved', 'Online', NULL, 6, '2026-03-07 14:05:16'),
(7, 2, 1, 4, '2026-03-09 16:00:00', 'Approved', 'Online', NULL, 6, '2026-03-07 14:05:29');

-- --------------------------------------------------------

--
-- Table structure for table `assignments`
--

CREATE TABLE `assignments` (
  `assignment_id` int(11) NOT NULL,
  `appointment_id` int(11) DEFAULT NULL,
  `vehicle_id` int(11) NOT NULL,
  `technician_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `status` enum('Assigned','Ongoing','Finished') DEFAULT 'Assigned',
  `start_time` datetime DEFAULT NULL,
  `end_time` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `assignments`
--

INSERT INTO `assignments` (`assignment_id`, `appointment_id`, `vehicle_id`, `technician_id`, `service_id`, `status`, `start_time`, `end_time`, `notes`) VALUES
(1, 4, 1, 9, 6, 'Ongoing', '2026-03-07 21:46:25', NULL, NULL),
(2, 5, 1, 8, 2, 'Assigned', NULL, NULL, NULL),
(3, 6, 1, 7, 1, 'Assigned', NULL, NULL, NULL),
(4, 7, 1, 9, 4, 'Assigned', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `log_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(255) DEFAULT NULL,
  `table_name` varchar(100) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cart`
--

CREATE TABLE `cart` (
  `cart_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `item_id` int(11) DEFAULT NULL,
  `service_id` int(11) DEFAULT NULL,
  `quantity` int(11) DEFAULT 1,
  `added_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `clients`
--

CREATE TABLE `clients` (
  `client_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `full_name` varchar(150) NOT NULL,
  `phone` varchar(50) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `clients`
--

INSERT INTO `clients` (`client_id`, `user_id`, `full_name`, `phone`, `email`, `address`, `created_at`) VALUES
(2, 6, 'Meriel Lanuza', '09628707645', 'rielanuza@gmail.com', 'Taguig City', '2026-03-04 14:50:18'),
(3, 10, 'Iyanna Angela Marquez', '09293050752', 'iya@gmail.com', 'Taguig City', '2026-03-09 13:16:05');

-- --------------------------------------------------------

--
-- Table structure for table `inventory`
--

CREATE TABLE `inventory` (
  `item_id` int(11) NOT NULL,
  `item_name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `sku` varchar(100) DEFAULT NULL,
  `quantity` int(11) DEFAULT 0,
  `unit_price` decimal(10,2) NOT NULL,
  `expiry_date` date DEFAULT NULL,
  `supplier` varchar(150) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `inventory`
--

INSERT INTO `inventory` (`item_id`, `item_name`, `description`, `category`, `image`, `sku`, `quantity`, `unit_price`, `expiry_date`, `supplier`, `supplier_id`, `last_updated`) VALUES
(1, 'Premium Ceramic Brake Pads - Front Set', 'High-performance ceramic brake pads with low dust and noise. Provides excellent stopping power and extended pad life.', 'Brake Parts', 'item_69a8ed08832fd.jpg', 'BRK-001', 49, 1850.00, NULL, 'Brembo Philippines', 1, '2026-03-07 14:27:11'),
(2, 'High Performance Air Filter - Universal', 'Washable and reusable high-flow air filter for improved engine breathing and horsepower gains.', 'Engine Parts', 'item_69a8f0c1a411f.jpg', 'ENG-001', 74, 950.00, NULL, 'K&N Filters', 2, '2026-03-07 14:27:11'),
(3, '17\" Alloy Sport Wheels - Set of 4', 'Lightweight forged alloy wheels with sport design. Fits most sedans and compact SUVs.', 'Wheels & Tires', 'item_69a8efe60131b.webp', 'WHL-001', 12, 18500.00, NULL, 'Rota Wheels PH', 3, '2026-03-07 14:27:11'),
(4, 'LED Dashboard Gauge Cluster - Universal', 'Digital LED gauge cluster with speedometer, tachometer, fuel, and temperature. Universal fitment.', 'Accessories', 'item_69a8f211350b0.jpg', 'ACC-001', 30, 3200.00, NULL, 'AutoGauge', 4, '2026-03-07 14:27:11'),
(5, 'Turbocharger Assembly - Performance Grade', 'Ball-bearing turbocharger for significant power gains. Includes gaskets and oil feed line.', 'Engine Parts', 'item_69a8f2be89714.jpg', 'ENG-002', 8, 15750.00, NULL, 'Garrett Motion', 5, '2026-03-07 14:27:11'),
(6, 'LED Headlight Bulbs H11 - 6000K White', 'Ultra-bright LED headlight bulbs with 6000K daylight white output. Plug-and-play installation.', 'Lighting', 'item_69a8f2395b864.webp', 'LGT-001', 100, 1200.00, NULL, 'Philips Automotive', 6, '2026-03-07 14:27:11'),
(7, 'Drilled & Slotted Rotors - Rear Pair', 'Performance drilled and slotted brake rotors for improved heat dissipation and wet weather braking.', 'Brake Parts', 'item_69a8ef50a545e.jpg', 'BRK-002', 35, 4500.00, NULL, 'EBC Brakes', 7, '2026-03-07 14:27:11'),
(8, 'All-Season Performance Tires 225/45R17', 'Premium all-season tires with asymmetric tread pattern for superior grip in wet and dry conditions.', 'Wheels & Tires', 'item_69a8ed448a788.jpg', 'WHL-002', 40, 5200.00, NULL, 'Michelin Philippines', 8, '2026-03-07 14:27:11'),
(9, 'Full Synthetic Engine Oil 5W-30 (4L)', 'Premium full synthetic engine oil for maximum engine protection and performance. Compatible with all gasoline and diesel engines.', 'Fluids & Oils', 'item_69a8f010d0759.webp', 'OIL-001', 210, 1499.00, '2026-05-15', 'AutoGauge', 4, '2026-03-07 14:47:22'),
(10, 'Carbon Fiber Side Mirror Covers', 'Real carbon fiber mirror cap replacements. Lightweight and stylish upgrade for most vehicle models.', 'Body Parts', 'item_69a8eddc4e81c.jpg', 'BDY-001', 24, 2800.00, NULL, 'Carbon Concepts PH', 10, '2026-03-07 14:27:11'),
(11, 'Cold Air Intake System - Sport Series', 'Complete cold air intake kit with heat shield and high-flow filter for maximum airflow and power.', 'Engine Parts', 'item_69a8ee548024b.jpg', 'ENG-003', 14, 6500.00, NULL, 'AEM Intakes', 11, '2026-03-07 14:27:11'),
(12, 'OBD2 Diagnostic Scanner - Pro Edition', 'Professional-grade OBD2 scanner with live data, code reading, and advanced diagnostics for all OBD2 vehicles.', 'Electronics', 'item_69a8f294ce673.jpg', 'ELC-001', 45, 3450.00, NULL, 'Autel Philippines', 12, '2026-03-07 14:27:11'),
(13, 'Wireless Car Charger Mount - Fast Charge', '15W Qi wireless charging mount with auto-clamping. Compatible with all Qi-enabled smartphones.', 'Accessories', 'item_69ac302e75d42.webp', 'ACC-002', 80, 1100.00, NULL, 'Baseus', 13, '2026-03-07 14:27:11'),
(14, 'Front Shock Absorber Set', 'Gas-charged front shock absorbers for improved ride comfort and handling. Direct OEM replacement.', 'Suspension', 'item_69a8ef899a301.jpg', 'SUS-001', 20, 3800.00, NULL, 'KYB Philippines', 14, '2026-03-07 14:27:11'),
(15, 'Aluminum Radiator - High Capacity', 'All-aluminum racing radiator with 40% more cooling capacity than stock. Direct bolt-on fitment.', 'Cooling System', 'item_69a8eda7ceab5.webp', 'COL-001', 9, 5200.00, NULL, 'Mishimoto', 15, '2026-03-07 14:27:11'),
(16, 'Iridium Spark Plugs - Set of 4', 'Fine-wire iridium spark plugs for improved combustion, fuel efficiency, and engine performance.', 'Ignition', 'item_69a8f191aa1ab.jpg', 'IGN-001', 119, 1200.00, NULL, 'NGK Philippines', 16, '2026-03-07 14:27:11'),
(17, 'Water Pump Assembly - OEM Spec', 'Direct OEM replacement water pump assembly with gasket. Ensures proper engine cooling circulation.', 'Engine Parts', 'item_69ac2ef723eac.webp', 'ENG-004', 18, 2800.00, NULL, 'Gates Corporation', 17, '2026-03-07 14:27:11');

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `invoice_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `subtotal` decimal(12,2) DEFAULT 0.00,
  `tax_amount` decimal(12,2) DEFAULT 0.00,
  `total_amount` decimal(12,2) DEFAULT 0.00,
  `status` enum('Unpaid','Partially Paid','Paid') DEFAULT 'Unpaid',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoice_items`
--

CREATE TABLE `invoice_items` (
  `invoice_item_id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `service_id` int(11) DEFAULT NULL,
  `item_id` int(11) DEFAULT NULL,
  `quantity` int(11) DEFAULT 1,
  `unit_price` decimal(10,2) NOT NULL,
  `total_price` decimal(10,2) GENERATED ALWAYS AS (`quantity` * `unit_price`) VIRTUAL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `message` text DEFAULT NULL,
  `type` enum('info','success','warning','danger','queue_turn','new_assignment','ewallet_payment') DEFAULT 'info',
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `type`, `is_read`, `created_at`) VALUES
(1, 1, 'New E-Wallet Payment', 'Order #3 paid via GCash. Amount: ₱7,168.00. Please verify the receipt.', '', 0, '2026-03-05 13:10:44'),
(2, 9, 'New Service Assignment', 'You have been assigned: Car Detailing. Scheduled for Mar 08, 2026 09:00 AM.', '', 1, '2026-03-07 13:45:56'),
(3, 8, 'New Service Assignment', 'You have been assigned: Engine Repair. Scheduled for Mar 08, 2026 10:00 AM.', '', 0, '2026-03-07 14:04:54'),
(4, 7, 'New Service Assignment', 'You have been assigned: Oil Change. Scheduled for Mar 09, 2026 12:00 PM.', '', 0, '2026-03-07 14:05:16'),
(5, 9, 'New Service Assignment', 'You have been assigned: Tire Service. Scheduled for Mar 09, 2026 04:00 PM.', '', 0, '2026-03-07 14:05:29');

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `order_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `order_type` enum('product','service') DEFAULT 'product',
  `vehicle_id` int(11) DEFAULT NULL,
  `subtotal` decimal(12,2) DEFAULT 0.00,
  `tax_amount` decimal(12,2) DEFAULT 0.00,
  `total_amount` decimal(12,2) DEFAULT 0.00,
  `status` enum('Pending','Processing','Completed','Cancelled') DEFAULT 'Pending',
  `payment_method` enum('Cash','GCash','Maya') DEFAULT NULL,
  `receipt_image` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`order_id`, `client_id`, `order_type`, `vehicle_id`, `subtotal`, `tax_amount`, `total_amount`, `status`, `payment_method`, `receipt_image`, `notes`, `created_at`) VALUES
(1, 2, 'product', NULL, 2800.00, 336.00, 3136.00, 'Completed', 'GCash', NULL, '', '2026-03-05 03:08:23'),
(2, 2, 'product', NULL, 9300.00, 1116.00, 10416.00, 'Completed', 'Cash', NULL, '', '2026-03-05 12:57:01'),
(3, 2, 'product', NULL, 6400.00, 768.00, 7168.00, 'Completed', 'GCash', 'receipt_1772716244_943a2f0d.jpg', '', '2026-03-05 13:10:44');

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `order_item_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `item_id` int(11) DEFAULT NULL,
  `service_id` int(11) DEFAULT NULL,
  `quantity` int(11) DEFAULT 1,
  `unit_price` decimal(10,2) NOT NULL,
  `total_price` decimal(10,2) GENERATED ALWAYS AS (`quantity` * `unit_price`) VIRTUAL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order_items`
--

INSERT INTO `order_items` (`order_item_id`, `order_id`, `item_id`, `service_id`, `quantity`, `unit_price`) VALUES
(1, 1, 1, NULL, 1, 1850.00),
(2, 1, 2, NULL, 1, 950.00),
(3, 2, 11, NULL, 1, 6500.00),
(4, 2, 10, NULL, 1, 2800.00),
(5, 3, 16, NULL, 1, 1200.00),
(6, 3, 15, NULL, 1, 5200.00);

-- --------------------------------------------------------

--
-- Table structure for table `parts_used`
--

CREATE TABLE `parts_used` (
  `parts_used_id` int(11) NOT NULL,
  `assignment_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `payment_id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `amount_paid` decimal(12,2) NOT NULL,
  `payment_method` enum('Cash','GCash','Maya') DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `payment_date` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_orders`
--

CREATE TABLE `purchase_orders` (
  `po_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `unit_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('Pending','Received','Cancelled') DEFAULT 'Pending',
  `notes` text DEFAULT NULL,
  `ordered_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `received_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchase_orders`
--

INSERT INTO `purchase_orders` (`po_id`, `supplier_id`, `item_id`, `quantity`, `unit_cost`, `total_cost`, `status`, `notes`, `ordered_by`, `created_at`, `received_at`) VALUES
(1, 11, 8, 20, 3120.00, 62400.00, 'Pending', NULL, 1, '2026-03-07 14:34:00', NULL),
(2, 4, 9, 10, 899.40, 8994.00, 'Received', NULL, 1, '2026-03-07 14:37:51', '2026-03-07 22:37:51');

-- --------------------------------------------------------

--
-- Table structure for table `queue`
--

CREATE TABLE `queue` (
  `queue_id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `position` int(11) NOT NULL,
  `status` enum('Waiting','Serving','Done','Cancelled') DEFAULT 'Waiting',
  `added_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ratings`
--

CREATE TABLE `ratings` (
  `rating_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `technician_id` int(11) DEFAULT NULL,
  `assignment_id` int(11) DEFAULT NULL,
  `rating_type` enum('service','product') DEFAULT 'service',
  `rating_value` int(11) DEFAULT NULL CHECK (`rating_value` between 1 and 5),
  `comment` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `services`
--

CREATE TABLE `services` (
  `service_id` int(11) NOT NULL,
  `service_name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `base_price` decimal(10,2) NOT NULL,
  `estimated_duration` int(11) DEFAULT NULL COMMENT 'Duration in minutes',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `services`
--

INSERT INTO `services` (`service_id`, `service_name`, `description`, `base_price`, `estimated_duration`, `created_at`) VALUES
(1, 'Oil Change', 'Regular oil changes using premium synthetic or conventional oils to keep your engine running smoothly.', 800.00, 45, '2026-03-04 15:01:06'),
(2, 'Engine Repair', 'Complete engine diagnostics, tune-ups, and major repair services for all vehicle makes and models.', 2500.00, 120, '2026-03-04 15:01:06'),
(3, 'Brake Service', 'Brake inspection, pad replacement, rotor resurfacing, and complete brake system overhaul.', 1500.00, 60, '2026-03-04 15:01:06'),
(4, 'Tire Service', 'Tire mounting, balancing, rotation, alignment, and flat tire repair services available.', 500.00, 30, '2026-03-04 15:01:06'),
(5, 'Battery Service', 'Battery testing, charging, replacement, and electrical system diagnostics for reliable starts.', 300.00, 20, '2026-03-04 15:01:06'),
(6, 'Car Detailing', 'Interior and exterior detailing, paint correction, ceramic coating, and protective treatments.', 1800.00, 90, '2026-03-04 15:01:06');

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `supplier_id` int(11) NOT NULL,
  `supplier_name` varchar(200) NOT NULL,
  `contact_person` varchar(150) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `suppliers`
--

INSERT INTO `suppliers` (`supplier_id`, `supplier_name`, `contact_person`, `email`, `phone`, `address`, `status`, `created_at`) VALUES
(1, 'Brembo Philippines', NULL, NULL, NULL, NULL, 'active', '2026-03-07 14:27:11'),
(2, 'K&N Filters', NULL, NULL, NULL, NULL, 'active', '2026-03-07 14:27:11'),
(3, 'Rota Wheels PH', NULL, NULL, NULL, NULL, 'active', '2026-03-07 14:27:11'),
(4, 'AutoGauge', NULL, NULL, NULL, NULL, 'active', '2026-03-07 14:27:11'),
(5, 'Garrett Motion', NULL, NULL, NULL, NULL, 'active', '2026-03-07 14:27:11'),
(6, 'Philips Automotive', NULL, NULL, NULL, NULL, 'active', '2026-03-07 14:27:11'),
(7, 'EBC Brakes', NULL, NULL, NULL, NULL, 'active', '2026-03-07 14:27:11'),
(8, 'Michelin Philippines', NULL, NULL, NULL, NULL, 'active', '2026-03-07 14:27:11'),
(9, 'Mobil 1', NULL, NULL, NULL, NULL, 'active', '2026-03-07 14:27:11'),
(10, 'Carbon Concepts PH', NULL, NULL, NULL, NULL, 'active', '2026-03-07 14:27:11'),
(11, 'AEM Intakes', NULL, NULL, NULL, NULL, 'active', '2026-03-07 14:27:11'),
(12, 'Autel Philippines', NULL, NULL, NULL, NULL, 'active', '2026-03-07 14:27:11'),
(13, 'Baseus', NULL, NULL, NULL, NULL, 'active', '2026-03-07 14:27:11'),
(14, 'KYB Philippines', NULL, NULL, NULL, NULL, 'active', '2026-03-07 14:27:11'),
(15, 'Mishimoto', NULL, NULL, NULL, NULL, 'active', '2026-03-07 14:27:11'),
(16, 'NGK Philippines', NULL, NULL, NULL, NULL, 'active', '2026-03-07 14:27:11'),
(17, 'Gates Corporation', NULL, NULL, NULL, NULL, 'active', '2026-03-07 14:27:11');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','staff','technician','customer') NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `full_name`, `email`, `password_hash`, `role`, `status`, `created_at`) VALUES
(1, 'System Administrator', 'admin@vehicare.ph', '$2y$10$DpfREEUKJnLJuf4RSk8Bf.LCeiihTGjzwAAT.l1qmb3IGAcxLhM02', 'admin', 'active', '2026-03-04 13:13:59'),
(6, 'Meriel Lanuza', 'rielanuza@gmail.com', '$2y$10$UWlHCkr/OndT0IlS2/NVmuIFjJpZQ8I8ANZCE9mMtM2O1OKxGTYQ6', 'customer', 'active', '2026-03-04 14:50:18'),
(7, 'Juan Carlos Reyes', 'juan.reyes@vehicare.ph', '$2y$10$i13lJZiyJB89QkSEZoE8JODLLouqMr.gY2C2SOwVhB02BysVDOwDy', 'technician', 'active', '2026-03-04 22:13:01'),
(8, 'Maria Santos Cruz', 'maria.cruz@vehicare.ph', '$2y$10$i13lJZiyJB89QkSEZoE8JODLLouqMr.gY2C2SOwVhB02BysVDOwDy', 'technician', 'active', '2026-03-04 22:13:01'),
(9, 'Roberto dela Rosa', 'roberto.delarosa@vehicare.ph', '$2y$10$i13lJZiyJB89QkSEZoE8JODLLouqMr.gY2C2SOwVhB02BysVDOwDy', 'technician', 'active', '2026-03-04 22:13:01'),
(10, 'Iyanna Angela Marquez', 'iya@gmail.com', '$2y$10$BC/4Gu.mSmwXtOz/24P0GOvRNFYTk2iGfmjQcfiPB5rCDGL.UrZiC', 'customer', 'active', '2026-03-09 13:16:05');

-- --------------------------------------------------------

--
-- Table structure for table `vehicles`
--

CREATE TABLE `vehicles` (
  `vehicle_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `plate_number` varchar(50) NOT NULL,
  `vin` varchar(17) DEFAULT NULL,
  `make` varchar(100) DEFAULT NULL,
  `model` varchar(100) DEFAULT NULL,
  `year` year(4) DEFAULT NULL,
  `color` varchar(50) DEFAULT NULL,
  `status` enum('active','in_service','completed','released') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vehicles`
--

INSERT INTO `vehicles` (`vehicle_id`, `client_id`, `plate_number`, `vin`, `make`, `model`, `year`, `color`, `status`, `created_at`) VALUES
(1, 2, 'ABC 1234', NULL, 'Toyota', 'Vios', '2025', 'Black', 'active', '2026-03-05 03:10:33');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `appointments`
--
ALTER TABLE `appointments`
  ADD PRIMARY KEY (`appointment_id`),
  ADD KEY `fk_app_client` (`client_id`),
  ADD KEY `fk_app_vehicle` (`vehicle_id`),
  ADD KEY `fk_app_user` (`created_by`);

--
-- Indexes for table `assignments`
--
ALTER TABLE `assignments`
  ADD PRIMARY KEY (`assignment_id`),
  ADD KEY `vehicle_id` (`vehicle_id`),
  ADD KEY `technician_id` (`technician_id`),
  ADD KEY `service_id` (`service_id`),
  ADD KEY `idx_assignment_status` (`status`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `cart`
--
ALTER TABLE `cart`
  ADD PRIMARY KEY (`cart_id`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `service_id` (`service_id`);

--
-- Indexes for table `clients`
--
ALTER TABLE `clients`
  ADD PRIMARY KEY (`client_id`),
  ADD KEY `idx_client_email` (`email`),
  ADD KEY `idx_client_phone` (`phone`),
  ADD KEY `fk_client_user` (`user_id`);

--
-- Indexes for table `inventory`
--
ALTER TABLE `inventory`
  ADD PRIMARY KEY (`item_id`),
  ADD UNIQUE KEY `sku` (`sku`),
  ADD KEY `supplier_id` (`supplier_id`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`invoice_id`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `vehicle_id` (`vehicle_id`),
  ADD KEY `idx_invoice_status` (`status`);

--
-- Indexes for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD PRIMARY KEY (`invoice_item_id`),
  ADD KEY `invoice_id` (`invoice_id`),
  ADD KEY `service_id` (`service_id`),
  ADD KEY `item_id` (`item_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`order_id`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `vehicle_id` (`vehicle_id`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`order_item_id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `service_id` (`service_id`);

--
-- Indexes for table `parts_used`
--
ALTER TABLE `parts_used`
  ADD PRIMARY KEY (`parts_used_id`),
  ADD KEY `assignment_id` (`assignment_id`),
  ADD KEY `item_id` (`item_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD KEY `invoice_id` (`invoice_id`);

--
-- Indexes for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD PRIMARY KEY (`po_id`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `ordered_by` (`ordered_by`);

--
-- Indexes for table `queue`
--
ALTER TABLE `queue`
  ADD PRIMARY KEY (`queue_id`),
  ADD KEY `vehicle_id` (`vehicle_id`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `idx_queue_active` (`status`,`position`);

--
-- Indexes for table `ratings`
--
ALTER TABLE `ratings`
  ADD PRIMARY KEY (`rating_id`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `vehicle_id` (`vehicle_id`);

--
-- Indexes for table `services`
--
ALTER TABLE `services`
  ADD PRIMARY KEY (`service_id`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`supplier_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD PRIMARY KEY (`vehicle_id`),
  ADD UNIQUE KEY `plate_number` (`plate_number`),
  ADD UNIQUE KEY `vin` (`vin`),
  ADD KEY `fk_vehicle_client` (`client_id`),
  ADD KEY `idx_vehicle_lookup` (`plate_number`,`vin`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `appointments`
--
ALTER TABLE `appointments`
  MODIFY `appointment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `assignments`
--
ALTER TABLE `assignments`
  MODIFY `assignment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cart`
--
ALTER TABLE `cart`
  MODIFY `cart_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `clients`
--
ALTER TABLE `clients`
  MODIFY `client_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `inventory`
--
ALTER TABLE `inventory`
  MODIFY `item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `invoice_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoice_items`
--
ALTER TABLE `invoice_items`
  MODIFY `invoice_item_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `order_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `order_item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `parts_used`
--
ALTER TABLE `parts_used`
  MODIFY `parts_used_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `payment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  MODIFY `po_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `queue`
--
ALTER TABLE `queue`
  MODIFY `queue_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ratings`
--
ALTER TABLE `ratings`
  MODIFY `rating_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `services`
--
ALTER TABLE `services`
  MODIFY `service_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `supplier_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `vehicles`
--
ALTER TABLE `vehicles`
  MODIFY `vehicle_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `appointments`
--
ALTER TABLE `appointments`
  ADD CONSTRAINT `fk_app_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`client_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_app_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_app_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`vehicle_id`) ON DELETE CASCADE;

--
-- Constraints for table `assignments`
--
ALTER TABLE `assignments`
  ADD CONSTRAINT `assignments_ibfk_1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`vehicle_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `assignments_ibfk_2` FOREIGN KEY (`technician_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `assignments_ibfk_3` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) ON DELETE CASCADE;

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `cart`
--
ALTER TABLE `cart`
  ADD CONSTRAINT `cart_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`client_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cart_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `inventory` (`item_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cart_ibfk_3` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) ON DELETE CASCADE;

--
-- Constraints for table `clients`
--
ALTER TABLE `clients`
  ADD CONSTRAINT `fk_client_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `inventory`
--
ALTER TABLE `inventory`
  ADD CONSTRAINT `inventory_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON DELETE SET NULL;

--
-- Constraints for table `invoices`
--
ALTER TABLE `invoices`
  ADD CONSTRAINT `invoices_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`client_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `invoices_ibfk_2` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`vehicle_id`) ON DELETE CASCADE;

--
-- Constraints for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD CONSTRAINT `invoice_items_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`invoice_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `invoice_items_ibfk_2` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `invoice_items_ibfk_3` FOREIGN KEY (`item_id`) REFERENCES `inventory` (`item_id`) ON DELETE SET NULL;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`client_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `orders_ibfk_2` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`vehicle_id`) ON DELETE SET NULL;

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`order_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `order_items_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `inventory` (`item_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `order_items_ibfk_3` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) ON DELETE SET NULL;

--
-- Constraints for table `parts_used`
--
ALTER TABLE `parts_used`
  ADD CONSTRAINT `parts_used_ibfk_1` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`assignment_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `parts_used_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `inventory` (`item_id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`invoice_id`) ON DELETE CASCADE;

--
-- Constraints for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD CONSTRAINT `purchase_orders_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`),
  ADD CONSTRAINT `purchase_orders_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `inventory` (`item_id`),
  ADD CONSTRAINT `purchase_orders_ibfk_3` FOREIGN KEY (`ordered_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `queue`
--
ALTER TABLE `queue`
  ADD CONSTRAINT `queue_ibfk_1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`vehicle_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `queue_ibfk_2` FOREIGN KEY (`client_id`) REFERENCES `clients` (`client_id`) ON DELETE CASCADE;

--
-- Constraints for table `ratings`
--
ALTER TABLE `ratings`
  ADD CONSTRAINT `ratings_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`client_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ratings_ibfk_2` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`vehicle_id`) ON DELETE CASCADE;

--
-- Constraints for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD CONSTRAINT `fk_vehicle_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`client_id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
