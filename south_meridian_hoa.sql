-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 17, 2026 at 12:41 PM
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
-- Database: `south_meridian_hoa`
--

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `full_name` varchar(255) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3','Superadmin') NOT NULL,
  `role` enum('admin','superadmin') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admins`
--

INSERT INTO `admins` (`id`, `email`, `full_name`, `password`, `phase`, `role`) VALUES
(1, 'superadmin@gmail.com', NULL, 'superadmin', 'Superadmin', 'superadmin'),
(2, 'admin1@gmail.com', 'Patrick Justin Baculpo', 'admin1', 'Phase 1', 'admin'),
(3, 'admin2@gmail.com', 'Elaine P. Mendoza', 'admin2', 'Phase 2', 'admin'),
(4, 'admin3@gmail.com', 'Miguel A. Dizon', 'admin3', 'Phase 3', 'admin');

-- --------------------------------------------------------

--
-- Table structure for table `announcements`
--

CREATE TABLE `announcements` (
  `id` int(11) NOT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3','Superadmin') NOT NULL DEFAULT 'Superadmin',
  `title` varchar(255) NOT NULL,
  `category` enum('general','maintenance','meeting','emergency') NOT NULL,
  `audience` enum('all','block','selected','selected_officer','all_officers') NOT NULL,
  `audience_value` varchar(255) DEFAULT NULL,
  `message` text NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `priority` enum('normal','important','urgent') NOT NULL DEFAULT 'normal',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `announcements`
--

INSERT INTO `announcements` (`id`, `admin_id`, `phase`, `title`, `category`, `audience`, `audience_value`, `message`, `start_date`, `end_date`, `priority`, `created_at`) VALUES
(9, 2, 'Phase 1', 'atingitng', 'general', 'all', NULL, 'awadwasd', '2026-02-14', '2026-02-21', 'normal', '2026-02-13 21:33:45');

-- --------------------------------------------------------

--
-- Table structure for table `announcement_attachments`
--

CREATE TABLE `announcement_attachments` (
  `id` int(11) NOT NULL,
  `announcement_id` int(11) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `stored_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `file_size` int(11) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `announcement_comments`
--

