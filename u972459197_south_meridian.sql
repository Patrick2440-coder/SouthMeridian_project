-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 22, 2026 at 11:40 PM
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
-- Database: `u972459197_south_meridian`
--

-- --------------------------------------------------------

--
-- Table structure for table `access_modules`
--

CREATE TABLE `access_modules` (
  `module_key` varchar(50) NOT NULL,
  `module_name` varchar(100) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `access_modules`
--

INSERT INTO `access_modules` (`module_key`, `module_name`, `sort_order`) VALUES
('activity_log', 'Activity Log', 10),
('announcements', 'Announcements', 4),
('community', 'Community', 8),
('complaints', 'Complaints', 5),
('dashboard', 'Dashboard', 1),
('finance', 'Finance', 6),
('homeowner_management', 'Homeowner Management', 2),
('parking', 'Parking', 7),
('settings', 'Settings', 11),
('user_management', 'User Management', 3),
('voting_management', 'Voting Management', 9);

-- --------------------------------------------------------

--
-- Table structure for table `access_permissions`
--

CREATE TABLE `access_permissions` (
  `id` int(11) NOT NULL,
  `position` enum('President','Vice President','Secretary','Treasurer','Auditor','Board of Director') NOT NULL,
  `module_key` varchar(50) NOT NULL,
  `is_allowed` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `access_permissions`
--

INSERT INTO `access_permissions` (`id`, `position`, `module_key`, `is_allowed`, `updated_at`) VALUES
(1, 'President', 'dashboard', 1, '2026-03-15 17:15:07'),
(2, 'President', 'homeowner_management', 1, '2026-03-15 17:15:07'),
(3, 'President', 'user_management', 1, '2026-03-15 17:15:07'),
(4, 'President', 'announcements', 1, '2026-03-15 17:15:07'),
(5, 'President', 'complaints', 1, '2026-03-15 17:15:07'),
(6, 'President', 'finance', 1, '2026-03-15 17:15:07'),
(7, 'President', 'parking', 1, '2026-03-15 18:59:09'),
(8, 'President', 'community', 1, '2026-03-18 19:33:47'),
(9, 'President', 'voting_management', 1, '2026-03-15 17:15:07'),
(10, 'President', 'settings', 1, '2026-03-15 17:15:07'),
(11, 'Vice President', 'dashboard', 1, '2026-03-15 17:15:07'),
(12, 'Vice President', 'homeowner_management', 1, '2026-03-31 07:46:54'),
(13, 'Vice President', 'user_management', 0, '2026-03-23 00:39:53'),
(14, 'Vice President', 'announcements', 1, '2026-03-31 07:46:54'),
(15, 'Vice President', 'complaints', 1, '2026-03-31 07:46:54'),
(16, 'Vice President', 'finance', 1, '2026-03-31 07:46:54'),
(17, 'Vice President', 'parking', 1, '2026-03-31 07:46:54'),
(18, 'Vice President', 'community', 1, '2026-03-31 07:46:54'),
(19, 'Vice President', 'voting_management', 0, '2026-03-15 17:15:07'),
(20, 'Vice President', 'settings', 0, '2026-03-15 17:15:07'),
(21, 'Secretary', 'dashboard', 1, '2026-03-15 17:15:07'),
(22, 'Secretary', 'homeowner_management', 1, '2026-03-15 17:15:07'),
(23, 'Secretary', 'user_management', 0, '2026-03-31 07:46:54'),
(24, 'Secretary', 'announcements', 1, '2026-03-15 17:15:07'),
(25, 'Secretary', 'complaints', 1, '2026-03-15 17:15:07'),
(26, 'Secretary', 'finance', 0, '2026-03-15 17:15:07'),
(27, 'Secretary', 'parking', 0, '2026-03-15 17:15:07'),
(28, 'Secretary', 'community', 0, '2026-03-15 17:15:07'),
(29, 'Secretary', 'voting_management', 0, '2026-03-15 17:15:07'),
(30, 'Secretary', 'settings', 0, '2026-03-15 17:15:07'),
(31, 'Treasurer', 'dashboard', 1, '2026-03-15 17:15:07'),
(32, 'Treasurer', 'homeowner_management', 0, '2026-03-15 17:15:07'),
(33, 'Treasurer', 'user_management', 0, '2026-03-15 17:15:07'),
(34, 'Treasurer', 'announcements', 0, '2026-03-15 17:15:07'),
(35, 'Treasurer', 'complaints', 0, '2026-03-15 17:15:07'),
(36, 'Treasurer', 'finance', 1, '2026-03-15 17:15:07'),
(37, 'Treasurer', 'parking', 0, '2026-03-15 17:15:07'),
(38, 'Treasurer', 'community', 0, '2026-03-15 17:15:07'),
(39, 'Treasurer', 'voting_management', 0, '2026-03-15 17:15:07'),
(40, 'Treasurer', 'settings', 0, '2026-03-15 17:15:07'),
(41, 'Auditor', 'dashboard', 1, '2026-03-15 17:15:07'),
(42, 'Auditor', 'homeowner_management', 0, '2026-03-15 18:53:27'),
(43, 'Auditor', 'user_management', 0, '2026-03-15 17:15:07'),
(44, 'Auditor', 'announcements', 0, '2026-03-15 17:15:07'),
(45, 'Auditor', 'complaints', 0, '2026-03-15 17:15:07'),
(46, 'Auditor', 'finance', 1, '2026-03-15 17:15:07'),
(47, 'Auditor', 'parking', 0, '2026-03-15 17:15:07'),
(48, 'Auditor', 'community', 0, '2026-03-15 17:15:07'),
(49, 'Auditor', 'voting_management', 0, '2026-03-15 17:15:07'),
(50, 'Auditor', 'settings', 0, '2026-03-15 17:15:07'),
(51, 'Board of Director', 'dashboard', 1, '2026-03-15 17:15:07'),
(52, 'Board of Director', 'homeowner_management', 0, '2026-03-15 17:15:07'),
(53, 'Board of Director', 'user_management', 0, '2026-03-15 17:15:07'),
(54, 'Board of Director', 'announcements', 1, '2026-03-15 17:15:07'),
(55, 'Board of Director', 'complaints', 1, '2026-03-15 17:15:07'),
(56, 'Board of Director', 'finance', 1, '2026-03-15 17:15:07'),
(57, 'Board of Director', 'parking', 1, '2026-03-15 17:15:07'),
(58, 'Board of Director', 'community', 1, '2026-03-15 17:15:07'),
(59, 'Board of Director', 'voting_management', 0, '2026-03-15 17:15:07'),
(60, 'Board of Director', 'settings', 0, '2026-03-15 17:15:07'),
(3601, 'President', 'activity_log', 0, '2026-03-31 07:46:54'),
(3602, 'Vice President', 'activity_log', 0, '2026-03-23 00:39:53'),
(3603, 'Secretary', 'activity_log', 0, '2026-03-31 07:46:54'),
(3604, 'Treasurer', 'activity_log', 0, '2026-03-19 20:03:52'),
(3605, 'Auditor', 'activity_log', 0, '2026-03-19 20:03:52'),
(3606, 'Board of Director', 'activity_log', 0, '2026-03-19 20:03:52');

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3','Superadmin') DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `module_key` varchar(50) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
  `role` enum('admin','superadmin') NOT NULL,
  `position` enum('President','Vice President','Secretary','Treasurer','Auditor','Board of Director','Superadmin') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admins`
--

INSERT INTO `admins` (`id`, `email`, `full_name`, `password`, `phase`, `role`, `position`) VALUES
(1, 'superadmin@gmail.com', 'System Superadmin', '12345678', 'Superadmin', 'superadmin', 'Superadmin'),
(2, 'p1.president@hoa.local', 'Adrian M. Reyes', '12345678', 'Phase 1', 'admin', 'President'),
(3, 'p1.vicepresident@hoa.local', 'Bianca L. Santos', '12345678', 'Phase 1', 'admin', 'Vice President'),
(4, 'p1.secretary@hoa.local', 'Carlo D. Mendoza', '12345678', 'Phase 1', 'admin', 'Secretary'),
(5, 'p1.treasurer@hoa.local', 'Diana P. Cruz', '12345678', 'Phase 1', 'admin', 'Treasurer'),
(6, 'p1.auditor@hoa.local', 'Ethan R. Flores', '12345678', 'Phase 1', 'admin', 'Auditor'),
(7, 'p1.board1@hoa.local', 'Fiona G. Garcia', '12345678', 'Phase 1', 'admin', 'Board of Director'),
(8, 'p1.board2@hoa.local', 'Gabriel T. Navarro', '12345678', 'Phase 1', 'admin', 'Board of Director'),
(9, 'p1.board3@hoa.local', 'Hannah C. Lim', '12345678', 'Phase 1', 'admin', 'Board of Director'),
(10, 'p1.board4@hoa.local', 'Ivan J. Torres', '12345678', 'Phase 1', 'admin', 'Board of Director'),
(11, 'p1.board5@hoa.local', 'Julia A. Ramos', '12345678', 'Phase 1', 'admin', 'Board of Director'),
(12, 'p1.board6@hoa.local', 'Kevin B. Bautista', '12345678', 'Phase 1', 'admin', 'Board of Director'),
(13, 'p2.president@hoa.local', 'Lara S. Villanueva', '12345678', 'Phase 2', 'admin', 'President'),
(14, 'p2.vicepresident@hoa.local', 'Marco V. Aquino', '12345678', 'Phase 2', 'admin', 'Vice President'),
(15, 'p2.secretary@hoa.local', 'Nina F. Castillo', '12345678', 'Phase 2', 'admin', 'Secretary'),
(16, 'p2.treasurer@hoa.local', 'Owen M. Pascual', '12345678', 'Phase 2', 'admin', 'Treasurer'),
(17, 'p2.auditor@hoa.local', 'Paula K. Dizon', '12345678', 'Phase 2', 'admin', 'Auditor'),
(18, 'p2.board1@hoa.local', 'Rafael L. Chua', '12345678', 'Phase 2', 'admin', 'Board of Director'),
(19, 'p2.board2@hoa.local', 'Sofia D. Valdez', '12345678', 'Phase 2', 'admin', 'Board of Director'),
(20, 'p2.board3@hoa.local', 'Tristan R. Lopez', '12345678', 'Phase 2', 'admin', 'Board of Director'),
(21, 'p2.board4@hoa.local', 'Ursula P. Tan', '12345678', 'Phase 2', 'admin', 'Board of Director'),
(22, 'p2.board5@hoa.local', 'Victor N. Ong', '12345678', 'Phase 2', 'admin', 'Board of Director'),
(23, 'p2.board6@hoa.local', 'Wendy C. Yu', '12345678', 'Phase 2', 'admin', 'Board of Director'),
(24, 'p3.president@hoa.local', 'Xavier A. Delgado', '12345678', 'Phase 3', 'admin', 'President'),
(25, 'p3.vicepresident@hoa.local', 'Yvonne S. Bautista', '12345678', 'Phase 3', 'admin', 'Vice President'),
(26, 'p3.secretary@hoa.local', 'Zachary P. Flores', '12345678', 'Phase 3', 'admin', 'Secretary'),
(27, 'p3.treasurer@hoa.local', 'Angela M. Mercado', '12345678', 'Phase 3', 'admin', 'Treasurer'),
(28, 'p3.auditor@hoa.local', 'Brandon L. Gomez', '12345678', 'Phase 3', 'admin', 'Auditor'),
(29, 'p3.board1@hoa.local', 'Camille A. Sison', '12345678', 'Phase 3', 'admin', 'Board of Director'),
(30, 'p3.board2@hoa.local', 'Daniel M. Herrera', '12345678', 'Phase 3', 'admin', 'Board of Director'),
(31, 'p3.board3@hoa.local', 'Erica G. Pineda', '12345678', 'Phase 3', 'admin', 'Board of Director'),
(32, 'p3.board4@hoa.local', 'Francis C. Marquez', '12345678', 'Phase 3', 'admin', 'Board of Director'),
(33, 'p3.board5@hoa.local', 'Grace R. Velasco', '12345678', 'Phase 3', 'admin', 'Board of Director'),
(34, 'p3.board6@hoa.local', 'Henry T. Fernandez', '12345678', 'Phase 3', 'admin', 'Board of Director');

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

-- --------------------------------------------------------

--
-- Table structure for table `communication_calls`
--

CREATE TABLE `communication_calls` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `caller_type` enum('homeowner','tenant','admin') NOT NULL,
  `caller_id` int(11) NOT NULL,
  `receiver_type` enum('homeowner','tenant','admin') NOT NULL,
  `receiver_id` int(11) NOT NULL,
  `phase` varchar(50) NOT NULL,
  `call_type` enum('audio','video') NOT NULL,
  `status` enum('calling','ringing','answered','declined','missed','ended','failed') NOT NULL DEFAULT 'calling',
  `started_at` datetime DEFAULT current_timestamp(),
  `answered_at` datetime DEFAULT NULL,
  `ended_at` datetime DEFAULT NULL,
  `duration_seconds` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `complaints`
--

CREATE TABLE `complaints` (
  `id` int(11) NOT NULL,
  `homeowner_id` int(11) NOT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3') NOT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `subject` varchar(255) NOT NULL,
  `category` enum('general','security','maintenance','noise','parking','neighbor','billing','other') NOT NULL DEFAULT 'general',
  `description` text NOT NULL,
  `status` enum('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
  `priority` enum('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `complaint_messages`
--

CREATE TABLE `complaint_messages` (
  `id` int(11) NOT NULL,
  `complaint_id` int(11) NOT NULL,
  `sender_type` enum('homeowner','admin') NOT NULL,
  `sender_homeowner_id` int(11) DEFAULT NULL,
  `sender_admin_id` int(11) DEFAULT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `election_nominations`
--

CREATE TABLE `election_nominations` (
  `id` int(11) NOT NULL,
  `election_id` int(11) DEFAULT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3') NOT NULL,
  `position` enum('President','Vice President','Secretary','Treasurer','Auditor','Board of Director') NOT NULL,
  `homeowner_id` int(11) NOT NULL,
  `created_by_admin_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `election_sessions`
--

CREATE TABLE `election_sessions` (
  `id` int(11) NOT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3') NOT NULL,
  `title` varchar(255) NOT NULL,
  `status` enum('draft','active','finished') NOT NULL DEFAULT 'draft',
  `started_at` datetime DEFAULT NULL,
  `ended_at` datetime DEFAULT NULL,
  `created_by_admin_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `election_votes`
--

CREATE TABLE `election_votes` (
  `id` int(11) NOT NULL,
  `election_id` int(11) NOT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3') NOT NULL,
  `position` enum('President','Vice President','Secretary','Treasurer','Auditor','Board of Director') NOT NULL,
  `voter_homeowner_id` int(11) NOT NULL,
  `nominee_homeowner_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `facility_rental_pricing`
--

CREATE TABLE `facility_rental_pricing` (
  `id` int(11) NOT NULL,
  `phase` varchar(50) NOT NULL,
  `court_rate_per_hour` int(11) NOT NULL DEFAULT 100,
  `court_rate_per_30min` int(11) NOT NULL DEFAULT 50,
  `tables_chairs_flat` int(11) NOT NULL DEFAULT 2500,
  `clubhouse_flat` int(11) NOT NULL DEFAULT 2500,
  `clubhouse_max_person` int(11) NOT NULL DEFAULT 50,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `facility_rental_pricing`
--

INSERT INTO `facility_rental_pricing` (`id`, `phase`, `court_rate_per_hour`, `court_rate_per_30min`, `tables_chairs_flat`, `clubhouse_flat`, `clubhouse_max_person`, `updated_at`) VALUES
(1, 'Phase 1', 100, 50, 2500, 2500, 50, '2026-03-05 07:40:26'),
(2, 'Phase 2', 100, 50, 2500, 2500, 50, '2026-03-05 07:40:26'),
(3, 'Phase 3', 100, 50, 2500, 2500, 50, '2026-03-05 07:40:26');

-- --------------------------------------------------------

--
-- Table structure for table `facility_rental_requests`
--

CREATE TABLE `facility_rental_requests` (
  `id` int(11) NOT NULL,
  `phase` varchar(50) NOT NULL,
  `homeowner_id` int(11) NOT NULL,
  `facility` enum('tables_chairs','court','clubhouse') NOT NULL,
  `start_dt` datetime NOT NULL,
  `end_dt` datetime NOT NULL,
  `purpose` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `guest_count` int(11) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','approved','denied','cancelled') NOT NULL DEFAULT 'pending',
  `admin_id` int(11) DEFAULT NULL,
  `admin_remarks` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `finance_donations`
--

CREATE TABLE `finance_donations` (
  `id` int(11) NOT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3') NOT NULL,
  `homeowner_id` int(11) DEFAULT NULL,
  `donor_name` varchar(255) NOT NULL,
  `donor_email` varchar(255) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `donation_date` date NOT NULL,
  `receipt_no` varchar(50) DEFAULT NULL,
  `message` varchar(255) DEFAULT NULL,
  `created_by_admin_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
(1, 'Phase 1', 0.00, 2, '2026-09-22 21:20:40'),
(2, 'Phase 2', 0.00, 13, '2026-09-22 21:20:40'),
(3, 'Phase 3', 0.00, 24, '2026-09-22 21:20:40');

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

--
-- Dumping data for table `finance_opening_balance`
--

INSERT INTO `finance_opening_balance` (`id`, `phase`, `opening_balance`, `as_of`, `updated_by_admin_id`, `updated_at`) VALUES
(1, 'Phase 1', 0.00, '2026-09-23', 2, '2026-09-22 21:20:40'),
(2, 'Phase 2', 0.00, '2026-09-23', 13, '2026-09-22 21:20:40'),
(3, 'Phase 3', 0.00, '2026-09-23', 24, '2026-09-22 21:20:40');

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

-- --------------------------------------------------------

--
-- Table structure for table `finance_report_snapshots`
--

CREATE TABLE `finance_report_snapshots` (
  `id` int(11) NOT NULL,
  `report_request_id` int(11) NOT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3') NOT NULL,
  `report_year` int(11) NOT NULL,
  `report_month` tinyint(4) NOT NULL,
  `snapshot_json` longtext NOT NULL,
  `approved_by_email` varchar(255) DEFAULT NULL,
  `approved_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
(1, 'Phase 1', 'President', 'Adrian M. Reyes', 'p1.president@hoa.local', 1, '2026-09-22 21:20:40'),
(2, 'Phase 1', 'Vice President', 'Bianca L. Santos', 'p1.vicepresident@hoa.local', 1, '2026-09-22 21:20:40'),
(3, 'Phase 1', 'Secretary', 'Carlo D. Mendoza', 'p1.secretary@hoa.local', 1, '2026-09-22 21:20:40'),
(4, 'Phase 1', 'Treasurer', 'Diana P. Cruz', 'p1.treasurer@hoa.local', 1, '2026-09-22 21:20:40'),
(5, 'Phase 1', 'Auditor', 'Ethan R. Flores', 'p1.auditor@hoa.local', 1, '2026-09-22 21:20:40'),
(6, 'Phase 1', 'Board of Director', 'Fiona G. Garcia', 'p1.board1@hoa.local', 1, '2026-09-22 21:20:40'),
(7, 'Phase 1', 'Board of Director', 'Gabriel T. Navarro', 'p1.board2@hoa.local', 1, '2026-09-22 21:20:40'),
(8, 'Phase 1', 'Board of Director', 'Hannah C. Lim', 'p1.board3@hoa.local', 1, '2026-09-22 21:20:40'),
(9, 'Phase 1', 'Board of Director', 'Ivan J. Torres', 'p1.board4@hoa.local', 1, '2026-09-22 21:20:40'),
(10, 'Phase 1', 'Board of Director', 'Julia A. Ramos', 'p1.board5@hoa.local', 1, '2026-09-22 21:20:40'),
(11, 'Phase 1', 'Board of Director', 'Kevin B. Bautista', 'p1.board6@hoa.local', 1, '2026-09-22 21:20:40'),
(12, 'Phase 2', 'President', 'Lara S. Villanueva', 'p2.president@hoa.local', 1, '2026-09-22 21:20:40'),
(13, 'Phase 2', 'Vice President', 'Marco V. Aquino', 'p2.vicepresident@hoa.local', 1, '2026-09-22 21:20:40'),
(14, 'Phase 2', 'Secretary', 'Nina F. Castillo', 'p2.secretary@hoa.local', 1, '2026-09-22 21:20:40'),
(15, 'Phase 2', 'Treasurer', 'Owen M. Pascual', 'p2.treasurer@hoa.local', 1, '2026-09-22 21:20:40'),
(16, 'Phase 2', 'Auditor', 'Paula K. Dizon', 'p2.auditor@hoa.local', 1, '2026-09-22 21:20:40'),
(17, 'Phase 2', 'Board of Director', 'Rafael L. Chua', 'p2.board1@hoa.local', 1, '2026-09-22 21:20:40'),
(18, 'Phase 2', 'Board of Director', 'Sofia D. Valdez', 'p2.board2@hoa.local', 1, '2026-09-22 21:20:40'),
(19, 'Phase 2', 'Board of Director', 'Tristan R. Lopez', 'p2.board3@hoa.local', 1, '2026-09-22 21:20:40'),
(20, 'Phase 2', 'Board of Director', 'Ursula P. Tan', 'p2.board4@hoa.local', 1, '2026-09-22 21:20:40'),
(21, 'Phase 2', 'Board of Director', 'Victor N. Ong', 'p2.board5@hoa.local', 1, '2026-09-22 21:20:40'),
(22, 'Phase 2', 'Board of Director', 'Wendy C. Yu', 'p2.board6@hoa.local', 1, '2026-09-22 21:20:40'),
(23, 'Phase 3', 'President', 'Xavier A. Delgado', 'p3.president@hoa.local', 1, '2026-09-22 21:20:40'),
(24, 'Phase 3', 'Vice President', 'Yvonne S. Bautista', 'p3.vicepresident@hoa.local', 1, '2026-09-22 21:20:40'),
(25, 'Phase 3', 'Secretary', 'Zachary P. Flores', 'p3.secretary@hoa.local', 1, '2026-09-22 21:20:40'),
(26, 'Phase 3', 'Treasurer', 'Angela M. Mercado', 'p3.treasurer@hoa.local', 1, '2026-09-22 21:20:40'),
(27, 'Phase 3', 'Auditor', 'Brandon L. Gomez', 'p3.auditor@hoa.local', 1, '2026-09-22 21:20:40'),
(28, 'Phase 3', 'Board of Director', 'Camille A. Sison', 'p3.board1@hoa.local', 1, '2026-09-22 21:20:40'),
(29, 'Phase 3', 'Board of Director', 'Daniel M. Herrera', 'p3.board2@hoa.local', 1, '2026-09-22 21:20:40'),
(30, 'Phase 3', 'Board of Director', 'Erica G. Pineda', 'p3.board3@hoa.local', 1, '2026-09-22 21:20:40'),
(31, 'Phase 3', 'Board of Director', 'Francis C. Marquez', 'p3.board4@hoa.local', 1, '2026-09-22 21:20:40'),
(32, 'Phase 3', 'Board of Director', 'Grace R. Velasco', 'p3.board5@hoa.local', 1, '2026-09-22 21:20:40'),
(33, 'Phase 3', 'Board of Director', 'Henry T. Fernandez', 'p3.board6@hoa.local', 1, '2026-09-22 21:20:40');

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
  `block` varchar(50) DEFAULT NULL,
  `lot` varchar(50) DEFAULT NULL,
  `street` varchar(100) DEFAULT NULL,
  `barangay` varchar(255) DEFAULT NULL,
  `city_municipality` varchar(255) DEFAULT NULL,
  `province` varchar(255) DEFAULT NULL,
  `region` varchar(255) DEFAULT NULL,
  `zip_code` varchar(50) DEFAULT NULL,
  `country` varchar(255) DEFAULT NULL,
  `other_location_info` text DEFAULT NULL,
  `length_of_residency` varchar(100) DEFAULT NULL,
  `residential_type` enum('Owner','Renter/Tenant') NOT NULL DEFAULT 'Owner',
  `emergency_contact_person` varchar(255) DEFAULT NULL,
  `emergency_contact_number` varchar(20) DEFAULT NULL,
  `exact_location` text DEFAULT NULL,
  `valid_id_path` varchar(255) NOT NULL,
  `proof_of_billing_path` varchar(255) NOT NULL,
  `profile_picture_path` varchar(255) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `map_x` int(11) DEFAULT NULL,
  `map_y` int(11) DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `admin_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `homeowners`
--

INSERT INTO `homeowners` (`id`, `public_id`, `first_name`, `middle_name`, `last_name`, `contact_number`, `email`, `password`, `must_change_password`, `phase`, `house_lot_number`, `block`, `lot`, `street`, `barangay`, `city_municipality`, `province`, `region`, `zip_code`, `country`, `other_location_info`, `length_of_residency`, `residential_type`, `emergency_contact_person`, `emergency_contact_number`, `exact_location`, `valid_id_path`, `proof_of_billing_path`, `profile_picture_path`, `latitude`, `longitude`, `map_x`, `map_y`, `status`, `admin_id`, `created_at`, `reset_token`, `reset_expires`) VALUES
(1, 'P1H001', 'Adrian', 'M.', 'Reyes', '09171000001', 'p1.president@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 1', 'Block 1 Lot 1', '1', '1', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09181000001', 'Block 1, Lot 1', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 2, '2026-09-22 21:20:40', NULL, NULL),
(2, 'P1H002', 'Bianca', 'L.', 'Santos', '09171000002', 'p1.vicepresident@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 1', 'Block 1 Lot 2', '1', '2', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09181000002', 'Block 1, Lot 2', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 2, '2026-09-22 21:20:40', NULL, NULL),
(3, 'P1H003', 'Carlo', 'D.', 'Mendoza', '09171000003', 'p1.secretary@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 1', 'Block 1 Lot 3', '1', '3', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09181000003', 'Block 1, Lot 3', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 2, '2026-09-22 21:20:40', NULL, NULL),
(4, 'P1H004', 'Diana', 'P.', 'Cruz', '09171000004', 'p1.treasurer@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 1', 'Block 2 Lot 1', '2', '1', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09181000004', 'Block 2, Lot 1', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 2, '2026-09-22 21:20:40', NULL, NULL),
(5, 'P1H005', 'Ethan', 'R.', 'Flores', '09171000005', 'p1.auditor@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 1', 'Block 2 Lot 2', '2', '2', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09181000005', 'Block 2, Lot 2', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 2, '2026-09-22 21:20:40', NULL, NULL),
(6, 'P1H006', 'Fiona', 'G.', 'Garcia', '09171000006', 'p1.board1@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 1', 'Block 2 Lot 3', '2', '3', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09181000006', 'Block 2, Lot 3', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 2, '2026-09-22 21:20:40', NULL, NULL),
(7, 'P1H007', 'Gabriel', 'T.', 'Navarro', '09171000007', 'p1.board2@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 1', 'Block 3 Lot 1', '3', '1', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09181000007', 'Block 3, Lot 1', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 2, '2026-09-22 21:20:40', NULL, NULL),
(8, 'P1H008', 'Hannah', 'C.', 'Lim', '09171000008', 'p1.board3@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 1', 'Block 3 Lot 2', '3', '2', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09181000008', 'Block 3, Lot 2', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 2, '2026-09-22 21:20:40', NULL, NULL),
(9, 'P1H009', 'Ivan', 'J.', 'Torres', '09171000009', 'p1.board4@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 1', 'Block 3 Lot 3', '3', '3', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09181000009', 'Block 3, Lot 3', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 2, '2026-09-22 21:20:40', NULL, NULL),
(10, 'P1H010', 'Julia', 'A.', 'Ramos', '09171000010', 'p1.board5@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 1', 'Block 4 Lot 1', '4', '1', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09181000010', 'Block 4, Lot 1', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 2, '2026-09-22 21:20:40', NULL, NULL),
(11, 'P1H011', 'Kevin', 'B.', 'Bautista', '09171000011', 'p1.board6@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 1', 'Block 4 Lot 2', '4', '2', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09181000011', 'Block 4, Lot 2', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 2, '2026-09-22 21:20:40', NULL, NULL),
(12, 'P1H012', 'Liam', 'C.', 'Domingo', '09171000012', 'p1.homeowner12@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 1', 'Block 4 Lot 3', '4', '3', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09181000012', 'Block 4, Lot 3', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 2, '2026-09-22 21:20:40', NULL, NULL),
(13, 'P1H013', 'Mia', 'R.', 'Salazar', '09171000013', 'p1.homeowner13@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 1', 'Block 5 Lot 1', '5', '1', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09181000013', 'Block 5, Lot 1', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 2, '2026-09-22 21:20:40', NULL, NULL),
(14, 'P1H014', 'Noah', 'P.', 'Evangelista', '09171000014', 'p1.homeowner14@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 1', 'Block 5 Lot 2', '5', '2', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09181000014', 'Block 5, Lot 2', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 2, '2026-09-22 21:20:40', NULL, NULL),
(15, 'P1H015', 'Olivia', 'T.', 'Mercado', '09171000015', 'p1.homeowner15@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 1', 'Block 5 Lot 3', '5', '3', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09181000015', 'Block 5, Lot 3', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 2, '2026-09-22 21:20:40', NULL, NULL),
(16, 'P2H001', 'Lara', 'S.', 'Villanueva', '09172000001', 'p2.president@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 2', 'Block 6 Lot 1', '6', '1', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09182000001', 'Block 6, Lot 1', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 13, '2026-09-22 21:20:40', NULL, NULL),
(17, 'P2H002', 'Marco', 'V.', 'Aquino', '09172000002', 'p2.vicepresident@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 2', 'Block 6 Lot 2', '6', '2', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09182000002', 'Block 6, Lot 2', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 13, '2026-09-22 21:20:40', NULL, NULL),
(18, 'P2H003', 'Nina', 'F.', 'Castillo', '09172000003', 'p2.secretary@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 2', 'Block 6 Lot 3', '6', '3', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09182000003', 'Block 6, Lot 3', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 13, '2026-09-22 21:20:40', NULL, NULL),
(19, 'P2H004', 'Owen', 'M.', 'Pascual', '09172000004', 'p2.treasurer@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 2', 'Block 7 Lot 1', '7', '1', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09182000004', 'Block 7, Lot 1', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 13, '2026-09-22 21:20:40', NULL, NULL),
(20, 'P2H005', 'Paula', 'K.', 'Dizon', '09172000005', 'p2.auditor@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 2', 'Block 7 Lot 2', '7', '2', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09182000005', 'Block 7, Lot 2', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 13, '2026-09-22 21:20:40', NULL, NULL),
(21, 'P2H006', 'Rafael', 'L.', 'Chua', '09172000006', 'p2.board1@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 2', 'Block 7 Lot 3', '7', '3', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09182000006', 'Block 7, Lot 3', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 13, '2026-09-22 21:20:40', NULL, NULL),
(22, 'P2H007', 'Sofia', 'D.', 'Valdez', '09172000007', 'p2.board2@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 2', 'Block 8 Lot 1', '8', '1', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09182000007', 'Block 8, Lot 1', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 13, '2026-09-22 21:20:40', NULL, NULL),
(23, 'P2H008', 'Tristan', 'R.', 'Lopez', '09172000008', 'p2.board3@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 2', 'Block 8 Lot 2', '8', '2', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09182000008', 'Block 8, Lot 2', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 13, '2026-09-22 21:20:40', NULL, NULL),
(24, 'P2H009', 'Ursula', 'P.', 'Tan', '09172000009', 'p2.board4@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 2', 'Block 8 Lot 3', '8', '3', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09182000009', 'Block 8, Lot 3', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 13, '2026-09-22 21:20:40', NULL, NULL),
(25, 'P2H010', 'Victor', 'N.', 'Ong', '09172000010', 'p2.board5@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 2', 'Block 9 Lot 1', '9', '1', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09182000010', 'Block 9, Lot 1', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 13, '2026-09-22 21:20:40', NULL, NULL),
(26, 'P2H011', 'Wendy', 'C.', 'Yu', '09172000011', 'p2.board6@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 2', 'Block 9 Lot 2', '9', '2', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09182000011', 'Block 9, Lot 2', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 13, '2026-09-22 21:20:40', NULL, NULL),
(27, 'P2H012', 'Peter', 'A.', 'Dominguez', '09172000012', 'p2.homeowner12@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 2', 'Block 9 Lot 3', '9', '3', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09182000012', 'Block 9, Lot 3', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 13, '2026-09-22 21:20:40', NULL, NULL),
(28, 'P2H013', 'Queenie', 'L.', 'Sarmiento', '09172000013', 'p2.homeowner13@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 2', 'Block 10 Lot 1', '10', '1', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09182000013', 'Block 10, Lot 1', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 13, '2026-09-22 21:20:40', NULL, NULL),
(29, 'P2H014', 'Ryan', 'M.', 'Andrada', '09172000014', 'p2.homeowner14@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 2', 'Block 10 Lot 2', '10', '2', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09182000014', 'Block 10, Lot 2', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 13, '2026-09-22 21:20:40', NULL, NULL),
(30, 'P2H015', 'Sarah', 'D.', 'Manalo', '09172000015', 'p2.homeowner15@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 2', 'Block 10 Lot 3', '10', '3', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09182000015', 'Block 10, Lot 3', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 13, '2026-09-22 21:20:40', NULL, NULL),
(31, 'P3H001', 'Xavier', 'A.', 'Delgado', '09173000001', 'p3.president@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 3', 'Block 11 Lot 1', '11', '1', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09183000001', 'Block 11, Lot 1', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 24, '2026-09-22 21:20:40', NULL, NULL),
(32, 'P3H002', 'Yvonne', 'S.', 'Bautista', '09173000002', 'p3.vicepresident@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 3', 'Block 11 Lot 2', '11', '2', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09183000002', 'Block 11, Lot 2', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 24, '2026-09-22 21:20:40', NULL, NULL),
(33, 'P3H003', 'Zachary', 'P.', 'Flores', '09173000003', 'p3.secretary@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 3', 'Block 11 Lot 3', '11', '3', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09183000003', 'Block 11, Lot 3', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 24, '2026-09-22 21:20:40', NULL, NULL),
(34, 'P3H004', 'Angela', 'M.', 'Mercado', '09173000004', 'p3.treasurer@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 3', 'Block 12 Lot 1', '12', '1', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09183000004', 'Block 12, Lot 1', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 24, '2026-09-22 21:20:40', NULL, NULL),
(35, 'P3H005', 'Brandon', 'L.', 'Gomez', '09173000005', 'p3.auditor@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 3', 'Block 12 Lot 2', '12', '2', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09183000005', 'Block 12, Lot 2', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 24, '2026-09-22 21:20:40', NULL, NULL),
(36, 'P3H006', 'Camille', 'A.', 'Sison', '09173000006', 'p3.board1@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 3', 'Block 12 Lot 3', '12', '3', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09183000006', 'Block 12, Lot 3', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 24, '2026-09-22 21:20:40', NULL, NULL),
(37, 'P3H007', 'Daniel', 'M.', 'Herrera', '09173000007', 'p3.board2@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 3', 'Block 13 Lot 1', '13', '1', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09183000007', 'Block 13, Lot 1', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 24, '2026-09-22 21:20:40', NULL, NULL),
(38, 'P3H008', 'Erica', 'G.', 'Pineda', '09173000008', 'p3.board3@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 3', 'Block 13 Lot 2', '13', '2', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09183000008', 'Block 13, Lot 2', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 24, '2026-09-22 21:20:40', NULL, NULL),
(39, 'P3H009', 'Francis', 'C.', 'Marquez', '09173000009', 'p3.board4@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 3', 'Block 13 Lot 3', '13', '3', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09183000009', 'Block 13, Lot 3', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 24, '2026-09-22 21:20:40', NULL, NULL),
(40, 'P3H010', 'Grace', 'R.', 'Velasco', '09173000010', 'p3.board5@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 3', 'Block 14 Lot 1', '14', '1', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09183000010', 'Block 14, Lot 1', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 24, '2026-09-22 21:20:40', NULL, NULL),
(41, 'P3H011', 'Henry', 'T.', 'Fernandez', '09173000011', 'p3.board6@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 3', 'Block 14 Lot 2', '14', '2', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09183000011', 'Block 14, Lot 2', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 24, '2026-09-22 21:20:40', NULL, NULL),
(42, 'P3H012', 'Theo', 'G.', 'Rosales', '09173000012', 'p3.homeowner12@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 3', 'Block 14 Lot 3', '14', '3', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09183000012', 'Block 14, Lot 3', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 24, '2026-09-22 21:20:40', NULL, NULL),
(43, 'P3H013', 'Uma', 'P.', 'Serrano', '09173000013', 'p3.homeowner13@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 3', 'Block 15 Lot 1', '15', '1', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09183000013', 'Block 15, Lot 1', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 24, '2026-09-22 21:20:40', NULL, NULL),
(44, 'P3H014', 'Vince', 'R.', 'Padilla', '09173000014', 'p3.homeowner14@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 3', 'Block 15 Lot 2', '15', '2', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09183000014', 'Block 15, Lot 2', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 24, '2026-09-22 21:20:40', NULL, NULL),
(45, 'P3H015', 'Wella', 'M.', 'Alcantara', '09173000015', 'p3.homeowner15@hoa.local', '$2y$12$FvEM4JetPahr/U.yzajMuOVSHydVMKPGGHayE0w1J99aXsIgHC85q', 0, 'Phase 3', 'Block 15 Lot 3', '15', '3', NULL, 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', '', '1 year', 'Owner', 'Emergency Contact', '09183000015', 'Block 15, Lot 3', 'imports/not_provided', 'imports/not_provided', NULL, NULL, NULL, NULL, NULL, 'approved', 24, '2026-09-22 21:20:40', NULL, NULL),
(46, 'P146', 'Patrick', 'Justin', 'Baculpo', '09916963390', 'baculpopatrick2440@gmail.com', '$2y$10$/GMjMRWJ0p7AZQzn1H.ak.VmXnizrCNNv7W3d5LMAc4vXd/XKy6JC', 0, 'Phase 1', 'Block 9 Lot 4', '9', '4', 'Horizon Ave.', 'Salitran IV', 'Dasmarinas City', 'Cavite', 'CALABARZON', '4114', 'Philippines', NULL, NULL, 'Owner', NULL, NULL, NULL, 'uploads/3579848c75935c791d98daacc1370125_id.jpg', 'uploads/a5a3ff1819e4a040ae2c57338ccd4da7_proof.jpg', NULL, NULL, NULL, 1732, 1768, 'approved', 2, '2026-09-22 21:32:56', NULL, NULL);

--
-- Triggers `homeowners`
--
DELIMITER $$
CREATE TRIGGER `homeowners_bi` BEFORE INSERT ON `homeowners` FOR EACH ROW BEGIN
  -- example only
  IF NEW.status IS NULL THEN
    SET NEW.status = 'pending';
  END IF;

  IF NEW.created_at IS NULL THEN
    SET NEW.created_at = NOW();
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `homeowner_calls`
--

CREATE TABLE `homeowner_calls` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `phase` varchar(50) NOT NULL,
  `caller_homeowner_id` int(11) NOT NULL,
  `receiver_homeowner_id` int(11) NOT NULL,
  `call_type` enum('audio','video') NOT NULL,
  `status` enum('ringing','answered','declined','missed','ended','failed') NOT NULL DEFAULT 'ringing',
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `answered_at` datetime DEFAULT NULL,
  `ended_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `homeowner_call_signals`
--

CREATE TABLE `homeowner_call_signals` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `call_id` bigint(20) UNSIGNED NOT NULL,
  `sender_homeowner_id` int(11) NOT NULL,
  `signal_type` enum('offer','answer','ice') NOT NULL,
  `payload_json` longtext NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
(46, '2026-09-23 05:34:04', '2026-09-23 05:34:04', '2026-09-22 21:34:04', '2026-09-22 21:34:04');

-- --------------------------------------------------------

--
-- Table structure for table `homeowner_import_queue`
--

CREATE TABLE `homeowner_import_queue` (
  `id` int(11) NOT NULL,
  `source_row` int(11) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `contact_number` varchar(20) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phase` varchar(20) NOT NULL,
  `block` varchar(50) DEFAULT NULL,
  `lot` varchar(50) DEFAULT NULL,
  `street` varchar(100) DEFAULT NULL,
  `map_x` int(11) DEFAULT 0,
  `map_y` int(11) DEFAULT 0,
  `house_lot_number` varchar(100) NOT NULL,
  `barangay` varchar(255) NOT NULL DEFAULT 'Salitran IV',
  `city_municipality` varchar(255) NOT NULL DEFAULT 'Dasmarinas City',
  `province` varchar(255) NOT NULL DEFAULT 'Cavite',
  `region` varchar(255) DEFAULT NULL,
  `zip_code` varchar(50) DEFAULT NULL,
  `country` varchar(255) DEFAULT NULL,
  `other_location_info` text DEFAULT NULL,
  `exact_location` text DEFAULT NULL,
  `length_of_residency` varchar(100) NOT NULL,
  `residential_type` varchar(30) NOT NULL,
  `emergency_contact_person` varchar(255) NOT NULL,
  `emergency_contact_number` varchar(20) NOT NULL,
  `status` enum('pending','duplicate','approved','rejected') NOT NULL DEFAULT 'pending',
  `duplicate_homeowner_id` int(11) DEFAULT NULL,
  `approved_homeowner_id` int(11) DEFAULT NULL,
  `imported_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `approved_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `homeowner_officer_messages`
--

CREATE TABLE `homeowner_officer_messages` (
  `id` int(11) NOT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3') NOT NULL,
  `homeowner_id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `sender_type` enum('homeowner','admin') NOT NULL DEFAULT 'homeowner',
  `message` text NOT NULL,
  `attachment_name` varchar(255) DEFAULT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `attachment_type` varchar(100) DEFAULT NULL,
  `is_read_by_homeowner` tinyint(1) NOT NULL DEFAULT 0,
  `is_read_by_admin` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
(1, 1, 'Phase 1', 'President', 2, '2026-09-22 21:20:40'),
(2, 2, 'Phase 1', 'Vice President', 2, '2026-09-22 21:20:40'),
(3, 3, 'Phase 1', 'Secretary', 2, '2026-09-22 21:20:40'),
(4, 4, 'Phase 1', 'Treasurer', 2, '2026-09-22 21:20:40'),
(5, 5, 'Phase 1', 'Auditor', 2, '2026-09-22 21:20:40'),
(6, 6, 'Phase 1', 'Board of Director', 2, '2026-09-22 21:20:40'),
(7, 7, 'Phase 1', 'Board of Director', 2, '2026-09-22 21:20:40'),
(8, 8, 'Phase 1', 'Board of Director', 2, '2026-09-22 21:20:40'),
(9, 9, 'Phase 1', 'Board of Director', 2, '2026-09-22 21:20:40'),
(10, 10, 'Phase 1', 'Board of Director', 2, '2026-09-22 21:20:40'),
(11, 11, 'Phase 1', 'Board of Director', 2, '2026-09-22 21:20:40'),
(12, 12, 'Phase 1', 'Homeowner', 2, '2026-09-22 21:20:40'),
(13, 13, 'Phase 1', 'Homeowner', 2, '2026-09-22 21:20:40'),
(14, 14, 'Phase 1', 'Homeowner', 2, '2026-09-22 21:20:40'),
(15, 15, 'Phase 1', 'Homeowner', 2, '2026-09-22 21:20:40'),
(16, 16, 'Phase 2', 'President', 13, '2026-09-22 21:20:40'),
(17, 17, 'Phase 2', 'Vice President', 13, '2026-09-22 21:20:40'),
(18, 18, 'Phase 2', 'Secretary', 13, '2026-09-22 21:20:40'),
(19, 19, 'Phase 2', 'Treasurer', 13, '2026-09-22 21:20:40'),
(20, 20, 'Phase 2', 'Auditor', 13, '2026-09-22 21:20:40'),
(21, 21, 'Phase 2', 'Board of Director', 13, '2026-09-22 21:20:40'),
(22, 22, 'Phase 2', 'Board of Director', 13, '2026-09-22 21:20:40'),
(23, 23, 'Phase 2', 'Board of Director', 13, '2026-09-22 21:20:40'),
(24, 24, 'Phase 2', 'Board of Director', 13, '2026-09-22 21:20:40'),
(25, 25, 'Phase 2', 'Board of Director', 13, '2026-09-22 21:20:40'),
(26, 26, 'Phase 2', 'Board of Director', 13, '2026-09-22 21:20:40'),
(27, 27, 'Phase 2', 'Homeowner', 13, '2026-09-22 21:20:40'),
(28, 28, 'Phase 2', 'Homeowner', 13, '2026-09-22 21:20:40'),
(29, 29, 'Phase 2', 'Homeowner', 13, '2026-09-22 21:20:40'),
(30, 30, 'Phase 2', 'Homeowner', 13, '2026-09-22 21:20:40'),
(31, 31, 'Phase 3', 'President', 24, '2026-09-22 21:20:40'),
(32, 32, 'Phase 3', 'Vice President', 24, '2026-09-22 21:20:40'),
(33, 33, 'Phase 3', 'Secretary', 24, '2026-09-22 21:20:40'),
(34, 34, 'Phase 3', 'Treasurer', 24, '2026-09-22 21:20:40'),
(35, 35, 'Phase 3', 'Auditor', 24, '2026-09-22 21:20:40'),
(36, 36, 'Phase 3', 'Board of Director', 24, '2026-09-22 21:20:40'),
(37, 37, 'Phase 3', 'Board of Director', 24, '2026-09-22 21:20:40'),
(38, 38, 'Phase 3', 'Board of Director', 24, '2026-09-22 21:20:40'),
(39, 39, 'Phase 3', 'Board of Director', 24, '2026-09-22 21:20:40'),
(40, 40, 'Phase 3', 'Board of Director', 24, '2026-09-22 21:20:40'),
(41, 41, 'Phase 3', 'Board of Director', 24, '2026-09-22 21:20:40'),
(42, 42, 'Phase 3', 'Homeowner', 24, '2026-09-22 21:20:40'),
(43, 43, 'Phase 3', 'Homeowner', 24, '2026-09-22 21:20:40'),
(44, 44, 'Phase 3', 'Homeowner', 24, '2026-09-22 21:20:40'),
(45, 45, 'Phase 3', 'Homeowner', 24, '2026-09-22 21:20:40');

-- --------------------------------------------------------

--
-- Table structure for table `homeowner_private_messages`
--

CREATE TABLE `homeowner_private_messages` (
  `id` int(11) NOT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3') NOT NULL,
  `sender_homeowner_id` int(11) NOT NULL,
  `receiver_homeowner_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `attachment_name` varchar(255) DEFAULT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `attachment_type` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
(1, 1, 'Adrian', 'M.', 'Reyes', 'Homeowner'),
(2, 2, 'Bianca', 'L.', 'Santos', 'Homeowner'),
(3, 3, 'Carlo', 'D.', 'Mendoza', 'Homeowner'),
(4, 4, 'Diana', 'P.', 'Cruz', 'Homeowner'),
(5, 5, 'Ethan', 'R.', 'Flores', 'Homeowner'),
(6, 6, 'Fiona', 'G.', 'Garcia', 'Homeowner'),
(7, 7, 'Gabriel', 'T.', 'Navarro', 'Homeowner'),
(8, 8, 'Hannah', 'C.', 'Lim', 'Homeowner'),
(9, 9, 'Ivan', 'J.', 'Torres', 'Homeowner'),
(10, 10, 'Julia', 'A.', 'Ramos', 'Homeowner'),
(11, 11, 'Kevin', 'B.', 'Bautista', 'Homeowner'),
(12, 12, 'Liam', 'C.', 'Domingo', 'Homeowner'),
(13, 13, 'Mia', 'R.', 'Salazar', 'Homeowner'),
(14, 14, 'Noah', 'P.', 'Evangelista', 'Homeowner'),
(15, 15, 'Olivia', 'T.', 'Mercado', 'Homeowner'),
(16, 16, 'Lara', 'S.', 'Villanueva', 'Homeowner'),
(17, 17, 'Marco', 'V.', 'Aquino', 'Homeowner'),
(18, 18, 'Nina', 'F.', 'Castillo', 'Homeowner'),
(19, 19, 'Owen', 'M.', 'Pascual', 'Homeowner'),
(20, 20, 'Paula', 'K.', 'Dizon', 'Homeowner'),
(21, 21, 'Rafael', 'L.', 'Chua', 'Homeowner'),
(22, 22, 'Sofia', 'D.', 'Valdez', 'Homeowner'),
(23, 23, 'Tristan', 'R.', 'Lopez', 'Homeowner'),
(24, 24, 'Ursula', 'P.', 'Tan', 'Homeowner'),
(25, 25, 'Victor', 'N.', 'Ong', 'Homeowner'),
(26, 26, 'Wendy', 'C.', 'Yu', 'Homeowner'),
(27, 27, 'Peter', 'A.', 'Dominguez', 'Homeowner'),
(28, 28, 'Queenie', 'L.', 'Sarmiento', 'Homeowner'),
(29, 29, 'Ryan', 'M.', 'Andrada', 'Homeowner'),
(30, 30, 'Sarah', 'D.', 'Manalo', 'Homeowner'),
(31, 31, 'Xavier', 'A.', 'Delgado', 'Homeowner'),
(32, 32, 'Yvonne', 'S.', 'Bautista', 'Homeowner'),
(33, 33, 'Zachary', 'P.', 'Flores', 'Homeowner'),
(34, 34, 'Angela', 'M.', 'Mercado', 'Homeowner'),
(35, 35, 'Brandon', 'L.', 'Gomez', 'Homeowner'),
(36, 36, 'Camille', 'A.', 'Sison', 'Homeowner'),
(37, 37, 'Daniel', 'M.', 'Herrera', 'Homeowner'),
(38, 38, 'Erica', 'G.', 'Pineda', 'Homeowner'),
(39, 39, 'Francis', 'C.', 'Marquez', 'Homeowner'),
(40, 40, 'Grace', 'R.', 'Velasco', 'Homeowner'),
(41, 41, 'Henry', 'T.', 'Fernandez', 'Homeowner'),
(42, 42, 'Theo', 'G.', 'Rosales', 'Homeowner'),
(43, 43, 'Uma', 'P.', 'Serrano', 'Homeowner'),
(44, 44, 'Vince', 'R.', 'Padilla', 'Homeowner'),
(45, 45, 'Wella', 'M.', 'Alcantara', 'Homeowner'),
(46, 46, 'Erick', 'Alva', 'Rez', 'Relative');

-- --------------------------------------------------------

--
-- Table structure for table `login_security_appeals`
--

CREATE TABLE `login_security_appeals` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `security_state_id` bigint(20) UNSIGNED NOT NULL,
  `token_hash` char(64) NOT NULL,
  `status` enum('available','pending','approved','rejected') NOT NULL DEFAULT 'available',
  `appeal_message` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `email_sent_at` datetime DEFAULT NULL,
  `requested_at` datetime DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `reviewed_by_admin_id` int(11) DEFAULT NULL,
  `admin_remarks` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `login_security_state`
--

CREATE TABLE `login_security_state` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `account_type` enum('admin','homeowner','tenant') NOT NULL,
  `account_id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phase` varchar(50) DEFAULT NULL,
  `failed_attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `cooldown_stage` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `cooldown_until` datetime DEFAULT NULL,
  `hard_locked` tinyint(1) NOT NULL DEFAULT 0,
  `hard_locked_at` datetime DEFAULT NULL,
  `last_failed_at` datetime DEFAULT NULL,
  `last_failed_ip` varchar(45) DEFAULT NULL,
  `last_user_agent` varchar(500) DEFAULT NULL,
  `unlocked_at` datetime DEFAULT NULL,
  `unlocked_by_admin_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `login_security_state`
--

INSERT INTO `login_security_state` (`id`, `account_type`, `account_id`, `email`, `phase`, `failed_attempts`, `cooldown_stage`, `cooldown_until`, `hard_locked`, `hard_locked_at`, `last_failed_at`, `last_failed_ip`, `last_user_agent`, `unlocked_at`, `unlocked_by_admin_id`, `created_at`, `updated_at`) VALUES
(1, 'admin', 2, 'p1.president@hoa.local', 'Phase 1', 2, 0, NULL, 0, NULL, '2026-09-23 05:31:43', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', NULL, NULL, '2026-09-23 05:22:29', '2026-09-23 05:31:43'),
(7, 'homeowner', 46, 'baculpopatrick2440@gmail.com', 'Phase 1', 0, 1, '2026-09-23 05:34:31', 0, NULL, '2026-09-23 05:34:21', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', NULL, NULL, '2026-09-23 05:34:04', '2026-09-23 05:34:21');

-- --------------------------------------------------------

--
-- Table structure for table `parking_paymongo_checkouts`
--

CREATE TABLE `parking_paymongo_checkouts` (
  `id` int(11) NOT NULL,
  `checkout_session_id` varchar(100) NOT NULL,
  `checkout_url` text DEFAULT NULL,
  `permit_id` int(11) NOT NULL,
  `homeowner_id` int(11) NOT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` enum('pending','paid','failed','expired') NOT NULL DEFAULT 'pending',
  `payment_id` varchar(100) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `last_event_type` varchar(100) DEFAULT NULL,
  `last_event_id` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `parking_permits`
--

CREATE TABLE `parking_permits` (
  `id` int(11) NOT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3') NOT NULL,
  `homeowner_id` int(11) NOT NULL,
  `request_type` enum('new','renew') NOT NULL DEFAULT 'new',
  `renew_of_id` int(11) DEFAULT NULL,
  `plate_no` varchar(30) NOT NULL,
  `vehicle_type` enum('car','motorcycle','ebike') NOT NULL,
  `vehicle_make` varchar(80) DEFAULT NULL,
  `vehicle_model` varchar(80) DEFAULT NULL,
  `vehicle_color` varchar(50) DEFAULT NULL,
  `permit_no` varchar(30) DEFAULT NULL,
  `sticker_year` int(11) NOT NULL DEFAULT year(curdate()),
  `permit_duration` enum('1_month','3_months','6_months','1_year') NOT NULL,
  `payment_method` enum('online','cash') NOT NULL,
  `contract_path` varchar(255) DEFAULT NULL,
  `status` enum('pending','active','expired','revoked','rejected') NOT NULL DEFAULT 'pending',
  `valid_from` date DEFAULT NULL,
  `valid_until` date DEFAULT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `approved_by_admin_id` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejected_reason` varchar(255) DEFAULT NULL,
  `revoked_reason` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `vehicle_front_path` varchar(255) DEFAULT NULL,
  `vehicle_back_path` varchar(255) DEFAULT NULL,
  `payment_status` enum('unpaid','for payment','paid','failed','waived') NOT NULL DEFAULT 'unpaid'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `parking_violations`
--

CREATE TABLE `parking_violations` (
  `id` int(11) NOT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3') NOT NULL,
  `permit_id` int(11) DEFAULT NULL,
  `homeowner_id` int(11) DEFAULT NULL,
  `plate_no` varchar(30) NOT NULL,
  `violation_type` varchar(80) NOT NULL,
  `location` varchar(120) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `fine_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('open','paid','cleared','void') NOT NULL DEFAULT 'open',
  `issued_at` datetime NOT NULL DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL,
  `resolved_by_admin_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `public_chat_messages`
--

CREATE TABLE `public_chat_messages` (
  `id` int(11) NOT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3') NOT NULL,
  `homeowner_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `attachment_name` varchar(255) DEFAULT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `attachment_type` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `public_chat_mutes`
--

CREATE TABLE `public_chat_mutes` (
  `id` int(11) NOT NULL,
  `homeowner_id` int(11) NOT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3') NOT NULL,
  `is_muted` tinyint(1) NOT NULL DEFAULT 1,
  `reason` varchar(255) DEFAULT NULL,
  `muted_by_admin_id` int(11) DEFAULT NULL,
  `muted_at` datetime DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `staff_applications`
--

CREATE TABLE `staff_applications` (
  `id` int(11) NOT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3') NOT NULL,
  `staff_type` enum('Guard','Volunteer','Other') NOT NULL DEFAULT 'Guard',
  `source_type` enum('homeowner','non_resident') NOT NULL DEFAULT 'homeowner',
  `homeowner_id` int(11) DEFAULT NULL,
  `full_name` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `position_title` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `valid_id_path` varchar(255) DEFAULT NULL,
  `resume_path` varchar(255) DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `applied_by_admin_id` int(11) DEFAULT NULL,
  `president_admin_id` int(11) DEFAULT NULL,
  `president_action_at` datetime DEFAULT NULL,
  `president_remarks` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `staff_members`
--

CREATE TABLE `staff_members` (
  `id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3') NOT NULL,
  `staff_type` enum('Guard','Volunteer','Other') NOT NULL DEFAULT 'Guard',
  `source_type` enum('homeowner','non_resident') NOT NULL DEFAULT 'homeowner',
  `homeowner_id` int(11) DEFAULT NULL,
  `full_name` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `position_title` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `approved_by_admin_id` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tenants`
--

CREATE TABLE `tenants` (
  `id` int(11) NOT NULL,
  `homeowner_id` int(11) NOT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3') NOT NULL,
  `house_lot_number` varchar(50) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `contact_number` varchar(15) DEFAULT NULL,
  `valid_id_path` varchar(255) DEFAULT NULL,
  `can_pay_dues` tinyint(1) NOT NULL DEFAULT 1,
  `can_rent` tinyint(1) NOT NULL DEFAULT 1,
  `can_parking` tinyint(1) NOT NULL DEFAULT 1,
  `can_announcements` tinyint(1) NOT NULL DEFAULT 1,
  `lease_start` date DEFAULT NULL,
  `lease_end` date DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `registered_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `access_modules`
--
ALTER TABLE `access_modules`
  ADD PRIMARY KEY (`module_key`);

--
-- Indexes for table `access_permissions`
--
ALTER TABLE `access_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_position_module` (`position`,`module_key`),
  ADD KEY `idx_module_key` (`module_key`);

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_admin_id` (`admin_id`),
  ADD KEY `idx_phase` (`phase`),
  ADD KEY `idx_module_key` (`module_key`);

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_admin_email` (`email`);

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
-- Indexes for table `communication_calls`
--
ALTER TABLE `communication_calls`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_receiver` (`receiver_type`,`receiver_id`),
  ADD KEY `idx_caller` (`caller_type`,`caller_id`),
  ADD KEY `idx_phase` (`phase`);

--
-- Indexes for table `complaints`
--
ALTER TABLE `complaints`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_complaints_homeowner` (`homeowner_id`),
  ADD KEY `idx_complaints_phase` (`phase`),
  ADD KEY `idx_complaints_admin` (`admin_id`);

--
-- Indexes for table `complaint_messages`
--
ALTER TABLE `complaint_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cm_complaint` (`complaint_id`),
  ADD KEY `idx_cm_homeowner` (`sender_homeowner_id`),
  ADD KEY `idx_cm_admin` (`sender_admin_id`);

--
-- Indexes for table `election_nominations`
--
ALTER TABLE `election_nominations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_election_position_homeowner` (`election_id`,`position`,`homeowner_id`),
  ADD KEY `idx_phase` (`phase`),
  ADD KEY `idx_homeowner` (`homeowner_id`),
  ADD KEY `fk_nom_admin` (`created_by_admin_id`),
  ADD KEY `idx_election_id` (`election_id`);

--
-- Indexes for table `election_sessions`
--
ALTER TABLE `election_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_phase_status` (`phase`,`status`),
  ADD KEY `fk_es_admin` (`created_by_admin_id`);

--
-- Indexes for table `election_votes`
--
ALTER TABLE `election_votes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_vote_per_nominee` (`election_id`,`voter_homeowner_id`,`position`,`nominee_homeowner_id`),
  ADD KEY `idx_election_position` (`election_id`,`position`),
  ADD KEY `idx_nominee` (`nominee_homeowner_id`),
  ADD KEY `fk_ev_voter` (`voter_homeowner_id`);

--
-- Indexes for table `facility_rental_pricing`
--
ALTER TABLE `facility_rental_pricing`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `phase` (`phase`);

--
-- Indexes for table `facility_rental_requests`
--
ALTER TABLE `facility_rental_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_phase_status` (`phase`,`status`),
  ADD KEY `idx_facility_time` (`facility`,`start_dt`,`end_dt`),
  ADD KEY `idx_homeowner` (`homeowner_id`),
  ADD KEY `idx_phase_facility` (`phase`,`facility`),
  ADD KEY `idx_approved_lookup` (`phase`,`facility`,`status`,`start_dt`,`end_dt`);

--
-- Indexes for table `finance_donations`
--
ALTER TABLE `finance_donations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_phase_date` (`phase`,`donation_date`),
  ADD KEY `fk_don_admin` (`created_by_admin_id`),
  ADD KEY `idx_finance_donations_homeowner` (`homeowner_id`);

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
-- Indexes for table `finance_report_snapshots`
--
ALTER TABLE `finance_report_snapshots`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_finance_snapshot_request` (`report_request_id`),
  ADD UNIQUE KEY `uniq_finance_snapshot_period` (`phase`,`report_year`,`report_month`);

--
-- Indexes for table `hoa_officers`
--
ALTER TABLE `hoa_officers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_phase_position_name` (`phase`,`position`,`officer_name`);

--
-- Indexes for table `homeowners`
--
ALTER TABLE `homeowners`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_homeowner_email` (`email`),
  ADD UNIQUE KEY `uniq_homeowners_public_id` (`public_id`);

--
-- Indexes for table `homeowner_calls`
--
ALTER TABLE `homeowner_calls`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_homeowner_calls_receiver` (`receiver_homeowner_id`,`status`,`started_at`),
  ADD KEY `idx_homeowner_calls_caller` (`caller_homeowner_id`,`status`,`started_at`),
  ADD KEY `idx_homeowner_calls_phase` (`phase`,`status`,`started_at`);

--
-- Indexes for table `homeowner_call_signals`
--
ALTER TABLE `homeowner_call_signals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_homeowner_call_signals_call` (`call_id`,`id`),
  ADD KEY `idx_homeowner_call_signals_sender` (`sender_homeowner_id`,`created_at`);

--
-- Indexes for table `homeowner_feed_state`
--
ALTER TABLE `homeowner_feed_state`
  ADD PRIMARY KEY (`homeowner_id`);

--
-- Indexes for table `homeowner_import_queue`
--
ALTER TABLE `homeowner_import_queue`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_import_queue_status` (`status`),
  ADD KEY `idx_import_queue_email` (`email`),
  ADD KEY `idx_import_queue_phase` (`phase`);

--
-- Indexes for table `homeowner_officer_messages`
--
ALTER TABLE `homeowner_officer_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_hom_phase_homeowner_admin` (`phase`,`homeowner_id`,`admin_id`,`created_at`),
  ADD KEY `idx_hom_admin` (`admin_id`),
  ADD KEY `idx_hom_homeowner` (`homeowner_id`);

--
-- Indexes for table `homeowner_positions`
--
ALTER TABLE `homeowner_positions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_homeowner_phase` (`homeowner_id`,`phase`),
  ADD KEY `fk_hp_admin` (`updated_by_admin_id`);

--
-- Indexes for table `homeowner_private_messages`
--
ALTER TABLE `homeowner_private_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_hpm_phase_pair_created` (`phase`,`sender_homeowner_id`,`receiver_homeowner_id`,`created_at`),
  ADD KEY `idx_hpm_receiver` (`receiver_homeowner_id`,`created_at`),
  ADD KEY `fk_hpm_sender` (`sender_homeowner_id`);

--
-- Indexes for table `household_members`
--
ALTER TABLE `household_members`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `login_security_appeals`
--
ALTER TABLE `login_security_appeals`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_login_security_appeal_token` (`token_hash`),
  ADD KEY `idx_login_security_appeal_state` (`security_state_id`),
  ADD KEY `idx_login_security_appeal_status` (`status`);

--
-- Indexes for table `login_security_state`
--
ALTER TABLE `login_security_state`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_login_security_account` (`account_type`,`account_id`),
  ADD KEY `idx_login_security_email` (`email`),
  ADD KEY `idx_login_security_phase` (`phase`),
  ADD KEY `idx_login_security_hard_locked` (`hard_locked`),
  ADD KEY `idx_login_security_cooldown` (`cooldown_until`);

--
-- Indexes for table `parking_paymongo_checkouts`
--
ALTER TABLE `parking_paymongo_checkouts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_parking_checkout_session` (`checkout_session_id`),
  ADD KEY `idx_parking_checkout_permit` (`permit_id`),
  ADD KEY `idx_parking_checkout_homeowner` (`homeowner_id`),
  ADD KEY `idx_parking_checkout_status` (`status`);

--
-- Indexes for table `parking_permits`
--
ALTER TABLE `parking_permits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_phase_status` (`phase`,`status`),
  ADD KEY `idx_plate` (`plate_no`),
  ADD KEY `idx_homeowner` (`homeowner_id`),
  ADD KEY `fk_pp_admin` (`approved_by_admin_id`),
  ADD KEY `idx_pp_phase_plate` (`phase`,`plate_no`);

--
-- Indexes for table `parking_violations`
--
ALTER TABLE `parking_violations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_phase_status` (`phase`,`status`),
  ADD KEY `idx_plate` (`plate_no`),
  ADD KEY `idx_permit` (`permit_id`),
  ADD KEY `idx_homeowner` (`homeowner_id`),
  ADD KEY `fk_pv_admin` (`resolved_by_admin_id`);

--
-- Indexes for table `public_chat_messages`
--
ALTER TABLE `public_chat_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pcm_phase_created` (`phase`,`created_at`),
  ADD KEY `idx_pcm_homeowner` (`homeowner_id`);

--
-- Indexes for table `public_chat_mutes`
--
ALTER TABLE `public_chat_mutes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_homeowner_phase_chatmute` (`homeowner_id`,`phase`),
  ADD KEY `idx_pcmute_phase` (`phase`),
  ADD KEY `idx_pcmute_admin` (`muted_by_admin_id`);

--
-- Indexes for table `staff_applications`
--
ALTER TABLE `staff_applications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_staff_phase_status` (`phase`,`status`),
  ADD KEY `idx_staff_homeowner` (`homeowner_id`),
  ADD KEY `idx_staff_applied_by` (`applied_by_admin_id`),
  ADD KEY `idx_staff_president` (`president_admin_id`);

--
-- Indexes for table `staff_members`
--
ALTER TABLE `staff_members`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_staff_application` (`application_id`),
  ADD KEY `idx_staff_members_phase` (`phase`),
  ADD KEY `idx_staff_members_homeowner` (`homeowner_id`),
  ADD KEY `idx_staff_members_approved_by` (`approved_by_admin_id`);

--
-- Indexes for table `tenants`
--
ALTER TABLE `tenants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_tenant_email` (`email`),
  ADD KEY `idx_tenant_homeowner` (`homeowner_id`),
  ADD KEY `idx_tenant_phase` (`phase`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `access_permissions`
--
ALTER TABLE `access_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6253;

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- AUTO_INCREMENT for table `announcements`
--
ALTER TABLE `announcements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `announcement_attachments`
--
ALTER TABLE `announcement_attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `announcement_comments`
--
ALTER TABLE `announcement_comments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `announcement_likes`
--
ALTER TABLE `announcement_likes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `announcement_recipients`
--
ALTER TABLE `announcement_recipients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `communication_calls`
--
ALTER TABLE `communication_calls`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `complaints`
--
ALTER TABLE `complaints`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `complaint_messages`
--
ALTER TABLE `complaint_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `election_nominations`
--
ALTER TABLE `election_nominations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `election_sessions`
--
ALTER TABLE `election_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `election_votes`
--
ALTER TABLE `election_votes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `facility_rental_pricing`
--
ALTER TABLE `facility_rental_pricing`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `facility_rental_requests`
--
ALTER TABLE `facility_rental_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `finance_donations`
--
ALTER TABLE `finance_donations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `finance_dues_settings`
--
ALTER TABLE `finance_dues_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `finance_expenses`
--
ALTER TABLE `finance_expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `finance_opening_balance`
--
ALTER TABLE `finance_opening_balance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `finance_payments`
--
ALTER TABLE `finance_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `finance_paymongo_checkouts`
--
ALTER TABLE `finance_paymongo_checkouts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `finance_report_requests`
--
ALTER TABLE `finance_report_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `finance_report_snapshots`
--
ALTER TABLE `finance_report_snapshots`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hoa_officers`
--
ALTER TABLE `hoa_officers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT for table `homeowners`
--
ALTER TABLE `homeowners`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=47;

--
-- AUTO_INCREMENT for table `homeowner_calls`
--
ALTER TABLE `homeowner_calls`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `homeowner_call_signals`
--
ALTER TABLE `homeowner_call_signals`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `homeowner_import_queue`
--
ALTER TABLE `homeowner_import_queue`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `homeowner_officer_messages`
--
ALTER TABLE `homeowner_officer_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `homeowner_positions`
--
ALTER TABLE `homeowner_positions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=46;

--
-- AUTO_INCREMENT for table `homeowner_private_messages`
--
ALTER TABLE `homeowner_private_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `household_members`
--
ALTER TABLE `household_members`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=47;

--
-- AUTO_INCREMENT for table `login_security_appeals`
--
ALTER TABLE `login_security_appeals`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `login_security_state`
--
ALTER TABLE `login_security_state`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `parking_paymongo_checkouts`
--
ALTER TABLE `parking_paymongo_checkouts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `parking_permits`
--
ALTER TABLE `parking_permits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `parking_violations`
--
ALTER TABLE `parking_violations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `public_chat_messages`
--
ALTER TABLE `public_chat_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `public_chat_mutes`
--
ALTER TABLE `public_chat_mutes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `staff_applications`
--
ALTER TABLE `staff_applications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `staff_members`
--
ALTER TABLE `staff_members`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tenants`
--
ALTER TABLE `tenants`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `access_permissions`
--
ALTER TABLE `access_permissions`
  ADD CONSTRAINT `fk_access_permissions_module` FOREIGN KEY (`module_key`) REFERENCES `access_modules` (`module_key`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `fk_activity_logs_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL;

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
-- Constraints for table `complaints`
--
ALTER TABLE `complaints`
  ADD CONSTRAINT `fk_complaints_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_complaints_homeowner` FOREIGN KEY (`homeowner_id`) REFERENCES `homeowners` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `complaint_messages`
--
ALTER TABLE `complaint_messages`
  ADD CONSTRAINT `fk_cm_admin` FOREIGN KEY (`sender_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_cm_complaint` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_cm_homeowner` FOREIGN KEY (`sender_homeowner_id`) REFERENCES `homeowners` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `election_nominations`
--
ALTER TABLE `election_nominations`
  ADD CONSTRAINT `fk_nom_admin` FOREIGN KEY (`created_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_nom_election` FOREIGN KEY (`election_id`) REFERENCES `election_sessions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_nom_homeowner` FOREIGN KEY (`homeowner_id`) REFERENCES `homeowners` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `election_sessions`
--
ALTER TABLE `election_sessions`
  ADD CONSTRAINT `fk_es_admin` FOREIGN KEY (`created_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `election_votes`
--
ALTER TABLE `election_votes`
  ADD CONSTRAINT `fk_ev_election` FOREIGN KEY (`election_id`) REFERENCES `election_sessions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ev_nominee` FOREIGN KEY (`nominee_homeowner_id`) REFERENCES `homeowners` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ev_voter` FOREIGN KEY (`voter_homeowner_id`) REFERENCES `homeowners` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `finance_donations`
--
ALTER TABLE `finance_donations`
  ADD CONSTRAINT `fk_don_admin` FOREIGN KEY (`created_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_finance_donations_homeowner` FOREIGN KEY (`homeowner_id`) REFERENCES `homeowners` (`id`) ON DELETE SET NULL;

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
-- Constraints for table `finance_report_snapshots`
--
ALTER TABLE `finance_report_snapshots`
  ADD CONSTRAINT `fk_finance_snapshot_request` FOREIGN KEY (`report_request_id`) REFERENCES `finance_report_requests` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `homeowner_feed_state`
--
ALTER TABLE `homeowner_feed_state`
  ADD CONSTRAINT `fk_feed_state_homeowner` FOREIGN KEY (`homeowner_id`) REFERENCES `homeowners` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `homeowner_officer_messages`
--
ALTER TABLE `homeowner_officer_messages`
  ADD CONSTRAINT `fk_hom_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_hom_homeowner` FOREIGN KEY (`homeowner_id`) REFERENCES `homeowners` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `homeowner_positions`
--
ALTER TABLE `homeowner_positions`
  ADD CONSTRAINT `fk_hp_admin` FOREIGN KEY (`updated_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_hp_homeowner` FOREIGN KEY (`homeowner_id`) REFERENCES `homeowners` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `homeowner_private_messages`
--
ALTER TABLE `homeowner_private_messages`
  ADD CONSTRAINT `fk_hpm_receiver` FOREIGN KEY (`receiver_homeowner_id`) REFERENCES `homeowners` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_hpm_sender` FOREIGN KEY (`sender_homeowner_id`) REFERENCES `homeowners` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `login_security_appeals`
--
ALTER TABLE `login_security_appeals`
  ADD CONSTRAINT `fk_login_security_appeal_state` FOREIGN KEY (`security_state_id`) REFERENCES `login_security_state` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `parking_paymongo_checkouts`
--
ALTER TABLE `parking_paymongo_checkouts`
  ADD CONSTRAINT `fk_parking_checkout_homeowner` FOREIGN KEY (`homeowner_id`) REFERENCES `homeowners` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_parking_checkout_permit` FOREIGN KEY (`permit_id`) REFERENCES `parking_permits` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `parking_permits`
--
ALTER TABLE `parking_permits`
  ADD CONSTRAINT `fk_pp_admin` FOREIGN KEY (`approved_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_pp_homeowner` FOREIGN KEY (`homeowner_id`) REFERENCES `homeowners` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `parking_violations`
--
ALTER TABLE `parking_violations`
  ADD CONSTRAINT `fk_pv_admin` FOREIGN KEY (`resolved_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_pv_homeowner` FOREIGN KEY (`homeowner_id`) REFERENCES `homeowners` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_pv_permit` FOREIGN KEY (`permit_id`) REFERENCES `parking_permits` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `public_chat_messages`
--
ALTER TABLE `public_chat_messages`
  ADD CONSTRAINT `fk_pcm_homeowner` FOREIGN KEY (`homeowner_id`) REFERENCES `homeowners` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `public_chat_mutes`
--
ALTER TABLE `public_chat_mutes`
  ADD CONSTRAINT `fk_pcmute_admin` FOREIGN KEY (`muted_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_pcmute_homeowner` FOREIGN KEY (`homeowner_id`) REFERENCES `homeowners` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `staff_applications`
--
ALTER TABLE `staff_applications`
  ADD CONSTRAINT `fk_staff_applied_by` FOREIGN KEY (`applied_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_staff_homeowner` FOREIGN KEY (`homeowner_id`) REFERENCES `homeowners` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_staff_president` FOREIGN KEY (`president_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `staff_members`
--
ALTER TABLE `staff_members`
  ADD CONSTRAINT `fk_staff_members_admin` FOREIGN KEY (`approved_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_staff_members_application` FOREIGN KEY (`application_id`) REFERENCES `staff_applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_staff_members_homeowner` FOREIGN KEY (`homeowner_id`) REFERENCES `homeowners` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `tenants`
--
ALTER TABLE `tenants`
  ADD CONSTRAINT `fk_tenant_homeowner` FOREIGN KEY (`homeowner_id`) REFERENCES `homeowners` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
