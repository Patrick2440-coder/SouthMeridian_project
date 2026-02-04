-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 26, 2026 at 06:45 PM
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

INSERT INTO `homeowners` (`id`, `first_name`, `middle_name`, `last_name`, `contact_number`, `email`, `password`, `phase`, `house_lot_number`, `valid_id_path`, `proof_of_billing_path`, `latitude`, `longitude`, `status`, `admin_id`, `created_at`, `reset_token`, `reset_expires`) VALUES
(5, 'Johnerre', 't', 'Enriquez', '09916963390', 'liamalexander2440@gmail.com', '$2y$10$Etfl2ezI1XoXqRpljo.1TuDJKvWxg4kUyZ6EQYPkGDewHcJNQIalm', 'Phase 1', 'blk 7 lot 8', 'uploads/1769272104_id_', 'uploads/1769272104_proof_', 14.3534456, 120.9467590, 'approved', 2, '2026-01-24 16:28:24', NULL, NULL),
(6, 'Lea', '', 'Guzman', '09916963390', 'wadasdaw@gmail.com', '$2y$10$sIbXIoCk1VNZGLjpSJJtPOqeYDMUXOWlnuwUxuSVzGtgFIPhvXv9a', 'Phase 1', 'blk 7 lot 8', 'uploads/1769272378_id_', 'uploads/1769272378_proof_', 14.3524114, 120.9467161, 'approved', 2, '2026-01-24 16:32:58', NULL, NULL),
(8, 'Patrick Justin', '', 'Baculpo', '09916963390', 'wdasdadw@gmail.com', '$2y$10$G.oQgF2F3o35OytSCAZ3keMjo2lz1ZJTcDUHegkxvtYKOy5u1XFJS', 'Phase 1', 'blk 7 lot 8', 'uploads/1769274749_id_', 'uploads/1769274749_proof_', 14.3523906, 120.9471881, 'rejected', 2, '2026-01-24 17:12:29', NULL, NULL),
(9, 'Patrick Justin', '', 'Baculpo', '09916963390', 'wdsdawdaw@gmail.com', '$2y$10$KOu351u8YxukIGECo376.uTASbxvEgdsqxFqDdzydC3Vj8tbz4c4i', 'Phase 1', 'blk 7 lot 9', 'uploads/1769274807_id_', 'uploads/1769274807_proof_', 14.3524426, 120.9461957, 'approved', 2, '2026-01-24 17:13:27', NULL, NULL),
(10, 'Patrick Justin', '', 'Baculpo', '09916963390', 'awdawsd@gmail.com', '$2y$10$XN2vR2w5FR5od5V6zNrQC.qPA5tTsDR1mfazllKyuqmjDP37W0Dey', 'Phase 1', 'blk 7 lot 9', 'uploads/1769434776_id_', 'uploads/1769434776_proof_', 14.3530922, 120.9458578, 'approved', 2, '2026-01-26 13:39:36', NULL, NULL),
(30, 'Patrick Justin', '', 'Baculpo', '09916963390', 'awdasdwad@gmail.com', '$2y$10$9kBD5B6q/Yk.vIh/M9ny3OM4wlg.UmWt0fgBEOjiLAlrcCzo/F3Gi', 'Phase 1', 'blk 7 lot 9', 'uploads/1769446162_id_', 'uploads/1769446162_proof_', 14.3548655, 120.9460555, 'rejected', 2, '2026-01-26 16:49:22', NULL, NULL),
(32, 'Patrick Justin', '', 'Baculpo', '09916963390', 'jheannaabigailerolesabella@gmail.com', '$2y$10$RhmUU7W7RqJVCG9MitqV4.SoD9h5x0Rd3QBEMZ3LjoaTf.OygcPfm', 'Phase 1', 'blk 7 lot 9', 'uploads/1769448105_id_', 'uploads/1769448105_proof_', 14.3523646, 120.9467858, 'approved', 2, '2026-01-26 17:21:45', 'b59013804cfc8fa73affdd513e7faa27a8b0f31df9f0163e4c6d6a1557f28e01', '2026-01-26 19:21:50'),
(33, 'Patrick Justin', '', 'Baculpo', '09916963390', 'baculpopatrick2440@gmail.com', '$2y$10$Jf5fnfDz5mgSIawijQrllOxNfPLHQblPpJbm0u53qET5dqLPWJs2K', 'Phase 1', 'blk 7 lot 9', 'uploads/1769449027_id_', 'uploads/1769449027_proof_', 14.3548655, 120.9460555, 'rejected', 2, '2026-01-26 17:37:07', NULL, NULL),
(34, 'Patrick Justin', '', 'Baculpo', '09916963390', 'awdasdadwa@gmail.com', '$2y$10$YjSgWHHmeNN47bM9MiW/K.JvNTLOSMFpPMXK5NiY5ZdCVV5wRaYb2', 'Phase 1', 'blk 7 lot 9', 'uploads/1769449179_id_', 'uploads/1769449179_proof_', 14.3548655, 120.9460555, 'pending', 2, '2026-01-26 17:39:39', NULL, NULL);

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
(30, 34, 'Patrick Justin', '', 'Baculpo', 'Homeowner');