CREATE TABLE `announcement_comments` (
  `id` int(11) NOT NULL,
  `announcement_id` int(11) NOT NULL,
  `homeowner_id` int(11) NOT NULL,
  `comment` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `announcement_comments`
--

INSERT INTO `announcement_comments` (`id`, `announcement_id`, `homeowner_id`, `comment`, `created_at`) VALUES
(1, 9, 35, 'awadaw', '2026-02-17 09:19:02');

-- --------------------------------------------------------

--
-- Table structure for table `announcement_likes`
--

CREATE TABLE `announcement_likes` (
  `id` int(11) NOT NULL,
  `announcement_id` int(11) NOT NULL,
  `homeowner_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `announcement_likes`
--

INSERT INTO `announcement_likes` (`id`, `announcement_id`, `homeowner_id`, `created_at`) VALUES
(2, 9, 35, '2026-02-17 09:19:05');

-- --------------------------------------------------------

--
-- Table structure for table `announcement_recipients`
--

CREATE TABLE `announcement_recipients` (
  `id` int(11) NOT NULL,
  `announcement_id` int(11) NOT NULL,
  `recipient_type` enum('homeowner','officer') NOT NULL,
  `homeowner_id` int(11) DEFAULT NULL,
  `officer_id` int(11) DEFAULT NULL,
  `recipient_name` varchar(255) DEFAULT NULL,
  `recipient_email` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `announcement_recipients`
--

INSERT INTO `announcement_recipients` (`id`, `announcement_id`, `recipient_type`, `homeowner_id`, `officer_id`, `recipient_name`, `recipient_email`, `created_at`) VALUES
(89, 9, 'homeowner', 35, NULL, 'Patrick Justin  Baculpo', 'baculpopatrick2440@gmail.com', '2026-02-13 21:33:45'),
(90, 9, 'homeowner', 37, NULL, 'Mark A Santos', 'p1_mark.santos@hoa.local', '2026-02-13 21:33:45'),
(91, 9, 'homeowner', 38, NULL, 'Anne M Reyes', 'p1_anne.reyes@hoa.local', '2026-02-13 21:33:45'),
(92, 9, 'homeowner', 39, NULL, 'John D Cruz', 'p1_john.cruz@hoa.local', '2026-02-13 21:33:45'),
(93, 9, 'homeowner', 40, NULL, 'Jenny L Garcia', 'p1_jenny.garcia@hoa.local', '2026-02-13 21:33:45'),
(94, 9, 'homeowner', 41, NULL, 'Paolo R Flores', 'p1_paolo.flores@hoa.local', '2026-02-13 21:33:45'),
(95, 9, 'homeowner', 42, NULL, 'Liza C Domingo', 'p1_liza.domingo@hoa.local', '2026-02-13 21:33:45'),
(96, 9, 'homeowner', 43, NULL, 'Ryan P Navarro', 'p1_ryan.navarro@hoa.local', '2026-02-13 21:33:45'),
(97, 9, 'homeowner', 44, NULL, 'Mika S Dela Cruz', 'p1_mika.delacruz@hoa.local', '2026-02-13 21:33:45'),
(98, 9, 'homeowner', 45, NULL, 'Carlo B Lim', 'p1_carlo.lim@hoa.local', '2026-02-13 21:33:45'),
(99, 9, 'homeowner', 46, NULL, 'Grace T Salazar', 'p1_grace.salazar@hoa.local', '2026-02-13 21:33:45');

-- --------------------------------------------------------

--
-- Table structure for table `election_nominations`
--

CREATE TABLE `election_nominations` (
  `id` int(11) NOT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3') NOT NULL,
  `position` enum('President','Vice President','Secretary','Treasurer','Auditor','Board of Director') NOT NULL,
  `homeowner_id` int(11) NOT NULL,
  `created_by_admin_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `finance_donations`
--

CREATE TABLE `finance_donations` (
  `id` int(11) NOT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3') NOT NULL,
  `donor_name` varchar(255) NOT NULL,
  `donor_email` varchar(255) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `donation_date` date NOT NULL,
  `receipt_no` varchar(50) DEFAULT NULL,
  `message` varchar(255) DEFAULT NULL,
  `created_by_admin_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `finance_donations`
--

INSERT INTO `finance_donations` (`id`, `phase`, `donor_name`, `donor_email`, `amount`, `donation_date`, `receipt_no`, `message`, `created_by_admin_id`, `created_at`) VALUES
(1, 'Phase 1', 'Patrick Justin Baculpo', 'baculpopatrick2440@gmail.com', 2500.00, '2026-02-17', '12313123', '', 2, '2026-02-17 11:03:25');

-- --------------------------------------------------------

--
-- Table structure for table `finance_dues_settings`
--

CREATE TABLE `finance_dues_settings` (
  `id` int(11) NOT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3') NOT NULL,
  `monthly_dues` decimal(10,2) NOT NULL DEFAULT 0.00,
  `updated_by_admin_id` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `finance_dues_settings`
--

INSERT INTO `finance_dues_settings` (`id`, `phase`, `monthly_dues`, `updated_by_admin_id`, `updated_at`) VALUES
(1, 'Phase 1', 200.00, 1, '2026-02-09 15:56:25');

-- --------------------------------------------------------

--
-- Table structure for table `finance_expenses`
--

CREATE TABLE `finance_expenses` (
  `id` int(11) NOT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3') NOT NULL,
  `category` enum('maintenance','security','utilities','other') NOT NULL DEFAULT 'other',
  `description` varchar(255) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `expense_date` date NOT NULL,
  `receipt_path` varchar(255) DEFAULT NULL,
  `created_by_admin_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `finance_expenses`
--

INSERT INTO `finance_expenses` (`id`, `phase`, `category`, `description`, `amount`, `expense_date`, `receipt_path`, `created_by_admin_id`, `created_at`) VALUES
(1, 'Phase 1', 'maintenance', 'ilaw', 1000.00, '2026-02-17', NULL, 2, '2026-02-17 11:06:47');

-- --------------------------------------------------------

--
-- Table structure for table `finance_opening_balance`
--

CREATE TABLE `finance_opening_balance` (
  `id` int(11) NOT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3') NOT NULL,
  `opening_balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `as_of` date NOT NULL,
  `updated_by_admin_id` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `finance_payments`
--

CREATE TABLE `finance_payments` (
  `id` int(11) NOT NULL,
  `homeowner_id` int(11) NOT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3') NOT NULL,
  `pay_year` int(11) NOT NULL,
  `pay_month` tinyint(4) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` enum('paid','unpaid') NOT NULL DEFAULT 'paid',
  `paid_at` datetime DEFAULT current_timestamp(),
  `reference_no` varchar(100) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_by_admin_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `finance_payments`
--

INSERT INTO `finance_payments` (`id`, `homeowner_id`, `phase`, `pay_year`, `pay_month`, `amount`, `status`, `paid_at`, `reference_no`, `notes`, `created_by_admin_id`, `created_at`) VALUES
(1, 35, 'Phase 1', 2026, 1, 200.00, 'paid', '2026-02-17 18:54:41', 'pay_UpepdNFQgdW6KuDX77pkWviH', 'PayMongo (fallback sync)', NULL, '2026-02-17 10:54:41'),
(2, 35, 'Phase 1', 2026, 2, 200.00, 'paid', '2026-02-17 18:55:08', 'pay_yhU3yoMSVFdEthBaXwkVRBa9', 'PayMongo (fallback sync)', NULL, '2026-02-17 10:55:08'),
(3, 39, 'Phase 1', 2026, 2, 200.00, 'paid', '2026-02-17 18:57:10', '', 'cash', 2, '2026-02-17 10:57:10');

-- --------------------------------------------------------

--
-- Table structure for table `finance_paymongo_checkouts`
--

CREATE TABLE `finance_paymongo_checkouts` (
  `id` int(11) NOT NULL,
  `checkout_session_id` varchar(80) NOT NULL,
  `checkout_url` text DEFAULT NULL,
  `homeowner_id` int(11) NOT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3') NOT NULL,
  `pay_year` int(11) NOT NULL,
  `pay_month` tinyint(4) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` enum('pending','paid','failed','expired') NOT NULL DEFAULT 'pending',
  `payment_id` varchar(80) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `last_event_type` varchar(80) DEFAULT NULL,
  `last_event_id` varchar(80) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `finance_paymongo_checkouts`
--

INSERT INTO `finance_paymongo_checkouts` (`id`, `checkout_session_id`, `checkout_url`, `homeowner_id`, `phase`, `pay_year`, `pay_month`, `amount`, `status`, `payment_id`, `paid_at`, `last_event_type`, `last_event_id`, `created_at`, `updated_at`) VALUES
(12, 'cs_d05ba16a13282dcf07db7853', 'https://checkout-v2.paymongo.com/d05ba16a13282dcf07db7853', 35, 'Phase 1', 2026, 1, 200.00, 'paid', 'pay_UpepdNFQgdW6KuDX77pkWviH', '2026-02-17 18:54:41', NULL, NULL, '2026-02-17 10:44:28', '2026-02-17 10:54:41'),
(13, 'cs_301d2d253552bf2f5bcbb727', 'https://checkout-v2.paymongo.com/301d2d253552bf2f5bcbb727', 35, 'Phase 1', 2026, 2, 200.00, 'paid', 'pay_yhU3yoMSVFdEthBaXwkVRBa9', '2026-02-17 18:55:08', NULL, NULL, '2026-02-17 10:54:59', '2026-02-17 10:55:08');

-- --------------------------------------------------------

--
-- Table structure for table `finance_report_requests`
--

CREATE TABLE `finance_report_requests` (
  `id` int(11) NOT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3') NOT NULL,
  `report_year` int(11) NOT NULL,
  `report_month` tinyint(4) NOT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `requested_by_admin_id` int(11) DEFAULT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `president_approved_by_email` varchar(255) DEFAULT NULL,
  `president_action_at` datetime DEFAULT NULL,
  `president_remarks` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `finance_report_requests`
--

INSERT INTO `finance_report_requests` (`id`, `phase`, `report_year`, `report_month`, `status`, `requested_by_admin_id`, `requested_at`, `president_approved_by_email`, `president_action_at`, `president_remarks`) VALUES
(1, 'Phase 1', 2026, 2, 'pending', 1, '2026-02-09 21:07:25', 'superadmin@gmail.com', '2026-02-09 23:45:53', '');

-- --------------------------------------------------------

--
-- Table structure for table `hoa_officers`
--

CREATE TABLE `hoa_officers` (
  `id` int(11) NOT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3') NOT NULL,
  `position` varchar(50) NOT NULL,
  `officer_name` varchar(255) DEFAULT NULL,
  `officer_email` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `hoa_officers`
--

INSERT INTO `hoa_officers` (`id`, `phase`, `position`, `officer_name`, `officer_email`, `is_active`, `updated_at`) VALUES
(1, 'Phase 1', 'President', 'Patrick Justin Baculpo', 'baculpopatrick2440@gmail.com', 1, '2026-02-09 21:09:28'),
(2, 'Phase 1', 'Vice President', 'Andrea L. Santos', 'andrea.santos.p1@hoa.local', 1, '2026-02-13 20:31:30'),
(3, 'Phase 1', 'Secretary', 'Mark Anthony Cruz', 'mark.cruz.p1@hoa.local', 1, '2026-02-13 20:31:30'),
(4, 'Phase 1', 'Treasurer', 'Janelle P. Reyes', 'janelle.reyes.p1@hoa.local', 1, '2026-02-13 20:31:30'),
(5, 'Phase 1', 'Auditor', 'Ronald D. Garcia', 'ronald.garcia.p1@hoa.local', 1, '2026-02-13 20:31:30'),
(6, 'Phase 1', 'Board of Director', 'Kim D. Navarro', 'kim.navarro.p1@hoa.local', 1, '2026-02-13 20:31:30'),
(13, 'Phase 2', 'President', 'Elaine P. Mendoza', 'elaine.mendoza.p2@hoa.local', 1, '2026-02-13 20:31:30'),
(14, 'Phase 2', 'Vice President', 'Joshua R. Lim', 'joshua.lim.p2@hoa.local', 1, '2026-02-13 20:31:30'),
(15, 'Phase 2', 'Secretary', 'Trisha Anne Flores', 'trisha.flores.p2@hoa.local', 1, '2026-02-13 20:31:30'),
(16, 'Phase 2', 'Treasurer', 'Paolo V. Bautista', 'paolo.bautista.p2@hoa.local', 1, '2026-02-13 20:31:30'),
(17, 'Phase 2', 'Auditor', 'Catherine D. Yu', 'catherine.yu.p2@hoa.local', 1, '2026-02-13 20:31:30'),
(18, 'Phase 2', 'Board of Director', 'Noel T. Ramos', 'noel.ramos.p2@hoa.local', 1, '2026-02-13 20:31:30'),
(422, 'Phase 3', 'President', 'Miguel A. Dizon', 'miguel.dizon.p3@hoa.local', 1, '2026-02-13 20:31:30'),
(423, 'Phase 3', 'Vice President', 'Shiela Marie Tan', 'shiela.tan.p3@hoa.local', 1, '2026-02-13 20:31:30'),
(424, 'Phase 3', 'Secretary', 'John Paul Villanueva', 'john.villanueva.p3@hoa.local', 1, '2026-02-13 20:31:30'),
(425, 'Phase 3', 'Treasurer', 'Arvin S. Delgado', 'arvin.delgado.p3@hoa.local', 1, '2026-02-13 20:31:30'),
(426, 'Phase 3', 'Auditor', 'Ma. Lourdes Castillo', 'lourdes.castillo.p3@hoa.local', 1, '2026-02-13 20:31:30'),
(427, 'Phase 3', 'Board of Director', 'Stephanie R. Ong', 'stephanie.ong.p3@hoa.local', 1, '2026-02-13 20:31:30');

-- --------------------------------------------------------

--
-- Table structure for table `homeowners`
--

CREATE TABLE `homeowners` (
  `id` int(11) NOT NULL,
  `public_id` varchar(20) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `contact_number` varchar(15) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 1,
  `phase` enum('Phase 1','Phase 2','Phase 3') NOT NULL,
  `house_lot_number` varchar(50) NOT NULL,
  `valid_id_path` varchar(255) NOT NULL,
  `proof_of_billing_path` varchar(255) NOT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `admin_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `homeowners`
--

INSERT INTO `homeowners` (`id`, `public_id`, `first_name`, `middle_name`, `last_name`, `contact_number`, `email`, `password`, `must_change_password`, `phase`, `house_lot_number`, `valid_id_path`, `proof_of_billing_path`, `latitude`, `longitude`, `status`, `admin_id`, `created_at`, `reset_token`, `reset_expires`) VALUES
(35, 'P135', 'Patrick Justin', '', 'Baculpo', '09916963390', 'baculpopatrick2440@gmail.com', '$2y$10$e.tpa4SSqd7Xqrr8IlNlxeUbi8JnIQJNcQt1QQ3RWZr0dPSHrByuW', 0, 'Phase 1', 'blk 7 lot 9', 'uploads/1770288743_id_', 'uploads/1770288743_proof_', 14.3555560, 120.9468340, 'approved', 2, '2026-02-05 10:52:23', NULL, NULL),
(37, 'P137', 'Mark', 'A', 'Santos', '09170000001', 'p1_mark.santos@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 1', 'Blk 1 Lot 1', 'uploads/seed/p1_id1.png', 'uploads/seed/p1_bill1.png', 14.3541010, 120.9461010, 'approved', 2, '2026-02-13 20:11:51', NULL, NULL),
(38, 'P138', 'Anne', 'M', 'Reyes', '09170000002', 'p1_anne.reyes@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 1', 'Blk 1 Lot 2', 'uploads/seed/p1_id2.png', 'uploads/seed/p1_bill2.png', 14.3545060, 120.9466140, 'approved', 2, '2026-02-13 20:11:51', NULL, NULL),
(39, 'P139', 'John', 'D', 'Cruz', '09170000003', 'p1_john.cruz@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 1', 'Blk 1 Lot 3', 'uploads/seed/p1_id3.png', 'uploads/seed/p1_bill3.png', 14.3541410, 120.9461410, 'approved', 2, '2026-02-13 20:11:51', NULL, NULL),
(40, 'P140', 'Jenny', 'L', 'Garcia', '09170000004', 'p1_jenny.garcia@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 1', 'Blk 1 Lot 4', 'uploads/seed/p1_id4.png', 'uploads/seed/p1_bill4.png', 14.3541610, 120.9461610, 'approved', 2, '2026-02-13 20:11:51', NULL, NULL),
(41, 'P141', 'Paolo', 'R', 'Flores', '09170000005', 'p1_paolo.flores@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 1', 'Blk 1 Lot 5', 'uploads/seed/p1_id5.png', 'uploads/seed/p1_bill5.png', 14.3541810, 120.9461810, 'approved', 2, '2026-02-13 20:11:51', NULL, NULL),
(42, 'P142', 'Liza', 'C', 'Domingo', '09170000006', 'p1_liza.domingo@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 1', 'Blk 2 Lot 1', 'uploads/seed/p1_id6.png', 'uploads/seed/p1_bill6.png', 14.3542010, 120.9462010, 'approved', 2, '2026-02-13 20:11:51', NULL, NULL),
(43, 'P143', 'Ryan', 'P', 'Navarro', '09170000007', 'p1_ryan.navarro@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 1', 'Blk 2 Lot 2', 'uploads/seed/p1_id7.png', 'uploads/seed/p1_bill7.png', 14.3542210, 120.9462210, 'approved', 2, '2026-02-13 20:11:51', NULL, NULL),
(44, 'P144', 'Mika', 'S', 'Dela Cruz', '09170000008', 'p1_mika.delacruz@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 1', 'Blk 2 Lot 3', 'uploads/seed/p1_id8.png', 'uploads/seed/p1_bill8.png', 14.3542410, 120.9462410, 'approved', 2, '2026-02-13 20:11:51', NULL, NULL),
(45, 'P145', 'Carlo', 'B', 'Lim', '09170000009', 'p1_carlo.lim@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 1', 'Blk 2 Lot 4', 'uploads/seed/p1_id9.png', 'uploads/seed/p1_bill9.png', 14.3542610, 120.9462610, 'approved', 2, '2026-02-13 20:11:51', NULL, NULL),
(46, 'P146', 'Grace', 'T', 'Salazar', '09170000010', 'p1_grace.salazar@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 1', 'Blk 2 Lot 5', 'uploads/seed/p1_id10.png', 'uploads/seed/p1_bill10.png', 14.3542810, 120.9462810, 'approved', 2, '2026-02-13 20:11:51', NULL, NULL),
(47, 'P247', 'Kevin', 'J', 'Villanueva', '09170000011', 'p2_kevin.villanueva@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 2', 'Blk 3 Lot 1', 'uploads/seed/p2_id1.png', 'uploads/seed/p2_bill1.png', 14.3537010, 120.9467010, 'approved', 3, '2026-02-13 20:11:51', NULL, NULL),
(48, 'P248', 'Nina', 'F', 'Torres', '09170000012', 'p2_nina.torres@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 2', 'Blk 3 Lot 2', 'uploads/seed/p2_id2.png', 'uploads/seed/p2_bill2.png', 14.3537210, 120.9467210, 'approved', 3, '2026-02-13 20:11:51', NULL, NULL),
(49, 'P249', 'Jasper', 'K', 'Aquino', '09170000013', 'p2_jasper.aquino@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 2', 'Blk 3 Lot 3', 'uploads/seed/p2_id3.png', 'uploads/seed/p2_bill3.png', 14.3537410, 120.9467410, 'approved', 3, '2026-02-13 20:11:51', NULL, NULL),
(50, 'P250', 'Bea', 'R', 'Mendoza', '09170000014', 'p2_bea.mendoza@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 2', 'Blk 3 Lot 4', 'uploads/seed/p2_id4.png', 'uploads/seed/p2_bill4.png', 14.3537610, 120.9467610, 'approved', 3, '2026-02-13 20:11:51', NULL, NULL),
(51, 'P251', 'Oscar', 'M', 'Pascual', '09170000015', 'p2_oscar.pascual@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 2', 'Blk 3 Lot 5', 'uploads/seed/p2_id5.png', 'uploads/seed/p2_bill5.png', 14.3537810, 120.9467810, 'approved', 3, '2026-02-13 20:11:51', NULL, NULL),
(52, 'P252', 'Elaine', 'S', 'Ramos', '09170000016', 'p2_elaine.ramos@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 2', 'Blk 4 Lot 1', 'uploads/seed/p2_id6.png', 'uploads/seed/p2_bill6.png', 14.3538010, 120.9468010, 'approved', 3, '2026-02-13 20:11:51', NULL, NULL),
(53, 'P253', 'Tony', 'L', 'Chua', '09170000017', 'p2_tony.chua@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 2', 'Blk 4 Lot 2', 'uploads/seed/p2_id7.png', 'uploads/seed/p2_bill7.png', 14.3538210, 120.9468210, 'approved', 3, '2026-02-13 20:11:51', NULL, NULL),
(54, 'P254', 'Kaye', 'D', 'Lopez', '09170000018', 'p2_kaye.lopez@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 2', 'Blk 4 Lot 3', 'uploads/seed/p2_id8.png', 'uploads/seed/p2_bill8.png', 14.3538410, 120.9468410, 'approved', 3, '2026-02-13 20:11:51', NULL, NULL),
(55, 'P255', 'Hanna', 'G', 'Valdez', '09170000019', 'p2_hanna.valdez@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 2', 'Blk 4 Lot 4', 'uploads/seed/p2_id9.png', 'uploads/seed/p2_bill9.png', 14.3538610, 120.9468610, 'approved', 3, '2026-02-13 20:11:51', NULL, NULL),
(56, 'P256', 'Leo', 'P', 'Castro', '09170000020', 'p2_leo.castro@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 2', 'Blk 4 Lot 5', 'uploads/seed/p2_id10.png', 'uploads/seed/p2_bill10.png', 14.3538810, 120.9468810, 'approved', 3, '2026-02-13 20:11:51', NULL, NULL),
(57, 'P357', 'Ivy', 'N', 'Bautista', '09170000021', 'p3_ivy.bautista@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 3', 'Blk 5 Lot 1', 'uploads/seed/p3_id1.png', 'uploads/seed/p3_bill1.png', 14.3532010, 120.9472010, 'approved', 4, '2026-02-13 20:11:51', NULL, NULL),
(58, 'P358', 'Arvin', 'C', 'Marquez', '09170000022', 'p3_arvin.marquez@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 3', 'Blk 5 Lot 2', 'uploads/seed/p3_id2.png', 'uploads/seed/p3_bill2.png', 14.3532210, 120.9472210, 'approved', 4, '2026-02-13 20:11:51', NULL, NULL),
(59, 'P359', 'Shane', 'R', 'Diaz', '09170000023', 'p3_shane.diaz@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 3', 'Blk 5 Lot 3', 'uploads/seed/p3_id3.png', 'uploads/seed/p3_bill3.png', 14.3532410, 120.9472410, 'approved', 4, '2026-02-13 20:11:51', NULL, NULL),
(60, 'P360', 'Mara', 'S', 'Velasco', '09170000024', 'p3_mara.velasco@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 3', 'Blk 5 Lot 4', 'uploads/seed/p3_id4.png', 'uploads/seed/p3_bill4.png', 14.3532610, 120.9472610, 'approved', 4, '2026-02-13 20:11:51', NULL, NULL),
(61, 'P361', 'Noel', 'T', 'Fernandez', '09170000025', 'p3_noel.fernandez@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 3', 'Blk 5 Lot 5', 'uploads/seed/p3_id5.png', 'uploads/seed/p3_bill5.png', 14.3532810, 120.9472810, 'approved', 4, '2026-02-13 20:11:51', NULL, NULL),
(62, 'P362', 'Bianca', 'L', 'Mercado', '09170000026', 'p3_bianca.mercado@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 3', 'Blk 6 Lot 1', 'uploads/seed/p3_id6.png', 'uploads/seed/p3_bill6.png', 14.3533010, 120.9473010, 'approved', 4, '2026-02-13 20:11:51', NULL, NULL),
(63, 'P363', 'Drew', 'P', 'Gomez', '09170000027', 'p3_drew.gomez@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 3', 'Blk 6 Lot 2', 'uploads/seed/p3_id7.png', 'uploads/seed/p3_bill7.png', 14.3533210, 120.9473210, 'approved', 4, '2026-02-13 20:11:51', NULL, NULL),
(64, 'P364', 'Tina', 'A', 'Sison', '09170000028', 'p3_tina.sison@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 3', 'Blk 6 Lot 3', 'uploads/seed/p3_id8.png', 'uploads/seed/p3_bill8.png', 14.3533410, 120.9473410, 'approved', 4, '2026-02-13 20:11:51', NULL, NULL),
(65, 'P365', 'Cedric', 'M', 'Herrera', '09170000029', 'p3_cedric.herrera@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 3', 'Blk 6 Lot 4', 'uploads/seed/p3_id9.png', 'uploads/seed/p3_bill9.png', 14.3533610, 120.9473610, 'approved', 4, '2026-02-13 20:11:51', NULL, NULL),
(66, 'P366', 'Aya', 'G', 'Pineda', '09170000030', 'p3_aya.pineda@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 3', 'Blk 6 Lot 5', 'uploads/seed/p3_id10.png', 'uploads/seed/p3_bill10.png', 14.3533810, 120.9473810, 'approved', 4, '2026-02-13 20:11:51', NULL, NULL);

--
-- Triggers `homeowners`
--
DELIMITER $$
CREATE TRIGGER `trg_homeowners_public_id_ai` AFTER INSERT ON `homeowners` FOR EACH ROW BEGIN
  UPDATE homeowners
  SET public_id = CONCAT(
    'P',
    CAST(REGEXP_REPLACE(NEW.phase, '[^0-9]', '') AS UNSIGNED),
    NEW.id
  )
  WHERE id = NEW.id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_homeowners_public_id_au` AFTER UPDATE ON `homeowners` FOR EACH ROW BEGIN
  IF NEW.phase <> OLD.phase THEN
    UPDATE homeowners
    SET public_id = CONCAT(
      'P',
      CAST(REGEXP_REPLACE(NEW.phase, '[^0-9]', '') AS UNSIGNED),
      NEW.id
    )
    WHERE id = NEW.id;
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `homeowner_feed_state`
--

CREATE TABLE `homeowner_feed_state` (
  `homeowner_id` int(11) NOT NULL,
  `last_ann_seen` datetime NOT NULL DEFAULT current_timestamp(),
  `last_comment_seen` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `homeowner_feed_state`
--

INSERT INTO `homeowner_feed_state` (`homeowner_id`, `last_ann_seen`, `last_comment_seen`, `created_at`, `updated_at`) VALUES
(35, '2026-02-08 15:58:22', '2026-02-08 15:58:22', '2026-02-08 07:58:22', '2026-02-08 07:58:22');

-- --------------------------------------------------------

--
-- Table structure for table `homeowner_positions`
--

CREATE TABLE `homeowner_positions` (
  `id` int(11) NOT NULL,
  `homeowner_id` int(11) NOT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3') NOT NULL,
  `position` varchar(80) NOT NULL DEFAULT 'Homeowner',
  `updated_by_admin_id` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `homeowner_positions`
--

INSERT INTO `homeowner_positions` (`id`, `homeowner_id`, `phase`, `position`, `updated_by_admin_id`, `updated_at`) VALUES
(1, 35, 'Phase 1', 'Volunteer', 2, '2026-02-17 02:15:54');

-- --------------------------------------------------------

--
-- Table structure for table `household_members`
--

CREATE TABLE `household_members` (
  `id` int(11) NOT NULL,
  `homeowner_id` int(11) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `relation` enum('Homeowner','Spouse','Child','Parent','Relative','Tenant','Caretaker') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `household_members`
--

INSERT INTO `household_members` (`id`, `homeowner_id`, `first_name`, `middle_name`, `last_name`, `relation`) VALUES
(5, 6, 'Patrick Justin', '', 'Baculpo', 'Homeowner'),
(6, 8, 'Patrick Justin', 'Abella', 'Baculpo', 'Homeowner'),
(7, 9, 'Patrick Justin', '', 'Baculpo', 'Homeowner'),
(26, 30, 'Patrick Justin', '', 'Baculpo', 'Homeowner'),
(28, 32, 'Patrick Justin', '', 'Baculpo', 'Homeowner'),
(29, 33, 'Patrick Justin', '', 'Baculpo', 'Homeowner'),
(30, 34, 'Patrick Justin', '', 'Baculpo', 'Homeowner'),
(0, 36, 'Patrick Justin', '', 'Baculpo', 'Caretaker');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `announcements`
--
ALTER TABLE `announcements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ann_admin_id` (`admin_id`),
  ADD KEY `idx_ann_phase` (`phase`),
  ADD KEY `idx_ann_dates` (`start_date`,`end_date`),
  ADD KEY `idx_ann_created_at` (`created_at`);

--
-- Indexes for table `announcement_attachments`
--
ALTER TABLE `announcement_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_aa_announcement_id` (`announcement_id`);

--
-- Indexes for table `announcement_comments`
--
ALTER TABLE `announcement_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `announcement_id` (`announcement_id`),
  ADD KEY `homeowner_id` (`homeowner_id`);

--
-- Indexes for table `announcement_likes`
--
ALTER TABLE `announcement_likes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_like` (`announcement_id`,`homeowner_id`),
  ADD KEY `homeowner_id` (`homeowner_id`);

--
-- Indexes for table `announcement_recipients`
--
ALTER TABLE `announcement_recipients`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ar_announcement_id` (`announcement_id`),
  ADD KEY `idx_ar_homeowner_id` (`homeowner_id`),
  ADD KEY `idx_ar_officer_id` (`officer_id`),
  ADD KEY `idx_ar_type` (`recipient_type`);

--
-- Indexes for table `election_nominations`
--
ALTER TABLE `election_nominations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_phase_position_homeowner` (`phase`,`position`,`homeowner_id`),
  ADD KEY `idx_phase` (`phase`),
  ADD KEY `idx_homeowner` (`homeowner_id`),
  ADD KEY `fk_nom_admin` (`created_by_admin_id`);

--
-- Indexes for table `finance_donations`
--
ALTER TABLE `finance_donations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_phase_date` (`phase`,`donation_date`),
  ADD KEY `fk_don_admin` (`created_by_admin_id`);

--
-- Indexes for table `finance_dues_settings`
--
ALTER TABLE `finance_dues_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_phase` (`phase`),
  ADD KEY `fk_dues_admin` (`updated_by_admin_id`);

--
-- Indexes for table `finance_expenses`
--
ALTER TABLE `finance_expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_phase_date` (`phase`,`expense_date`),
  ADD KEY `fk_exp_admin` (`created_by_admin_id`);

--
-- Indexes for table `finance_opening_balance`
--
ALTER TABLE `finance_opening_balance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_phase` (`phase`),
  ADD KEY `fk_open_admin` (`updated_by_admin_id`);

--
-- Indexes for table `finance_payments`
--
ALTER TABLE `finance_payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_homeowner_month` (`homeowner_id`,`pay_year`,`pay_month`),
  ADD KEY `idx_phase_date` (`phase`,`pay_year`,`pay_month`),
  ADD KEY `fk_pay_admin` (`created_by_admin_id`);

--
-- Indexes for table `finance_paymongo_checkouts`
--
ALTER TABLE `finance_paymongo_checkouts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_cs` (`checkout_session_id`),
  ADD KEY `idx_homeowner_period` (`homeowner_id`,`pay_year`,`pay_month`),
  ADD KEY `idx_phase_period` (`phase`,`pay_year`,`pay_month`);

--
-- Indexes for table `finance_report_requests`
--
ALTER TABLE `finance_report_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_phase_month` (`phase`,`report_year`,`report_month`),
  ADD KEY `fk_rep_admin` (`requested_by_admin_id`);

--
-- Indexes for table `hoa_officers`
--
ALTER TABLE `hoa_officers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_phase_position` (`phase`,`position`);

--
-- Indexes for table `homeowners`
--
ALTER TABLE `homeowners`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_homeowner_email` (`email`),
  ADD UNIQUE KEY `uniq_homeowners_public_id` (`public_id`);

--
-- Indexes for table `homeowner_feed_state`
--
ALTER TABLE `homeowner_feed_state`
  ADD PRIMARY KEY (`homeowner_id`);

--
-- Indexes for table `homeowner_positions`
--
ALTER TABLE `homeowner_positions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_homeowner_phase` (`homeowner_id`,`phase`),
  ADD KEY `fk_hp_admin` (`updated_by_admin_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `announcements`
--
ALTER TABLE `announcements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `announcement_attachments`
--
ALTER TABLE `announcement_attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `announcement_comments`
--
ALTER TABLE `announcement_comments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `announcement_likes`
--
ALTER TABLE `announcement_likes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `announcement_recipients`
--
ALTER TABLE `announcement_recipients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=100;

--
-- AUTO_INCREMENT for table `election_nominations`
--
ALTER TABLE `election_nominations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `finance_donations`
--
ALTER TABLE `finance_donations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `finance_dues_settings`
--
ALTER TABLE `finance_dues_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `finance_expenses`
--
ALTER TABLE `finance_expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `finance_opening_balance`
--
ALTER TABLE `finance_opening_balance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `finance_payments`
--
ALTER TABLE `finance_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `finance_paymongo_checkouts`
--
ALTER TABLE `finance_paymongo_checkouts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `finance_report_requests`
--
ALTER TABLE `finance_report_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `hoa_officers`
--
ALTER TABLE `hoa_officers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2053;

--
-- AUTO_INCREMENT for table `homeowners`
--
ALTER TABLE `homeowners`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=67;

--
-- AUTO_INCREMENT for table `homeowner_positions`
--
ALTER TABLE `homeowner_positions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `announcements`
--
ALTER TABLE `announcements`
  ADD CONSTRAINT `fk_ann_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `announcement_attachments`
--
ALTER TABLE `announcement_attachments`
  ADD CONSTRAINT `fk_announcement_attachments` FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `announcement_comments`
--
ALTER TABLE `announcement_comments`
  ADD CONSTRAINT `announcement_comments_ibfk_1` FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `announcement_comments_ibfk_2` FOREIGN KEY (`homeowner_id`) REFERENCES `homeowners` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `announcement_likes`
--
ALTER TABLE `announcement_likes`
  ADD CONSTRAINT `announcement_likes_ibfk_1` FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `announcement_likes_ibfk_2` FOREIGN KEY (`homeowner_id`) REFERENCES `homeowners` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `announcement_recipients`
--
ALTER TABLE `announcement_recipients`
  ADD CONSTRAINT `fk_ar_announcement` FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ar_homeowner` FOREIGN KEY (`homeowner_id`) REFERENCES `homeowners` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_ar_officer` FOREIGN KEY (`officer_id`) REFERENCES `hoa_officers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `election_nominations`
--
ALTER TABLE `election_nominations`
  ADD CONSTRAINT `fk_nom_admin` FOREIGN KEY (`created_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_nom_homeowner` FOREIGN KEY (`homeowner_id`) REFERENCES `homeowners` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `finance_donations`
--
ALTER TABLE `finance_donations`
  ADD CONSTRAINT `fk_don_admin` FOREIGN KEY (`created_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `finance_dues_settings`
--
ALTER TABLE `finance_dues_settings`
  ADD CONSTRAINT `fk_dues_admin` FOREIGN KEY (`updated_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `finance_expenses`
--
ALTER TABLE `finance_expenses`
  ADD CONSTRAINT `fk_exp_admin` FOREIGN KEY (`created_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `finance_opening_balance`
--
ALTER TABLE `finance_opening_balance`
  ADD CONSTRAINT `fk_open_admin` FOREIGN KEY (`updated_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `finance_payments`
--
ALTER TABLE `finance_payments`
  ADD CONSTRAINT `fk_pay_admin` FOREIGN KEY (`created_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_pay_homeowner` FOREIGN KEY (`homeowner_id`) REFERENCES `homeowners` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `finance_paymongo_checkouts`
--
ALTER TABLE `finance_paymongo_checkouts`
  ADD CONSTRAINT `fk_pm_homeowner` FOREIGN KEY (`homeowner_id`) REFERENCES `homeowners` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `finance_report_requests`
--
ALTER TABLE `finance_report_requests`
  ADD CONSTRAINT `fk_rep_admin` FOREIGN KEY (`requested_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `homeowner_feed_state`
--
ALTER TABLE `homeowner_feed_state`
  ADD CONSTRAINT `fk_feed_state_homeowner` FOREIGN KEY (`homeowner_id`) REFERENCES `homeowners` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `homeowner_positions`
--
ALTER TABLE `homeowner_positions`
  ADD CONSTRAINT `fk_hp_admin` FOREIGN KEY (`updated_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_hp_homeowner` FOREIGN KEY (`homeowner_id`) REFERENCES `homeowners` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
