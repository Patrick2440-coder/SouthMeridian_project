-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 05, 2026 at 12:01 PM
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
(1, 'superadmin@gmail.com', NULL, 'superadmin', 'Superadmin', 'superadmin', 'Superadmin'),
(5, 'arvin.delgado.p3@hoa.local', 'Arvin S. Delgado', '12345678', 'Phase 3', 'admin', 'Treasurer'),
(6, 'baculpopatrick2440@gmail.com', 'Jheanna Abella', '12345678', 'Phase 1', 'admin', 'Vice President'),
(7, 'catherine.yu.p2@hoa.local', 'Catherine D. Yu', '12345678', 'Phase 2', 'admin', 'Auditor'),
(8, 'elaine.mendoza.p2@hoa.local', 'Elaine P. Mendoza', '12345678', 'Phase 2', 'admin', 'President'),
(9, 'jheannaabigailerolesabella@gmail.com', 'Jheanna Abella', '12345678', 'Phase 1', 'admin', 'President'),
(10, 'john.villanueva.p3@hoa.local', 'John Paul Villanueva', '12345678', 'Phase 3', 'admin', 'Secretary'),
(11, 'joshua.lim.p2@hoa.local', 'Joshua R. Lim', '12345678', 'Phase 2', 'admin', 'Vice President'),
(12, 'lourdes.castillo.p3@hoa.local', 'Ma. Lourdes Castillo', '12345678', 'Phase 3', 'admin', 'Auditor'),
(13, 'miguel.dizon.p3@hoa.local', 'Miguel A. Dizon', '12345678', 'Phase 3', 'admin', 'President'),
(14, 'noel.ramos.p2@hoa.local', 'Noel T. Ramos', '12345678', 'Phase 2', 'admin', 'Board of Director'),
(15, 'p1_anne.reyes@hoa.local', 'Anne Reyes', '12345678', 'Phase 1', 'admin', 'Board of Director'),
(16, 'p1_jenny.garcia@hoa.local', 'Jenny Garcia', '12345678', 'Phase 1', 'admin', 'Board of Director'),
(17, 'p1_john.cruz@hoa.local', 'John Cruz', '12345678', 'Phase 1', 'admin', 'Secretary'),
(18, 'p1_liza.domingo@hoa.local', 'Liza Domingo', '12345678', 'Phase 1', 'admin', 'Board of Director'),
(19, 'p1_mark.santos@hoa.local', 'Mark Santos', '12345678', 'Phase 1', 'admin', 'Board of Director'),
(20, 'p1_mika.delacruz@hoa.local', 'Mika Dela Cruz', '12345678', 'Phase 1', 'admin', 'Treasurer'),
(21, 'p1_paolo.flores@hoa.local', 'Paolo Flores', '12345678', 'Phase 1', 'admin', 'Board of Director'),
(22, 'paolo.bautista.p2@hoa.local', 'Paolo V. Bautista', '12345678', 'Phase 2', 'admin', 'Treasurer'),
(23, 'shiela.tan.p3@hoa.local', 'Shiela Marie Tan', '12345678', 'Phase 3', 'admin', 'Vice President'),
(24, 'stephanie.ong.p3@hoa.local', 'Stephanie R. Ong', '12345678', 'Phase 3', 'admin', 'Board of Director'),
(25, 'trisha.flores.p2@hoa.local', 'Trisha Anne Flores', '12345678', 'Phase 2', 'admin', 'Secretary');

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
(9, NULL, 'Phase 1', 'atingitng', 'general', 'all', NULL, 'awadwasd', '2026-02-14', '2026-02-21', 'normal', '2026-02-13 21:33:45'),
(10, NULL, 'Phase 1', 'urgent', 'maintenance', 'selected', NULL, 'This announcementad.wad', '2026-02-18', '2026-02-18', 'normal', '2026-02-18 08:37:48'),
(11, NULL, 'Phase 1', 'urgent', 'general', 'selected', NULL, 'awasdawdadawdadada', '2026-02-18', '2026-02-18', 'normal', '2026-02-18 08:40:24'),
(12, NULL, 'Phase 1', 'urgent', 'general', 'selected', NULL, 'awasdawdadawdadada', '2026-02-18', '2026-02-18', 'normal', '2026-02-18 08:42:42'),
(13, NULL, 'Phase 1', 'urgent', 'general', 'selected', NULL, 'awasdawdadawdadada', '2026-02-18', '2026-02-18', 'normal', '2026-02-18 08:45:16'),
(15, 1, 'Superadmin', 'clean up drive', 'maintenance', 'all', NULL, 'all required', '2026-03-11', NULL, 'important', '2026-03-11 14:22:47'),
(16, 9, 'Phase 1', 'Clean Up-Drive', 'general', 'all', NULL, 'be on time', '2026-03-31', '2026-03-31', 'important', '2026-03-30 07:33:52'),
(17, 9, 'Phase 1', 'Clean Up-Drive', 'general', 'all', NULL, 'be on time', '2026-03-31', '2026-03-31', 'important', '2026-03-30 07:45:48'),
(18, 1, 'Superadmin', 'dawdsa', 'maintenance', 'all', NULL, 'dawdsawdawd', '2026-03-02', '2026-03-12', 'important', '2026-03-31 07:56:29');

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

--
-- Dumping data for table `announcement_attachments`
--

INSERT INTO `announcement_attachments` (`id`, `announcement_id`, `original_name`, `stored_name`, `file_path`, `mime_type`, `file_size`, `uploaded_at`) VALUES
(1, 16, 'Usermanagement.drawio.png', '1774856032_7d9cb9c8cc57_Usermanagement.drawio.png', 'uploads/announcements/1774856032_7d9cb9c8cc57_Usermanagement.drawio.png', 'image/png', 196150, '2026-03-30 07:33:52'),
(2, 17, 'Usermanagement.drawio.png', '1774856748_77b9efdafaae_Usermanagement.drawio.png', 'uploads/announcements/1774856748_77b9efdafaae_Usermanagement.drawio.png', 'image/png', 196150, '2026-03-30 07:45:48');

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
(11, 15, 120, 'okayy', '2026-03-30 10:30:46');

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

--
-- Dumping data for table `announcement_recipients`
--

