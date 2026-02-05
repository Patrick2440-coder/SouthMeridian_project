-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 05, 2026 at 01:17 PM
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
  `password` varchar(255) NOT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3','Superadmin') NOT NULL,
  `role` enum('admin','superadmin') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admins`
--

INSERT INTO `admins` (`id`, `email`, `password`, `phase`, `role`) VALUES
(1, 'superadmin@gmail.com', 'superadmin', 'Superadmin', 'superadmin'),
(2, 'admin1@gmail.com', 'admin1', 'Phase 1', 'admin'),
(3, 'admin2@gmail.com', 'admin2', 'Phase 2', 'admin'),
(4, 'admin3@gmail.com', 'admin3', 'Phase 3', 'admin');

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
(1, 'Phase 1', 'President', NULL, NULL, 1, '2026-02-05 10:22:56'),
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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
(35, 'Patrick Justin', '', 'Baculpo', '09916963390', 'baculpopatrick2440@gmail.com', '$2y$10$SLh0iXkpJQBWMgYyK/obROwZTT1TfYIqt.gt1YisN8id.fM9ulG6O', 0, 'Phase 1', 'blk 7 lot 9', 'uploads/1770288743_id_', 'uploads/1770288743_proof_', 14.3548655, 120.9460555, 'approved', 2, '2026-02-05 10:52:23', NULL, NULL);

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
(0, 35, 'Patrick Justin', '', 'Baculpo', 'Child');

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
  ADD KEY `idx_ann_dates` (`start_date`,`end_date`);

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
  ADD KEY `homeowner_id` (`homeowner_id`);

--
-- Indexes for table `hoa_post_comments`
--
ALTER TABLE `hoa_post_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `post_id` (`post_id`),
  ADD KEY `homeowner_id` (`homeowner_id`);

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
-- AUTO_INCREMENT for table `hoa_officers`
--
ALTER TABLE `hoa_officers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=229;

--
-- AUTO_INCREMENT for table `hoa_posts`
--
ALTER TABLE `hoa_posts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

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
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
