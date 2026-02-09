-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 09, 2026 at 11:47 PM
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
(2, 'admin1@gmail.com', NULL, 'admin1', 'Phase 1', 'admin'),
(3, 'admin2@gmail.com', NULL, 'admin2', 'Phase 2', 'admin'),
(4, 'admin3@gmail.com', NULL, 'admin3', 'Phase 3', 'admin');

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
(2, 'Phase 1', 'Vice President', NULL, NULL, 1, '2026-02-05 10:21:54'),
(3, 'Phase 1', 'Secretary', NULL, NULL, 1, '2026-02-05 10:21:54'),
(4, 'Phase 1', 'Treasurer', NULL, NULL, 1, '2026-02-05 10:21:54'),
(5, 'Phase 1', 'Auditor', NULL, NULL, 1, '2026-02-05 10:21:54'),
(6, 'Phase 1', 'Board of Director', NULL, NULL, 1, '2026-02-05 10:21:54'),
(13, 'Phase 2', 'President', NULL, NULL, 1, '2026-02-05 10:22:35'),
(14, 'Phase 2', 'Vice President', NULL, NULL, 1, '2026-02-05 10:22:35'),
(15, 'Phase 2', 'Secretary', NULL, NULL, 1, '2026-02-05 10:22:35'),
(16, 'Phase 2', 'Treasurer', NULL, NULL, 1, '2026-02-05 10:22:35'),
(17, 'Phase 2', 'Auditor', NULL, NULL, 1, '2026-02-05 10:22:35'),
(18, 'Phase 2', 'Board of Director', NULL, NULL, 1, '2026-02-05 10:22:35');

-- --------------------------------------------------------

--
-- Table structure for table `hoa_posts`
--