INSERT INTO `announcement_recipients` (`id`, `announcement_id`, `recipient_type`, `homeowner_id`, `officer_id`, `recipient_name`, `recipient_email`, `created_at`) VALUES
(89, 9, 'homeowner', NULL, NULL, 'Patrick Justin  Baculpo', 'baculpopatrick2440@gmail.com', '2026-02-13 21:33:45'),
(90, 9, 'homeowner', 37, NULL, 'Mark A Santos', 'p1_mark.santos@hoa.local', '2026-02-13 21:33:45'),
(91, 9, 'homeowner', 38, NULL, 'Anne M Reyes', 'p1_anne.reyes@hoa.local', '2026-02-13 21:33:45'),
(92, 9, 'homeowner', 39, NULL, 'John D Cruz', 'p1_john.cruz@hoa.local', '2026-02-13 21:33:45'),
(93, 9, 'homeowner', 40, NULL, 'Jenny L Garcia', 'p1_jenny.garcia@hoa.local', '2026-02-13 21:33:45'),
(94, 9, 'homeowner', 41, NULL, 'Paolo R Flores', 'p1_paolo.flores@hoa.local', '2026-02-13 21:33:45'),
(95, 9, 'homeowner', 42, NULL, 'Liza C Domingo', 'p1_liza.domingo@hoa.local', '2026-02-13 21:33:45'),
(96, 9, 'homeowner', 43, NULL, 'Ryan P Navarro', 'p1_ryan.navarro@hoa.local', '2026-02-13 21:33:45'),
(97, 9, 'homeowner', 44, NULL, 'Mika S Dela Cruz', 'p1_mika.delacruz@hoa.local', '2026-02-13 21:33:45'),
(98, 9, 'homeowner', 45, NULL, 'Carlo B Lim', 'p1_carlo.lim@hoa.local', '2026-02-13 21:33:45'),
(99, 9, 'homeowner', 46, NULL, 'Grace T Salazar', 'p1_grace.salazar@hoa.local', '2026-02-13 21:33:45'),
(100, 10, 'homeowner', NULL, NULL, 'Patrick Justin  Baculpo', 'baculpopatrick2440@gmail.com', '2026-02-18 08:37:48'),
(101, 11, 'homeowner', NULL, NULL, 'Patrick Justin  Baculpo', 'baculpopatrick2440@gmail.com', '2026-02-18 08:40:24'),
(102, 12, 'homeowner', NULL, NULL, 'Patrick Justin  Baculpo', 'baculpopatrick2440@gmail.com', '2026-02-18 08:42:42'),
(103, 13, 'homeowner', NULL, NULL, 'Patrick Justin  Baculpo', 'baculpopatrick2440@gmail.com', '2026-02-18 08:45:16'),
(117, 16, 'homeowner', 37, NULL, 'Mark A Santos', 'p1_mark.santos@hoa.local', '2026-03-30 07:33:52'),
(118, 16, 'homeowner', 38, NULL, 'Anne M Reyes', 'p1_anne.reyes@hoa.local', '2026-03-30 07:33:52'),
(119, 16, 'homeowner', 39, NULL, 'John D Cruz', 'p1_john.cruz@hoa.local', '2026-03-30 07:33:52'),
(120, 16, 'homeowner', 40, NULL, 'Jenny L Garcia', 'p1_jenny.garcia@hoa.local', '2026-03-30 07:33:52'),
(121, 16, 'homeowner', 41, NULL, 'Paolo R Flores', 'p1_paolo.flores@hoa.local', '2026-03-30 07:33:52'),
(122, 16, 'homeowner', 42, NULL, 'Liza C Domingo', 'p1_liza.domingo@hoa.local', '2026-03-30 07:33:52'),
(123, 16, 'homeowner', 43, NULL, 'Ryan P Navarro', 'p1_ryan.navarro@hoa.local', '2026-03-30 07:33:52'),
(124, 16, 'homeowner', 44, NULL, 'Mika S Dela Cruz', 'p1_mika.delacruz@hoa.local', '2026-03-30 07:33:52'),
(125, 16, 'homeowner', 45, NULL, 'Carlo B Lim', 'p1_carlo.lim@hoa.local', '2026-03-30 07:33:52'),
(126, 16, 'homeowner', 46, NULL, 'Grace T Salazar', 'p1_grace.salazar@hoa.local', '2026-03-30 07:33:52'),
(127, 16, 'homeowner', 88, NULL, 'Jheanna Abigail Abella', 'jheannaabigailerolesabella@gmail.com', '2026-03-30 07:33:52'),
(128, 16, 'homeowner', NULL, NULL, 'Jay Andrew  baculpo', 'liamalexander2440@gmail.com', '2026-03-30 07:33:52'),
(129, 16, 'homeowner', 113, NULL, 'patrick  baculpo', 'ljbaculpo2440@gmail.com', '2026-03-30 07:33:52'),
(130, 16, 'homeowner', 115, NULL, 'mark dexter  legacion', 'chann7721@gmail.com', '2026-03-30 07:33:52'),
(131, 16, 'homeowner', 116, NULL, 'dawdsasddwad awdsa dawdsa', 'jayjay@gmail.com', '2026-03-30 07:33:52'),
(132, 16, 'homeowner', 117, NULL, 'dsawd dsawd dsawddsaw', 'jayandrew@gmail.com', '2026-03-30 07:33:52'),
(133, 16, 'homeowner', 118, NULL, 'Jay Andrew Patani', 'jayyjayy@gmail.com', '2026-03-30 07:33:52'),
(134, 16, 'homeowner', 119, NULL, 'Jay Andrew Patani', 'jayandrewpatani18@gmail.co', '2026-03-30 07:33:52'),
(135, 16, 'homeowner', 120, NULL, 'Jay Andrew Patani', 'jayandrewpatani18@gmail.com', '2026-03-30 07:33:52'),
(136, 17, 'homeowner', 37, NULL, 'Mark A Santos', 'p1_mark.santos@hoa.local', '2026-03-30 07:45:48'),
(137, 17, 'homeowner', 38, NULL, 'Anne M Reyes', 'p1_anne.reyes@hoa.local', '2026-03-30 07:45:48'),
(138, 17, 'homeowner', 39, NULL, 'John D Cruz', 'p1_john.cruz@hoa.local', '2026-03-30 07:45:48'),
(139, 17, 'homeowner', 40, NULL, 'Jenny L Garcia', 'p1_jenny.garcia@hoa.local', '2026-03-30 07:45:48'),
(140, 17, 'homeowner', 41, NULL, 'Paolo R Flores', 'p1_paolo.flores@hoa.local', '2026-03-30 07:45:48'),
(141, 17, 'homeowner', 42, NULL, 'Liza C Domingo', 'p1_liza.domingo@hoa.local', '2026-03-30 07:45:48'),
(142, 17, 'homeowner', 43, NULL, 'Ryan P Navarro', 'p1_ryan.navarro@hoa.local', '2026-03-30 07:45:48'),
(143, 17, 'homeowner', 44, NULL, 'Mika S Dela Cruz', 'p1_mika.delacruz@hoa.local', '2026-03-30 07:45:48'),
(144, 17, 'homeowner', 45, NULL, 'Carlo B Lim', 'p1_carlo.lim@hoa.local', '2026-03-30 07:45:48'),
(145, 17, 'homeowner', 46, NULL, 'Grace T Salazar', 'p1_grace.salazar@hoa.local', '2026-03-30 07:45:48'),
(146, 17, 'homeowner', 88, NULL, 'Jheanna Abigail Abella', 'jheannaabigailerolesabella@gmail.com', '2026-03-30 07:45:48'),
(147, 17, 'homeowner', NULL, NULL, 'Jay Andrew  baculpo', 'liamalexander2440@gmail.com', '2026-03-30 07:45:48'),
(148, 17, 'homeowner', 113, NULL, 'patrick  baculpo', 'ljbaculpo2440@gmail.com', '2026-03-30 07:45:48'),
(149, 17, 'homeowner', 115, NULL, 'mark dexter  legacion', 'chann7721@gmail.com', '2026-03-30 07:45:48'),
(150, 17, 'homeowner', 116, NULL, 'dawdsasddwad awdsa dawdsa', 'jayjay@gmail.com', '2026-03-30 07:45:48'),
(151, 17, 'homeowner', 117, NULL, 'dsawd dsawd dsawddsaw', 'jayandrew@gmail.com', '2026-03-30 07:45:48'),
(152, 17, 'homeowner', 118, NULL, 'Jay Andrew Patani', 'jayyjayy@gmail.com', '2026-03-30 07:45:48'),
(153, 17, 'homeowner', 119, NULL, 'Jay Andrew Patani', 'jayandrewpatani18@gmail.co', '2026-03-30 07:45:48'),
(154, 17, 'homeowner', 120, NULL, 'Jay Andrew Patani', 'jayandrewpatani18@gmail.com', '2026-03-30 07:45:48');

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

--
-- Dumping data for table `complaints`
--

INSERT INTO `complaints` (`id`, `homeowner_id`, `phase`, `admin_id`, `subject`, `category`, `description`, `status`, `priority`, `created_at`, `updated_at`) VALUES
(8, 113, 'Phase 1', 9, 'Aso', 'general', 'ang ingay ng aso', 'closed', 'high', '2026-03-28 05:47:05', '2026-03-30 08:23:37'),
(9, 114, 'Phase 2', 8, 'Tae', 'security', 'Si sia natae', 'in_progress', 'normal', '2026-03-28 08:44:19', '2026-03-28 08:49:12'),
(10, 120, 'Phase 1', 6, 'tae', 'other', 'dsawdsawdsawdsawdsawdsawdwafdgfg', 'open', 'high', '2026-03-30 16:39:35', '2026-03-30 16:39:35');

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

--
-- Dumping data for table `complaint_messages`
--

INSERT INTO `complaint_messages` (`id`, `complaint_id`, `sender_type`, `sender_homeowner_id`, `sender_admin_id`, `message`, `created_at`) VALUES
(18, 8, 'homeowner', 113, NULL, 'ang ingay ng aso', '2026-03-28 05:47:05'),
(19, 9, 'homeowner', 114, NULL, 'Si sia natae', '2026-03-28 08:44:19'),
(20, 9, 'admin', NULL, 25, 'Complaint status updated to RESOLVED.', '2026-03-28 08:47:13'),
(21, 9, 'admin', NULL, 8, 'hi', '2026-03-28 08:49:12'),
(22, 8, 'admin', NULL, 9, 'noted ill check that', '2026-03-30 07:56:24'),
(23, 8, 'admin', NULL, 9, 'Complaint status updated to OPEN.', '2026-03-30 08:04:32'),
(24, 8, 'admin', NULL, 9, 'Complaint status updated to OPEN.', '2026-03-30 08:09:54'),
(25, 8, 'admin', NULL, 9, 'Complaint status updated to CLOSED.', '2026-03-30 08:23:37'),
(26, 10, 'homeowner', 120, NULL, 'dsawdsawdsawdsawdsawdsawdwafdgfg', '2026-03-30 16:39:35');

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