--
-- Indexes for dumped tables
--

--
-- Table structure for table `hoa_officers`
--
CREATE TABLE IF NOT EXISTS hoa_officers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  phase ENUM('Phase 1','Phase 2','Phase 3') NOT NULL,
  position VARCHAR(50) NOT NULL,
  officer_name VARCHAR(255) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_phase_position (phase, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Table structure for table `announcements`
CREATE TABLE IF NOT EXISTS announcements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admin_id INT NULL,
  phase ENUM('Phase 1','Phase 2','Phase 3','Superadmin') NOT NULL DEFAULT 'Superadmin',
  title VARCHAR(255) NOT NULL,
  category ENUM('general','maintenance','meeting','emergency') NOT NULL,
  audience ENUM('all','block','selected','selected_officer','all_officers') NOT NULL,
  audience_value VARCHAR(255) NULL, -- e.g. block name
  message TEXT NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NULL,
  priority ENUM('normal','important','urgent') NOT NULL DEFAULT 'normal',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ann_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS announcement_recipients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  announcement_id INT NOT NULL,
  recipient_type ENUM('homeowner','officer') NOT NULL,
  homeowner_id INT NULL,
  officer_id INT NULL,
  recipient_name VARCHAR(255) NULL,
  recipient_email VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ar_announcement FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE CASCADE,
  CONSTRAINT fk_ar_homeowner FOREIGN KEY (homeowner_id) REFERENCES homeowners(id) ON DELETE SET NULL,
  CONSTRAINT fk_ar_officer FOREIGN KEY (officer_id) REFERENCES hoa_officers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Table structure for table `announcement_attachments`
--
CREATE TABLE IF NOT EXISTS announcement_attachments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  announcement_id INT NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(255) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  file_size INT NOT NULL,
  uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_announcement_id (announcement_id),
  CONSTRAINT fk_announcement_attachments
    FOREIGN KEY (announcement_id) REFERENCES announcements(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `homeowners`
--
ALTER TABLE `homeowners`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `email_2` (`email`),
  ADD KEY `admin_id` (`admin_id`);

--
-- Indexes for table `household_members`
--
ALTER TABLE `household_members`
  ADD PRIMARY KEY (`id`),
  ADD KEY `homeowner_id` (`homeowner_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `homeowners`
--
ALTER TABLE `homeowners`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- AUTO_INCREMENT for table `household_members`
--
ALTER TABLE `household_members`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `homeowners`
--
ALTER TABLE `homeowners`
  ADD CONSTRAINT `homeowners_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`);

--
-- Constraints for table `household_members`
--
ALTER TABLE `household_members`
  ADD CONSTRAINT `household_members_ibfk_1` FOREIGN KEY (`homeowner_id`) REFERENCES `homeowners` (`id`);

--
-- Add comlumn in table hoa_officers
--
ALTER TABLE hoa_officers
ADD COLUMN officer_email VARCHAR(255) NULL AFTER officer_name;
COMMIT;


/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