CREATE TABLE `hoa_posts` (
  `id` int(11) NOT NULL,
  `homeowner_id` int(11) NOT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3') NOT NULL,
  `content` text NOT NULL,
  `shared_post_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `hoa_posts`
--

INSERT INTO `hoa_posts` (`id`, `homeowner_id`, `phase`, `content`, `shared_post_id`, `created_at`) VALUES
(1, 35, 'Phase 1', 'awdaskdnwa', NULL, '2026-02-08 07:58:36');

-- --------------------------------------------------------

--
-- Table structure for table `hoa_post_comments`
--

CREATE TABLE `hoa_post_comments` (
  `id` int(11) NOT NULL,
  `post_id` int(11) NOT NULL,
  `homeowner_id` int(11) NOT NULL,
  `comment` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `hoa_post_likes`
--

CREATE TABLE `hoa_post_likes` (
  `id` int(11) NOT NULL,
  `post_id` int(11) NOT NULL,
  `homeowner_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `hoa_post_shares`
--

CREATE TABLE `hoa_post_shares` (
  `id` int(11) NOT NULL,
  `post_id` int(11) NOT NULL,
  `homeowner_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `homeowners`
--

CREATE TABLE `homeowners` (
  `id` int(11) NOT NULL,
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

INSERT INTO `homeowners` (`id`, `first_name`, `middle_name`, `last_name`, `contact_number`, `email`, `password`, `must_change_password`, `phase`, `house_lot_number`, `valid_id_path`, `proof_of_billing_path`, `latitude`, `longitude`, `status`, `admin_id`, `created_at`, `reset_token`, `reset_expires`) VALUES
(5, 'Johnerre', 't', 'Enriquez', '09916963390', 'liamalexander2440@gmail.com', '$2y$10$Etfl2ezI1XoXqRpljo.1TuDJKvWxg4kUyZ6EQYPkGDewHcJNQIalm', 1, 'Phase 1', 'blk 7 lot 8', 'uploads/1769272104_id_', 'uploads/1769272104_proof_', 14.3534456, 120.9467590, 'approved', 2, '2026-01-24 16:28:24', NULL, NULL),
(6, 'Lea', '', 'Guzman', '09916963390', 'wadasdaw@gmail.com', '$2y$10$sIbXIoCk1VNZGLjpSJJtPOqeYDMUXOWlnuwUxuSVzGtgFIPhvXv9a', 1, 'Phase 1', 'blk 7 lot 8', 'uploads/1769272378_id_', 'uploads/1769272378_proof_', 14.3524114, 120.9467161, 'approved', 2, '2026-01-24 16:32:58', NULL, NULL),
(8, 'Patrick Justin', '', 'Baculpo', '09916963390', 'wdasdadw@gmail.com', '$2y$10$G.oQgF2F3o35OytSCAZ3keMjo2lz1ZJTcDUHegkxvtYKOy5u1XFJS', 1, 'Phase 1', 'blk 7 lot 8', 'uploads/1769274749_id_', 'uploads/1769274749_proof_', 14.3523906, 120.9471881, 'rejected', 2, '2026-01-24 17:12:29', NULL, NULL),
(9, 'Patrick Justin', '', 'Baculpo', '09916963390', 'wdsdawdaw@gmail.com', '$2y$10$KOu351u8YxukIGECo376.uTASbxvEgdsqxFqDdzydC3Vj8tbz4c4i', 1, 'Phase 1', 'blk 7 lot 9', 'uploads/1769274807_id_', 'uploads/1769274807_proof_', 14.3524426, 120.9461957, 'approved', 2, '2026-01-24 17:13:27', NULL, NULL),
(10, 'Patrick Justin', '', 'Baculpo', '09916963390', 'awdawsd@gmail.com', '$2y$10$XN2vR2w5FR5od5V6zNrQC.qPA5tTsDR1mfazllKyuqmjDP37W0Dey', 1, 'Phase 1', 'blk 7 lot 9', 'uploads/1769434776_id_', 'uploads/1769434776_proof_', 14.3530922, 120.9458578, 'approved', 2, '2026-01-26 13:39:36', NULL, NULL),
(30, 'Patrick Justin', '', 'Baculpo', '09916963390', 'awdasdwad@gmail.com', '$2y$10$9kBD5B6q/Yk.vIh/M9ny3OM4wlg.UmWt0fgBEOjiLAlrcCzo/F3Gi', 1, 'Phase 1', 'blk 7 lot 9', 'uploads/1769446162_id_', 'uploads/1769446162_proof_', 14.3548655, 120.9460555, 'rejected', 2, '2026-01-26 16:49:22', NULL, NULL),
(32, 'Patrick Justin', '', 'Baculpo', '09916963390', 'jheannaabigailerolesabella@gmail.com', '$2y$10$RhmUU7W7RqJVCG9MitqV4.SoD9h5x0Rd3QBEMZ3LjoaTf.OygcPfm', 1, 'Phase 1', 'blk 7 lot 9', 'uploads/1769448105_id_', 'uploads/1769448105_proof_', 14.3523646, 120.9467858, 'approved', 2, '2026-01-26 17:21:45', 'b59013804cfc8fa73affdd513e7faa27a8b0f31df9f0163e4c6d6a1557f28e01', '2026-01-26 19:21:50'),
(34, 'Patrick Justin', '', 'Baculpo', '09916963390', 'awdasdadwa@gmail.com', '$2y$10$YjSgWHHmeNN47bM9MiW/K.JvNTLOSMFpPMXK5NiY5ZdCVV5wRaYb2', 1, 'Phase 1', 'blk 7 lot 9', 'uploads/1769449179_id_', 'uploads/1769449179_proof_', 14.3548655, 120.9460555, 'approved', 2, '2026-01-26 17:39:39', '7667cea63a3edb9e1d6a577783a9a27bf0691f17256ee42896fade541ed0a099', '2026-02-05 12:47:19'),
(35, 'Patrick Justin', '', 'Baculpo', '09916963390', 'baculpopatrick2440@gmail.com', '$2y$10$SLh0iXkpJQBWMgYyK/obROwZTT1TfYIqt.gt1YisN8id.fM9ulG6O', 0, 'Phase 1', 'blk 7 lot 9', 'uploads/1770288743_id_', 'uploads/1770288743_proof_', 14.3548655, 120.9460555, 'approved', 2, '2026-02-05 10:52:23', NULL, NULL),
(36, 'Patrick Justin', '', 'Baculpo', '09916963390', 'dawdad@gmail.com', '$2y$10$AH3VAR8IHBlYhblcZtz.JOeVbGXj5yBYdpoGbyPzPHRB1LqykRrJW', 1, 'Phase 1', 'blk 7 lot 9', 'uploads/1770670503_id_images (1).png', 'uploads/1770670503_proof_images (2).png', 14.3546149, 120.9466088, 'pending', 2, '2026-02-09 20:55:12', NULL, NULL);

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
(0, 35, 'Patrick Justin', '', 'Baculpo', 'Child'),
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
-- Indexes for table `announcement_recipients`
--
ALTER TABLE `announcement_recipients`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ar_announcement_id` (`announcement_id`),
  ADD KEY `idx_ar_homeowner_id` (`homeowner_id`),
  ADD KEY `idx_ar_officer_id` (`officer_id`),
  ADD KEY `idx_ar_type` (`recipient_type`);

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
-- Indexes for table `hoa_posts`
--
ALTER TABLE `hoa_posts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `phase` (`phase`),
  ADD KEY `homeowner_id` (`homeowner_id`),
  ADD KEY `idx_shared_post_id` (`shared_post_id`);

--
-- Indexes for table `hoa_post_comments`
--
ALTER TABLE `hoa_post_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `post_id` (`post_id`),
  ADD KEY `homeowner_id` (`homeowner_id`),
  ADD KEY `idx_comments_created_at` (`created_at`);

--
-- Indexes for table `hoa_post_likes`
--
ALTER TABLE `hoa_post_likes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_like` (`post_id`,`homeowner_id`),
  ADD KEY `post_id` (`post_id`),
  ADD KEY `homeowner_id` (`homeowner_id`);

--
-- Indexes for table `hoa_post_shares`
--
ALTER TABLE `hoa_post_shares`
  ADD PRIMARY KEY (`id`),
  ADD KEY `post_id` (`post_id`),
  ADD KEY `homeowner_id` (`homeowner_id`);

--
-- Indexes for table `homeowners`
--
ALTER TABLE `homeowners`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_homeowner_email` (`email`);

--
-- Indexes for table `homeowner_feed_state`
--
ALTER TABLE `homeowner_feed_state`
  ADD PRIMARY KEY (`homeowner_id`);

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `announcement_attachments`
--
ALTER TABLE `announcement_attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `announcement_recipients`
--
ALTER TABLE `announcement_recipients`
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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `finance_expenses`
--
ALTER TABLE `finance_expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `finance_opening_balance`
--
ALTER TABLE `finance_opening_balance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `finance_payments`
--
ALTER TABLE `finance_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `finance_report_requests`
--
ALTER TABLE `finance_report_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `hoa_officers`
--
ALTER TABLE `hoa_officers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=338;

--
-- AUTO_INCREMENT for table `hoa_posts`
--
ALTER TABLE `hoa_posts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `hoa_post_comments`
--
ALTER TABLE `hoa_post_comments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hoa_post_likes`
--
ALTER TABLE `hoa_post_likes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hoa_post_shares`
--
ALTER TABLE `hoa_post_shares`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `homeowners`
--
ALTER TABLE `homeowners`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

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
-- Constraints for table `announcement_recipients`
--
ALTER TABLE `announcement_recipients`
  ADD CONSTRAINT `fk_ar_announcement` FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ar_homeowner` FOREIGN KEY (`homeowner_id`) REFERENCES `homeowners` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_ar_officer` FOREIGN KEY (`officer_id`) REFERENCES `hoa_officers` (`id`) ON DELETE SET NULL;

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
-- Constraints for table `finance_report_requests`
--
ALTER TABLE `finance_report_requests`
  ADD CONSTRAINT `fk_rep_admin` FOREIGN KEY (`requested_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `hoa_posts`
--
ALTER TABLE `hoa_posts`
  ADD CONSTRAINT `fk_posts_shared_post` FOREIGN KEY (`shared_post_id`) REFERENCES `hoa_posts` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `homeowner_feed_state`
--
ALTER TABLE `homeowner_feed_state`
  ADD CONSTRAINT `fk_feed_state_homeowner` FOREIGN KEY (`homeowner_id`) REFERENCES `homeowners` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