--
-- Dumping data for table `election_nominations`
--

INSERT INTO `election_nominations` (`id`, `election_id`, `phase`, `position`, `homeowner_id`, `created_by_admin_id`, `created_at`) VALUES
(2, 1, 'Phase 1', 'President', 88, 1, '2026-03-11 16:16:40'),
(4, 1, 'Phase 1', 'Secretary', 39, 1, '2026-03-11 16:17:05'),
(5, 1, 'Phase 1', 'Treasurer', 44, 1, '2026-03-11 16:17:20'),
(6, 1, 'Phase 1', 'Board of Director', 43, 1, '2026-03-11 16:17:34'),
(7, 1, 'Phase 1', 'Board of Director', 37, 1, '2026-03-11 16:42:33'),
(8, 1, 'Phase 1', 'Board of Director', 38, 1, '2026-03-11 16:42:33'),
(9, 1, 'Phase 1', 'Board of Director', 39, 1, '2026-03-11 16:42:33'),
(10, 1, 'Phase 1', 'Board of Director', 40, 1, '2026-03-11 16:42:33'),
(11, 1, 'Phase 1', 'Board of Director', 41, 1, '2026-03-11 16:42:33'),
(12, 1, 'Phase 1', 'Board of Director', 42, 1, '2026-03-11 16:42:33');

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

--
-- Dumping data for table `election_sessions`
--

INSERT INTO `election_sessions` (`id`, `phase`, `title`, `status`, `started_at`, `ended_at`, `created_by_admin_id`, `created_at`) VALUES
(1, 'Phase 1', 'Phase 1 HOA Election 2026', 'finished', '2026-03-11 16:17:45', '2026-03-11 16:18:17', 1, '2026-03-11 16:17:45'),
(3, 'Phase 1', 'awdadw', 'draft', NULL, NULL, 1, '2026-03-28 06:16:24');

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

--
-- Dumping data for table `facility_rental_requests`
--

INSERT INTO `facility_rental_requests` (`id`, `phase`, `homeowner_id`, `facility`, `start_dt`, `end_dt`, `purpose`, `notes`, `guest_count`, `amount`, `status`, `admin_id`, `admin_remarks`, `created_at`, `updated_at`) VALUES
(1, 'Phase 1', 80, 'court', '2026-03-06 02:17:00', '2026-03-06 06:17:00', 'awdadsd', '', NULL, 0.00, 'approved', 2, '', '2026-03-05 18:17:24', '2026-03-05 18:18:06'),
(2, 'Phase 1', 80, 'tables_chairs', '2026-03-07 10:37:00', '2026-03-07 15:40:00', 'BIRTHDAY', 'PUNTA PO KAYO', NULL, 0.00, 'approved', 2, '', '2026-03-06 13:38:10', '2026-03-12 05:46:46'),
(3, 'Phase 1', 113, 'clubhouse', '2026-03-28 14:50:00', '2026-04-04 17:49:00', 'bday', '', 30, 0.00, 'approved', 9, '', '2026-03-28 05:46:22', '2026-03-30 10:01:48'),
(4, 'Phase 2', 114, 'court', '2026-03-28 17:00:00', '2026-03-28 19:00:00', 'Zumba', '', NULL, 0.00, 'pending', NULL, NULL, '2026-03-28 08:43:47', NULL);

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
(1, 'Phase 1', 'Patrick Justin Baculpo', 'baculpopatrick2440@gmail.com', 2500.00, '2026-02-17', '12313123', '', NULL, '2026-02-17 11:03:25');

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
(1, 'Phase 1', 200.00, 9, '2026-03-30 08:34:43');

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
(1, 'Phase 1', 'maintenance', 'ilaw', 1000.00, '2026-02-17', NULL, NULL, '2026-02-17 11:06:47'),
(2, 'Phase 1', 'security', 'ilaw', 200.00, '2026-03-28', 'uploads/finance/receipts/1774677112_dd6eddac2059.png', 9, '2026-03-28 05:51:52');

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
(3, 39, 'Phase 1', 2026, 2, 200.00, 'paid', '2026-02-17 18:57:10', '', 'cash', NULL, '2026-02-17 10:57:10'),
(9, 113, 'Phase 1', 2026, 1, 200.00, 'paid', '2026-03-28 05:44:16', 'pay_4ouRuy2AKjD3oUYGonDyfnMS', 'PayMongo (fallback sync)', NULL, '2026-03-28 05:44:16'),
(10, 115, 'Phase 1', 2026, 2, 200.00, 'paid', '2026-03-29 17:55:18', 'pay_LDm4SQShCdjtTqq2JyJQYK3F', 'PayMongo (fallback sync)', NULL, '2026-03-29 17:55:18'),
(11, 118, 'Phase 1', 2026, 3, 200.00, 'paid', '2026-03-30 08:42:39', '209324267582', 'for 3 months', 9, '2026-03-30 08:42:39'),
(12, 119, 'Phase 1', 2026, 1, 200.00, 'paid', '2026-03-30 08:45:52', '', '', 9, '2026-03-30 08:45:52'),
(13, 120, 'Phase 1', 2026, 1, 200.00, 'paid', '2026-03-30 10:58:16', 'pay_MFZig74Ye8G9JyfxxXdoJoec', 'PayMongo (fallback sync)', NULL, '2026-03-30 10:58:16');

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
(44, 'cs_9a4a32d3f855330b0affe9bd', 'https://checkout.paymongo.com/9a4a32d3f855330b0affe9bd', 113, 'Phase 1', 2026, 1, 200.00, '', NULL, NULL, NULL, NULL, '2026-03-28 05:44:03', '2026-03-28 05:44:07'),
(45, 'cs_83c5483bc7e66302bf917849', 'https://checkout.paymongo.com/83c5483bc7e66302bf917849', 113, 'Phase 1', 2026, 1, 200.00, 'paid', 'pay_4ouRuy2AKjD3oUYGonDyfnMS', '2026-03-28 05:44:16', NULL, NULL, '2026-03-28 05:44:07', '2026-03-28 05:44:16'),
(46, 'cs_ce916cc1374e997e7bec2626', 'https://checkout.paymongo.com/ce916cc1374e997e7bec2626', 113, 'Phase 1', 2026, 2, 200.00, 'pending', NULL, NULL, NULL, NULL, '2026-03-28 05:44:19', '2026-03-28 05:44:19'),
(47, 'cs_e1cad1493572d1f0b63c1428', 'https://checkout.paymongo.com/e1cad1493572d1f0b63c1428', 115, 'Phase 1', 2026, 1, 200.00, '', NULL, NULL, NULL, NULL, '2026-03-29 14:03:56', '2026-03-30 17:37:39'),
(48, 'cs_f5c86760f0f3b613300e16dd', 'https://checkout.paymongo.com/f5c86760f0f3b613300e16dd', 115, 'Phase 1', 2026, 2, 200.00, 'paid', 'pay_LDm4SQShCdjtTqq2JyJQYK3F', '2026-03-29 17:55:18', NULL, NULL, '2026-03-29 17:54:25', '2026-03-29 17:55:18'),
(49, 'cs_f46087cdee9d460c05af71d2', 'https://checkout.paymongo.com/f46087cdee9d460c05af71d2', 120, 'Phase 1', 2026, 1, 200.00, '', NULL, NULL, NULL, NULL, '2026-03-30 10:54:04', '2026-03-30 10:56:24'),
(50, 'cs_a090d0a9a40fe6c8ec1599a7', 'https://checkout.paymongo.com/a090d0a9a40fe6c8ec1599a7', 120, 'Phase 1', 2026, 1, 200.00, 'paid', 'pay_MFZig74Ye8G9JyfxxXdoJoec', '2026-03-30 10:58:16', NULL, NULL, '2026-03-30 10:56:24', '2026-03-30 10:58:16'),
(51, 'cs_adbc84547b664697a3f593dd', 'https://checkout.paymongo.com/adbc84547b664697a3f593dd', 120, 'Phase 1', 2026, 2, 200.00, 'pending', NULL, NULL, NULL, NULL, '2026-03-30 10:58:21', '2026-03-30 10:58:21'),
(52, 'cs_e57b3554f94eca9d898c65bb', 'https://checkout.paymongo.com/e57b3554f94eca9d898c65bb', 115, 'Phase 1', 2026, 1, 200.00, 'pending', NULL, NULL, NULL, NULL, '2026-03-30 17:37:40', '2026-03-30 17:37:40');

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
(1, 'Phase 1', 2026, 2, 'rejected', 1, '2026-02-09 21:07:25', 'superadmin@gmail.com', '2026-03-28 05:52:39', 'Rejected'),
(3, 'Phase 1', 2026, 3, 'pending', 9, '2026-03-30 09:06:42', NULL, '2026-03-12 05:46:34', '');

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
(427, 'Phase 3', 'Board of Director', 'Stephanie R. Ong', 'stephanie.ong.p3@hoa.local', 1, '2026-02-13 20:31:30'),
(2632, 'Phase 1', 'President', 'Jheanna Abella', 'jheannaabigailerolesabella@gmail.com', 1, '2026-04-05 09:20:23'),
(2633, 'Phase 1', 'Vice President', 'patrick baculpo', 'baculpopatrick2440@gmail.com', 1, '2026-03-11 17:04:39'),
(2634, 'Phase 1', 'Secretary', 'John Cruz', 'p1_john.cruz@hoa.local', 1, '2026-03-11 17:04:39'),
(2635, 'Phase 1', 'Treasurer', 'Mika Dela Cruz', 'p1_mika.delacruz@hoa.local', 1, '2026-03-11 17:04:39'),
(2636, 'Phase 1', 'Board of Director', 'Anne Reyes', 'p1_anne.reyes@hoa.local', 1, '2026-03-11 17:04:39'),
(2637, 'Phase 1', 'Board of Director', 'Jenny Garcia', 'p1_jenny.garcia@hoa.local', 1, '2026-03-11 17:04:39'),
(2638, 'Phase 1', 'Board of Director', 'John Cruz', 'p1_john.cruz@hoa.local', 1, '2026-03-11 17:04:39'),
(2639, 'Phase 1', 'Board of Director', 'Liza Domingo', 'p1_liza.domingo@hoa.local', 1, '2026-03-11 17:04:39'),
(2640, 'Phase 1', 'Board of Director', 'Mark Santos', 'p1_mark.santos@hoa.local', 1, '2026-03-11 17:04:39'),
(2641, 'Phase 1', 'Board of Director', 'Paolo Flores', 'p1_paolo.flores@hoa.local', 1, '2026-04-05 09:34:46'),
(2642, 'Phase 1', 'Auditor', NULL, NULL, 1, '2026-04-05 09:20:24');

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
  `barangay` varchar(255) DEFAULT NULL,
  `city_municipality` varchar(255) DEFAULT NULL,
  `province` varchar(255) DEFAULT NULL,
  `region` varchar(255) DEFAULT NULL,
  `zip_code` varchar(50) DEFAULT NULL,
  `country` varchar(255) DEFAULT NULL,
  `other_location_info` text DEFAULT NULL,
  `exact_location` text DEFAULT NULL,
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

INSERT INTO `homeowners` (`id`, `public_id`, `first_name`, `middle_name`, `last_name`, `contact_number`, `email`, `password`, `must_change_password`, `phase`, `house_lot_number`, `barangay`, `city_municipality`, `province`, `region`, `zip_code`, `country`, `other_location_info`, `exact_location`, `valid_id_path`, `proof_of_billing_path`, `latitude`, `longitude`, `status`, `admin_id`, `created_at`, `reset_token`, `reset_expires`) VALUES
(37, 'P137', 'Mark', 'A', 'Santos', '09170000001', 'p1_mark.santos@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 1', 'Blk 1 Lot 1', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'uploads/seed/p1_id1.png', 'uploads/seed/p1_bill1.png', 14.3541010, 120.9461010, 'approved', 2, '2026-02-13 20:11:51', NULL, NULL),
(38, 'P138', 'Anne', 'M', 'Reyes', '09170000002', 'p1_anne.reyes@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 1', 'Blk 1 Lot 2', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'uploads/seed/p1_id2.png', 'uploads/seed/p1_bill2.png', 14.3545890, 120.9463410, 'approved', 2, '2026-02-13 20:11:51', NULL, NULL),
(39, 'P139', 'John', 'D', 'Cruz', '09170000003', 'p1_john.cruz@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 1', 'Blk 1 Lot 3', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'uploads/seed/p1_id3.png', 'uploads/seed/p1_bill3.png', 14.3541410, 120.9461410, 'approved', 2, '2026-02-13 20:11:51', NULL, NULL),
(40, 'P140', 'Jenny', 'L', 'Garcia', '09170000004', 'p1_jenny.garcia@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 1', 'Blk 1 Lot 4', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'uploads/seed/p1_id4.png', 'uploads/seed/p1_bill4.png', 14.3541610, 120.9461610, 'approved', 2, '2026-02-13 20:11:51', NULL, NULL),
(41, 'P141', 'Paolo', 'R', 'Flores', '09170000005', 'p1_paolo.flores@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 1', 'Blk 1 Lot 5', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'uploads/seed/p1_id5.png', 'uploads/seed/p1_bill5.png', 14.3541810, 120.9461810, 'approved', 2, '2026-02-13 20:11:51', NULL, NULL),
(42, 'P142', 'Liza', 'C', 'Domingo', '09170000006', 'p1_liza.domingo@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 1', 'Blk 2 Lot 1', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'uploads/seed/p1_id6.png', 'uploads/seed/p1_bill6.png', 14.3542010, 120.9462010, 'approved', 2, '2026-02-13 20:11:51', NULL, NULL),
(43, 'P143', 'Ryan', 'P', 'Navarro', '09170000007', 'p1_ryan.navarro@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 1', 'Blk 2 Lot 2', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'uploads/seed/p1_id7.png', 'uploads/seed/p1_bill7.png', 14.3542210, 120.9462210, 'approved', 2, '2026-02-13 20:11:51', NULL, NULL),
(44, 'P144', 'Mika', 'S', 'Dela Cruz', '09170000008', 'p1_mika.delacruz@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 1', 'Blk 2 Lot 3', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'uploads/seed/p1_id8.png', 'uploads/seed/p1_bill8.png', 14.3542410, 120.9462410, 'approved', 2, '2026-02-13 20:11:51', NULL, NULL),
(45, 'P145', 'Carlo', 'B', 'Lim', '09170000009', 'p1_carlo.lim@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 1', 'Blk 2 Lot 4', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'uploads/seed/p1_id9.png', 'uploads/seed/p1_bill9.png', 14.3542610, 120.9462610, 'approved', 2, '2026-02-13 20:11:51', NULL, NULL),
(46, 'P146', 'Grace', 'T', 'Salazar', '09170000010', 'p1_grace.salazar@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 1', 'Blk 2 Lot 5', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'uploads/seed/p1_id10.png', 'uploads/seed/p1_bill10.png', 14.3542810, 120.9462810, 'approved', 2, '2026-02-13 20:11:51', NULL, NULL),
(47, 'P247', 'Kevin', 'J', 'Villanueva', '09170000011', 'p2_kevin.villanueva@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 2', 'Blk 3 Lot 1', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'uploads/seed/p2_id1.png', 'uploads/seed/p2_bill1.png', 14.3537010, 120.9467010, 'approved', 3, '2026-02-13 20:11:51', NULL, NULL),
(48, 'P248', 'Nina', 'F', 'Torres', '09170000012', 'p2_nina.torres@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 2', 'Blk 3 Lot 2', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'uploads/seed/p2_id2.png', 'uploads/seed/p2_bill2.png', 14.3537210, 120.9467210, 'approved', 3, '2026-02-13 20:11:51', NULL, NULL),
(49, 'P249', 'Jasper', 'K', 'Aquino', '09170000013', 'p2_jasper.aquino@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 2', 'Blk 3 Lot 3', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'uploads/seed/p2_id3.png', 'uploads/seed/p2_bill3.png', 14.3537410, 120.9467410, 'approved', 3, '2026-02-13 20:11:51', NULL, NULL),
(50, 'P250', 'Bea', 'R', 'Mendoza', '09170000014', 'p2_bea.mendoza@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 2', 'Blk 3 Lot 4', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'uploads/seed/p2_id4.png', 'uploads/seed/p2_bill4.png', 14.3537610, 120.9467610, 'approved', 3, '2026-02-13 20:11:51', NULL, NULL),
(51, 'P251', 'Oscar', 'M', 'Pascual', '09170000015', 'p2_oscar.pascual@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 2', 'Blk 3 Lot 5', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'uploads/seed/p2_id5.png', 'uploads/seed/p2_bill5.png', 14.3537810, 120.9467810, 'approved', 3, '2026-02-13 20:11:51', NULL, NULL),
(52, 'P252', 'Elaine', 'S', 'Ramos', '09170000016', 'p2_elaine.ramos@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 2', 'Blk 4 Lot 1', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'uploads/seed/p2_id6.png', 'uploads/seed/p2_bill6.png', 14.3538010, 120.9468010, 'approved', 3, '2026-02-13 20:11:51', NULL, NULL),
(53, 'P253', 'Tony', 'L', 'Chua', '09170000017', 'p2_tony.chua@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 2', 'Blk 4 Lot 2', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'uploads/seed/p2_id7.png', 'uploads/seed/p2_bill7.png', 14.3538210, 120.9468210, 'approved', 3, '2026-02-13 20:11:51', NULL, NULL),
(54, 'P254', 'Kaye', 'D', 'Lopez', '09170000018', 'p2_kaye.lopez@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 2', 'Blk 4 Lot 3', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'uploads/seed/p2_id8.png', 'uploads/seed/p2_bill8.png', 14.3538410, 120.9468410, 'approved', 3, '2026-02-13 20:11:51', NULL, NULL),
(55, 'P255', 'Hanna', 'G', 'Valdez', '09170000019', 'p2_hanna.valdez@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 2', 'Blk 4 Lot 4', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'uploads/seed/p2_id9.png', 'uploads/seed/p2_bill9.png', 14.3538610, 120.9468610, 'approved', 3, '2026-02-13 20:11:51', NULL, NULL),
(56, 'P256', 'Leo', 'P', 'Castro', '09170000020', 'p2_leo.castro@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 2', 'Blk 4 Lot 5', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'uploads/seed/p2_id10.png', 'uploads/seed/p2_bill10.png', 14.3538810, 120.9468810, 'approved', 3, '2026-02-13 20:11:51', NULL, NULL),
(57, 'P357', 'Ivy', 'N', 'Bautista', '09170000021', 'p3_ivy.bautista@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 3', 'Blk 5 Lot 1', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'uploads/seed/p3_id1.png', 'uploads/seed/p3_bill1.png', 14.3532010, 120.9472010, 'approved', 4, '2026-02-13 20:11:51', NULL, NULL),
(58, 'P358', 'Arvin', 'C', 'Marquez', '09170000022', 'p3_arvin.marquez@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 3', 'Blk 5 Lot 2', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'uploads/seed/p3_id2.png', 'uploads/seed/p3_bill2.png', 14.3532210, 120.9472210, 'approved', 4, '2026-02-13 20:11:51', NULL, NULL),
(59, 'P359', 'Shane', 'R', 'Diaz', '09170000023', 'p3_shane.diaz@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 3', 'Blk 5 Lot 3', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'uploads/seed/p3_id3.png', 'uploads/seed/p3_bill3.png', 14.3532410, 120.9472410, 'approved', 4, '2026-02-13 20:11:51', NULL, NULL),
(60, 'P360', 'Mara', 'S', 'Velasco', '09170000024', 'p3_mara.velasco@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 3', 'Blk 5 Lot 4', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'uploads/seed/p3_id4.png', 'uploads/seed/p3_bill4.png', 14.3532610, 120.9472610, 'approved', 4, '2026-02-13 20:11:51', NULL, NULL),
(61, 'P361', 'Noel', 'T', 'Fernandez', '09170000025', 'p3_noel.fernandez@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 3', 'Blk 5 Lot 5', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'uploads/seed/p3_id5.png', 'uploads/seed/p3_bill5.png', 14.3532810, 120.9472810, 'approved', 4, '2026-02-13 20:11:51', NULL, NULL),
(62, 'P362', 'Bianca', 'L', 'Mercado', '09170000026', 'p3_bianca.mercado@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 3', 'Blk 6 Lot 1', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'uploads/seed/p3_id6.png', 'uploads/seed/p3_bill6.png', 14.3533010, 120.9473010, 'approved', 4, '2026-02-13 20:11:51', NULL, NULL),
(63, 'P363', 'Drew', 'P', 'Gomez', '09170000027', 'p3_drew.gomez@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 3', 'Blk 6 Lot 2', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'uploads/seed/p3_id7.png', 'uploads/seed/p3_bill7.png', 14.3533210, 120.9473210, 'approved', 4, '2026-02-13 20:11:51', NULL, NULL),
(64, 'P364', 'Tina', 'A', 'Sison', '09170000028', 'p3_tina.sison@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 3', 'Blk 6 Lot 3', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'uploads/seed/p3_id8.png', 'uploads/seed/p3_bill8.png', 14.3533410, 120.9473410, 'approved', 4, '2026-02-13 20:11:51', NULL, NULL),
(65, 'P365', 'Cedric', 'M', 'Herrera', '09170000029', 'p3_cedric.herrera@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 3', 'Blk 6 Lot 4', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'uploads/seed/p3_id9.png', 'uploads/seed/p3_bill9.png', 14.3533610, 120.9473610, 'approved', 4, '2026-02-13 20:11:51', NULL, NULL),
(66, 'P366', 'Aya', 'G', 'Pineda', '09170000030', 'p3_aya.pineda@hoa.local', '$2y$10$wH5QfKqzB7bKp6pQK0f6eOq1JpZp9nQnqgC0h9oQk0qj6oJrWw5aW', 1, 'Phase 3', 'Blk 6 Lot 5', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'uploads/seed/p3_id10.png', 'uploads/seed/p3_bill10.png', 14.3533810, 120.9473810, 'approved', 4, '2026-02-13 20:11:51', NULL, NULL),
(88, NULL, 'Jheanna', 'Abigail', 'Abella', '09029309101', 'jheannaabigailerolesabella@gmail.com', '$2y$10$0T/.SHVQUskk4oeKPVp6jeyzIDz571ahgFbHqLvzdO.LpMBVpb5HW', 0, 'Phase 1', 'utot mo', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'uploads/1773067918_id_Screenshot 2023-10-19 154922.png', 'uploads/1773067918_proof_Screenshot 2023-10-19 202059.png', 14.3548655, 120.9460555, 'approved', 2, '2026-03-09 14:52:04', NULL, NULL),
(93, NULL, 'patrick', '', 'baculpo', '09916964490', 'baculpopatrick2440@gmail.com', '$2y$10$S1mF2jGz.uPBBGNEb84zzuT7bkt8C4csNuhbw0F0rHUtL7a./6vKy', 1, 'Phase 1', 'blk 7 lot 9', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'uploads/1773839929_id_images (1).png', 'uploads/1773839929_proof_images (2).png', 14.3537782, 120.9469199, 'rejected', 6, '2026-03-18 13:18:53', NULL, NULL),
(105, 'P1-000105', 'Liam', '', 'Alexander', '09916964490', 'leiannmartinez2440@gmail.com', '$2y$10$/61TLQav66EUbPZPuneKdeQFDHJHm0YztTFxYSkcgT202sWUqPxqm', 1, 'Phase 1', 'blk 15 lot 6', 'Salitran', 'Dasmariñas', 'Cavite', 'Calabarzon', '4114', 'Philippines', 'Belgium Street', NULL, 'uploads/1774460632_id_IT310_Information_Assurance_and_Security_1_2nd_Sem_Final.pdf', 'uploads/1774460632_proof_IT310_Information_Assurance_and_Security_1_2nd_Sem_Final.pdf', 14.3561584, 120.9456968, 'rejected', 6, '2026-03-25 17:43:52', NULL, NULL),
(110, 'P1-000110', 'patrick', '', 'baculpo', '09916964490', 'dawdawd@gmail.com', '$2y$10$vxc9vBkBUOVwwVbz2cR2eeprbY/DnS5GPpVnA6XrSilK.uZXExJ/6', 1, 'Phase 1', 'blk 7 lot 9', 'Salitran', 'Dasmariñas', 'Cavite', 'Calabarzon', '4114', 'Philippines', 'Equator Street', NULL, 'uploads/1774675096_id_sm_logo.png', 'uploads/1774675096_proof_favicon.png', 14.3548655, 120.9460555, 'rejected', 6, '2026-03-28 05:18:16', NULL, NULL),
(111, 'P119', 'erick', '', 'baculpo', '09916964490', 'awasdaawd@gmail.com', '$2y$10$kMLp7SxuJ1t.YtV547nQfOEeAlk.bTF090d4KeGlav7oWDgdegGTm', 1, 'Phase 1', 'blk 7 lot 9', 'Salitran', 'Dasmariñas', 'Cavite', 'Calabarzon', '4114', 'Philippines', 'Equator Street', NULL, 'uploads/1774675589_id_sm_logo.png', 'uploads/1774675589_proof_favicon.png', 14.3548655, 120.9460555, 'rejected', 6, '2026-03-28 05:26:29', NULL, NULL),
(112, 'P120', 'patrick', '', 'baculpo', '09916964490', 'awasdawasdadwadwad@gmail.com', '$2y$10$vk/IDJmuxCPRsf0cKRFtgOT5O0GcrNB5Ya05FQxYa6Rq4X3/WhVOi', 1, 'Phase 1', 'blk 7 lot 9', 'Salitran', 'Dasmariñas', 'Cavite', 'Calabarzon', '4114', 'Philippines', 'Equator Street', NULL, 'uploads/1774675631_id_sm_logo.png', 'uploads/1774675631_proof_favicon.png', 14.3548655, 120.9460555, 'rejected', 6, '2026-03-28 05:27:11', NULL, NULL),
(113, 'P121', 'patrick', '', 'baculpo', '09916964490', 'ljbaculpo2440@gmail.com', '$2y$10$WZGrcthoB6dcppNXY/NL/e4neIpywntdaxvGoNwTl6SWP082npchO', 0, 'Phase 1', 'blk 7 lot 9', 'Salitran', 'Dasmariñas', 'Cavite', 'Calabarzon', '4114', 'Philippines', 'Equator Street', NULL, 'uploads/1774675917_1f698386_id_sm_logo.png', 'uploads/1774675917_1f698386_proof_favicon.png', 14.3548655, 120.9460555, 'approved', 9, '2026-03-28 05:31:57', NULL, NULL),
(114, 'P212', 'Dex', 'Lex', 'Sia', '09321213123', 'patanijayandrew@gmail.com', '$2y$10$gbLTctHXSbAm0pXxn1G0LuyicwWg7HUyRIsEP/bOjxd98UQh.Vlzy', 0, 'Phase 2', 'bllk 1 lot 2', 'Salitran', 'Dasmariñas', 'Cavite', 'Calabarzon', '4114', 'Philippines', '', NULL, 'uploads/1774685361_69a5eb91_id_Screenshot_2025-03-24_180255.png', 'uploads/1774685361_69a5eb91_proof_Screenshot_2025-03-24_181051.png', 14.3558050, 120.9448171, 'approved', 8, '2026-03-28 08:09:21', NULL, NULL),
(115, 'P122', 'mark dexter', '', 'legacion', '09278509963', 'chann7721@gmail.com', '$2y$10$b1vXI6LTZvyPRrZ9N.tYiu/0HO8wvtg4elR9f3Xdag19SlK3PCKCK', 0, 'Phase 1', 'house 7', 'Salitran', 'Dasmariñas', 'Cavite', 'Calabarzon', '4114', 'Philippines', 'Brazil Street', NULL, 'uploads/1774792840_c35c93b6_id_NCST_NEW_LOGO.png', 'uploads/1774792840_c35c93b6_proof_NCST_NEW_LOGO.png', 14.3562831, 120.9455252, 'approved', 9, '2026-03-29 14:00:40', NULL, NULL),
(116, 'P123', 'dawdsasddwad', 'awdsa', 'dawdsa', 'dsawdsa', 'jayjay@gmail.com', '$2y$10$28r3UwhHcZKV8l00d8gSe.NH.UdWTpox7Vrf1d/xggheChUQbzyYm', 1, 'Phase 1', '12312', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'uploads/1774811983_38bd00af_id_Screenshot_2025-03-24_180255.png', 'uploads/1774811983_38bd00af_proof_Screenshot_2025-03-24_180255.png', 14.3557738, 120.9454929, 'approved', 9, '2026-03-29 19:20:10', 'aacc1a2a17c22935ed529eb54fbe5b18acfa79af4d436c968361fb0821478a36', '2026-03-29 21:09:53'),
(117, 'P124', 'dsawd', 'dsawd', 'dsawddsaw', 'dwadsadwa', 'jayandrew@gmail.com', '$2y$10$ohp6MwZzSbgX8cjsdy60XOWDOsgQqcFpFFXhFMH5ewpj7yXAItVEK', 1, 'Phase 1', 'dawdsa', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'uploads/1774815400_1d369df5_id_HOmanage.drawio.png', 'uploads/1774815400_1d369df5_proof_Usermanagementhomeowners.drawio.png', 14.3548655, 120.9460555, 'approved', 9, '2026-03-29 20:16:44', 'be56bcac674db45f2c6625b4cb310aeec87305f1cf05acb271b2bcbf954d9e7c', '2026-03-29 21:16:58'),
(118, 'P125', 'Jay', 'Andrew', 'Patani', '09321213123', 'jayyjayy@gmail.com', '$2y$10$.Ol/DL4Dtmt2QZmCA8YWyeuOUGAmSkPzBn05p4gdbEM1vKSiTcQfa', 1, 'Phase 1', 'blk 12 lot 2', 'Salitran', 'Dasmariñas', 'Cavite', 'Calabarzon', '4114', 'Philippines', 'Equator Street', NULL, 'uploads/1774815627_5c74759c_id_regho.drawio.png', 'uploads/1774815627_5c74759c_proof_HOmanage.drawio.png', 14.3548655, 120.9460555, 'approved', 9, '2026-03-29 20:20:27', 'a403d19756683908fc1f443688830e8238f5498198fefdaf0896335a79bccab3', '2026-03-30 04:27:10'),
(119, 'P126', 'Jay', 'Andrew', 'Patani', 'dsawdsa', 'jayandrewpatani18@gmail.co', '$2y$10$DQgkzVHSW73CZxgxeSFtYOz84KCaNFz4n1Q1F7ILsp2FDKjkaDzB.', 1, 'Phase 1', 'blk 12 lot 2', 'Salitran', 'Dasmariñas', 'Cavite', 'Calabarzon', '4114', 'Philippines', 'Equator Street', NULL, 'uploads/1774815716_f7bb79de_id_HOmanage.drawio.png', 'uploads/1774815716_f7bb79de_proof_regho.drawio.png', 14.3548655, 120.9460555, 'approved', 9, '2026-03-29 20:21:56', '9803dc49d910d5f5dcec6ab8a64a5cc420bbec78f8610bd0d0b04a1696d836c8', '2026-03-29 21:22:51'),
(120, 'P127', 'Jay', 'Andrew', 'Patani', '09321213123', 'jayandrewpatani18@gmail.com', '$2y$10$Z5FORNk5MJno4bBdErd4NuEeLMw/fG0YdBUjzlsbxojqXkqe6iiuO', 0, 'Phase 1', 'blk 12 lot 2', 'Salitran', 'Dasmariñas', 'Cavite', 'Calabarzon', '4114', 'Philippines', 'Equator Street', NULL, 'uploads/1774815985_a4c5071a_id_HOMhomeowners.drawio.png', 'uploads/1774815985_a4c5071a_proof_HOMhomeowners.drawio.png', 14.3548655, 120.9460555, 'approved', 9, '2026-03-29 20:26:25', NULL, NULL),
(121, 'P128', 'w131w', '231321', '21312', '32131', '321321@gmail.com', '$2y$10$p0OA2ILB9mCXxb/QctBk0.ZfUbDKALotmcIDyXd9qM79LdGqfxovy', 1, 'Phase 1', 'blk 12 lot 2', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'uploads/1774845848_ab0351e1_id_HOmanage.drawio.png', 'uploads/1774845848_ab0351e1_proof_HOmanage.drawio.png', 14.3548655, 120.9460555, 'pending', 9, '2026-03-30 04:44:14', NULL, NULL),
(122, 'P129', 'Juan', 'Santos', 'Dela Cruz', '09123456789', 'juan@gmail.com', '$2y$10$A8bwHroYFAUjePwKLjyrOeTzHrJDjkKi4Ao.AgIGrdac8zK72COpy', 1, 'Phase 1', 'Blk 5 Lot 12', '', '', '', '', '', '', '', NULL, 'uploads/1774902506_02dfe519_id_manages.php', 'uploads/1774902506_02dfe519_proof_manage.php', 14.3545000, 120.9460000, 'pending', 9, '2026-03-30 20:28:26', NULL, NULL),
(123, 'P130', 'Juan', 'Santos', 'Dela Cruz', '09123456789', 'juana@gmail.com', '$2y$10$pU2cHYNX3.AC0rZh2epWb.QkJm9bogOJaXj.rcwEkdJXiFGk5HbFK', 1, 'Phase 1', 'Blk 5 Lot 12', '', '', '', '', '', '', '', NULL, 'uploads/1774902735_66824780_id_manages.php', 'uploads/1774902735_66824780_proof_manage.php', 14.3545000, 120.9460000, 'pending', 9, '2026-03-30 20:32:15', NULL, NULL),
(124, 'P131', 'Juan', 'Santos', 'Dela Cruz', '09123456789', 'juansa@gmail.com', '$2y$10$nVeBBAlZ3Qol32Z0vdHeFOKW1sHnWHmSYhAhg3V5bPU5YwVa9e4pO', 1, 'Phase 1', 'Blk 5 Lot 12', '', '', '', '', '', '', '', NULL, 'uploads/1774902912_e81742a4_id_manages.phtml', 'uploads/1774902912_e81742a4_proof_manage.phtml', 14.3545000, 120.9460000, 'pending', 9, '2026-03-30 20:35:12', NULL, NULL);

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
(88, '2026-03-09 14:52:55', '2026-03-09 14:52:55', '2026-03-09 14:52:55', '2026-03-09 14:52:55'),
(113, '2026-03-28 05:43:29', '2026-03-28 05:43:29', '2026-03-28 05:43:29', '2026-03-28 05:43:29'),
(114, '2026-03-28 08:20:06', '2026-03-28 08:20:06', '2026-03-28 08:20:06', '2026-03-28 08:20:06'),
(115, '2026-03-29 14:01:56', '2026-03-29 14:01:56', '2026-03-29 14:01:56', '2026-03-29 14:01:56'),
(120, '2026-03-29 20:28:03', '2026-03-29 20:28:03', '2026-03-29 20:28:03', '2026-03-29 20:28:03');

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

--
-- Dumping data for table `homeowner_officer_messages`
--

INSERT INTO `homeowner_officer_messages` (`id`, `phase`, `homeowner_id`, `admin_id`, `sender_type`, `message`, `attachment_name`, `attachment_path`, `attachment_type`, `is_read_by_homeowner`, `is_read_by_admin`, `created_at`) VALUES
(1, 'Phase 1', 113, 9, 'homeowner', 'hello', NULL, NULL, NULL, 1, 1, '2026-03-28 05:44:35'),
(2, 'Phase 1', 113, 9, 'admin', 'hi', NULL, NULL, NULL, 0, 1, '2026-04-05 09:15:23');

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
(31, 36, 'Patrick Justin', '', 'Baculpo', 'Caretaker'),
(32, 72, 'patrick', '', 'baculpo', 'Homeowner'),
(33, 73, 'patrick', '', 'baculpo', 'Caretaker'),
(34, 74, 'patrick', '', 'baculpo', 'Caretaker'),
(35, 75, 'Jdjww', 'Jssjwkw', 'Nssjwkwk', ''),
(36, 76, 'awda w', 'dnwdklwnd', 'dnawndkal', 'Homeowner'),
(37, 77, 'Nsjskwwkwkqk', 'Nxsjwkwkqk', 'Xnjakqk', 'Homeowner'),
(38, 78, 'Maritess', 'Enriquez', 'Baculpo', 'Homeowner'),
(39, 79, 'Maritess', 'Enriquez', 'Baculpo', 'Tenant'),
(40, 80, 'patrick', '', 'baculpo', 'Homeowner'),
(41, 81, 'awdadwa', 'awdaw', 'awda', 'Homeowner'),
(42, 82, 'awda', 'awd', 'awdwada', 'Homeowner'),
(43, 83, 'ho', 'lee', 'shit', 'Relative'),
(44, 84, 'don', 'din', 'eerf', 'Homeowner'),
(45, 85, 'ho', 'lee', 'sheee', 'Relative'),
(46, 86, 'Jheanna', 'Abigail', 'Abella', 'Homeowner'),
(47, 87, 'ho', 'lee', 'sheee', 'Relative'),
(48, 88, 'Jheanna', 'Abigail', 'Abella', 'Relative'),
(52, 92, 'patrick', '', 'baculpo', 'Spouse'),
(53, 93, 'patrick', '', 'baculpo', 'Relative'),
(68, 105, 'patrick', '', 'baculpo', 'Homeowner'),
(73, 110, 'patrick', '', 'baculpo', 'Homeowner'),
(74, 113, 'patrick', '', 'baculpo', 'Homeowner'),
(75, 114, 'patrick', 'justin', 'baculpo', 'Child'),
(76, 115, 'ho', 'lee', 'sheee', 'Homeowner'),
(77, 116, '231213', '213', '3213', 'Child'),
(78, 117, 'Jay', 'Andrew', 'Patani', 'Relative'),
(79, 118, 'Jay', 'Andrew', 'Patani', ''),
(80, 119, 'Jay', 'Andrew', 'Patani', 'Spouse'),
(81, 120, 'Jay', 'Andrew', 'Patani', 'Relative'),
(82, 121, 'Jay', 'Andrew', 'Patani', 'Parent'),
(83, 122, 'Maria', '', 'Dela Cruz', ''),
(84, 122, 'Pedro', '', 'Dela Cruz', ''),
(85, 123, 'Maria', '', 'Dela Cruz', ''),
(86, 123, 'Pedro', '', 'Dela Cruz', ''),
(87, 124, 'Maria', '', 'Dela Cruz', ''),
(88, 124, 'Pedro', '', 'Dela Cruz', '');

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

--
-- Dumping data for table `parking_permits`
--

INSERT INTO `parking_permits` (`id`, `phase`, `homeowner_id`, `request_type`, `renew_of_id`, `plate_no`, `vehicle_type`, `vehicle_make`, `vehicle_model`, `vehicle_color`, `permit_no`, `sticker_year`, `permit_duration`, `payment_method`, `contract_path`, `status`, `valid_from`, `valid_until`, `requested_at`, `approved_by_admin_id`, `approved_at`, `rejected_reason`, `revoked_reason`, `updated_at`, `vehicle_front_path`, `vehicle_back_path`, `payment_status`) VALUES
(8, 'Phase 1', 115, 'new', NULL, '12ABC', 'car', 'toyoto', 'vios', '0', 'P1-001', 2026, '1_month', 'online', 'uploads/parking_contracts/parking_contract_1775375714_8524390d.html', 'pending', '2026-04-05', '2026-05-04', '2026-04-05 07:55:14', 9, '2026-04-05 15:55:45', NULL, NULL, '2026-04-05 07:55:45', 'uploads/parking_permits/1775375714_e5546aebf56b.jpg', 'uploads/parking_permits/1775375714_c7372fb34717.jpg', 'for payment');

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

--
-- Dumping data for table `public_chat_messages`
--

INSERT INTO `public_chat_messages` (`id`, `phase`, `homeowner_id`, `message`, `attachment_name`, `attachment_path`, `attachment_type`, `created_at`) VALUES
(5, 'Phase 1', 113, 'hi', NULL, NULL, NULL, '2026-03-28 05:44:31'),
(6, 'Phase 2', 114, 'Hi', NULL, NULL, NULL, '2026-03-28 08:36:23'),
(7, 'Phase 1', 115, 'hello', NULL, NULL, NULL, '2026-04-04 08:51:34'),
(8, 'Phase 1', 115, '', 'abi.jpg', 'uploads/chat_files/1775377010_7628adbe.jpg', 'image/jpeg', '2026-04-05 08:16:50'),
(9, 'Phase 1', 115, 'gello', NULL, NULL, NULL, '2026-04-05 08:30:33');

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

--
-- Dumping data for table `staff_applications`
--

INSERT INTO `staff_applications` (`id`, `phase`, `staff_type`, `source_type`, `homeowner_id`, `full_name`, `email`, `contact_number`, `address`, `position_title`, `notes`, `valid_id_path`, `resume_path`, `photo_path`, `status`, `applied_by_admin_id`, `president_admin_id`, `president_action_at`, `president_remarks`, `created_at`, `updated_at`) VALUES
(1, 'Phase 1', 'Guard', '', NULL, 'patrick baculpo', 'baculpopatrick2440@gmail.com', '09916964490', 'B15 L6 Phase 2, Camia str, Villa Luisa', 'Main guard', '', 'uploads/staff/staff_id_1773946359_c4d3e08e.png', 'uploads/staff/staff_resume_1773946359_7412404b.docx', 'uploads/staff/staff_photo_1773946359_c9db8c52.jpg', 'rejected', 9, 9, '2026-03-19 19:12:56', '', '2026-03-19 18:52:39', '2026-03-19 19:12:56'),
(2, 'Phase 1', 'Guard', '', NULL, 'patrick baculpo', 'baculpopatrick2440@gmail.com', '09916964490', 'B15 L6 Phase 2, Camia str, Villa Luisa', 'Main guard', '', 'uploads/staff/staff_id_1773947135_827d3c09.jpg', 'uploads/staff/staff_resume_1773947135_07f5a212.docx', 'uploads/staff/staff_photo_1773947135_a297f55b.jpg', 'rejected', 6, 9, '2026-03-19 19:13:04', '', '2026-03-19 19:05:35', '2026-03-19 19:13:04'),
(3, 'Phase 1', 'Guard', 'non_resident', NULL, 'patrick baculpo', 'baculpopatrick2440@gmail.com', '09916964490', 'B15 L6 Phase 2, Camia str, Villa Luisa', 'Main guard', '', 'uploads/staff/staff_id_1773947493_00db7e74.jpg', 'uploads/staff/staff_resume_1773947493_36ae0d27.docx', 'uploads/staff/staff_photo_1773947493_d88168af.jpg', 'approved', 6, 9, '2026-03-19 19:13:08', '', '2026-03-19 19:11:33', '2026-03-19 19:13:08'),
(4, 'Phase 1', 'Guard', 'homeowner', 120, 'Jay Andrew Patani', 'jayandrewpatani18@gmail.com', '09321213123', 'blk 12 lot 2', 'main', 'be on time', 'uploads/staff/staff_id_1774816370_883baad1.png', 'uploads/staff/staff_resume_1774816370_9bf804f4.png', 'uploads/staff/staff_photo_1774816370_977e2aa8.png', 'approved', 9, 9, '2026-03-29 20:38:55', 'goodjob', '2026-03-29 20:32:50', '2026-03-29 20:38:55'),
(5, 'Phase 1', 'Guard', 'homeowner', 46, 'Grace T Salazar', 'p1_grace.salazar@hoa.local', '09170000010', 'Blk 2 Lot 5', 'main', 'psss', 'uploads/staff/staff_id_1774844865_6efc3be4.png', 'uploads/staff/staff_resume_1774844865_47ed6378.png', 'uploads/staff/staff_photo_1774844865_7e4eb222.png', 'approved', 9, 9, '2026-03-30 05:36:33', 'dwasda', '2026-03-30 04:27:45', '2026-03-30 05:36:33');

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

--
-- Dumping data for table `staff_members`
--

INSERT INTO `staff_members` (`id`, `application_id`, `phase`, `staff_type`, `source_type`, `homeowner_id`, `full_name`, `email`, `contact_number`, `address`, `position_title`, `is_active`, `approved_by_admin_id`, `approved_at`, `created_at`, `updated_at`) VALUES
(1, 3, 'Phase 1', 'Guard', 'non_resident', NULL, 'patrick baculpo', 'baculpopatrick2440@gmail.com', '09916964490', 'B15 L6 Phase 2, Camia str, Villa Luisa', 'Main guard', 1, 9, '2026-03-19 19:13:08', '2026-03-19 19:13:08', '2026-03-30 05:44:31'),
(2, 4, 'Phase 1', 'Guard', 'homeowner', 120, 'Jay Andrew Patani', 'jayandrewpatani18@gmail.com', '09321213123', 'blk 12 lot 2', 'main', 0, 9, '2026-03-29 20:38:55', '2026-03-29 20:38:55', '2026-03-30 05:44:35'),
(3, 5, 'Phase 1', 'Guard', 'homeowner', 46, 'Grace T Salazar', 'p1_grace.salazar@hoa.local', '09170000010', 'Blk 2 Lot 5', 'main', 1, 9, '2026-03-30 05:36:33', '2026-03-30 05:36:33', '2026-03-30 05:44:24');

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
-- Dumping data for table `tenants`
--

INSERT INTO `tenants` (`id`, `homeowner_id`, `phase`, `house_lot_number`, `first_name`, `middle_name`, `last_name`, `email`, `password`, `contact_number`, `valid_id_path`, `can_pay_dues`, `can_rent`, `can_parking`, `can_announcements`, `lease_start`, `lease_end`, `status`, `registered_at`, `updated_at`) VALUES
(1, 115, 'Phase 1', 'house 7', 'patrick', '', 'baculpo', 'tenant1@gmail.com', '$2y$10$wsOgqFb1dZSjQbNP/o1x3eh7narBGNhgDjdBsLd2tvEjeRYI1qt3S', '09916964490', NULL, 1, 1, 1, 1, NULL, NULL, 'active', '2026-04-04 07:36:27', '2026-04-04 07:40:44');

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
  ADD UNIQUE KEY `uniq_phase_position_homeowner` (`phase`,`position`,`homeowner_id`),
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
  ADD UNIQUE KEY `uniq_phase_position_name` (`phase`,`position`,`officer_name`);

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
-- Indexes for table `household_members`
--
ALTER TABLE `household_members`
  ADD PRIMARY KEY (`id`);

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6187;

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `announcements`
--
ALTER TABLE `announcements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `announcement_attachments`
--
ALTER TABLE `announcement_attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `announcement_comments`
--
ALTER TABLE `announcement_comments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `announcement_likes`
--
ALTER TABLE `announcement_likes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `announcement_recipients`
--
ALTER TABLE `announcement_recipients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=155;

--
-- AUTO_INCREMENT for table `complaints`
--
ALTER TABLE `complaints`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `complaint_messages`
--
ALTER TABLE `complaint_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `election_nominations`
--
ALTER TABLE `election_nominations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `election_sessions`
--
ALTER TABLE `election_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `finance_donations`
--
ALTER TABLE `finance_donations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `finance_dues_settings`
--
ALTER TABLE `finance_dues_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `finance_expenses`
--
ALTER TABLE `finance_expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `finance_opening_balance`
--
ALTER TABLE `finance_opening_balance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `finance_payments`
--
ALTER TABLE `finance_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `finance_paymongo_checkouts`
--
ALTER TABLE `finance_paymongo_checkouts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=53;

--
-- AUTO_INCREMENT for table `finance_report_requests`
--
ALTER TABLE `finance_report_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `hoa_officers`
--
ALTER TABLE `hoa_officers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2643;

--
-- AUTO_INCREMENT for table `homeowners`
--
ALTER TABLE `homeowners`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=125;

--
-- AUTO_INCREMENT for table `homeowner_officer_messages`
--
ALTER TABLE `homeowner_officer_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `homeowner_positions`
--
ALTER TABLE `homeowner_positions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `household_members`
--
ALTER TABLE `household_members`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=89;

--
-- AUTO_INCREMENT for table `parking_permits`
--
ALTER TABLE `parking_permits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `parking_violations`
--
ALTER TABLE `parking_violations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `public_chat_messages`
--
ALTER TABLE `public_chat_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `public_chat_mutes`
--
ALTER TABLE `public_chat_mutes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `staff_applications`
--
ALTER TABLE `staff_applications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `staff_members`
--
ALTER TABLE `staff_members`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `tenants`
--
ALTER TABLE `tenants`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

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
