-- ============================================================
-- SOUTH MERIDIAN HOMES HOA
-- FRESH START DATABASE
-- Baseline: user's current database exported October 2, 2026
--
-- Preserved:
--   * Current schema, indexes, triggers and foreign keys
--   * Access module / permission configuration
--   * Facility rental pricing defaults
--   * Clean zero-value finance configuration
--   * Superadmin account ONLY
--
-- Cleared:
--   * Homeowners / tenants / HOA officers
--   * Admin/officer accounts other than Superadmin
--   * Complaints, chats, calls, announcements
--   * Finance transactions/import queues/audit logs
--   * Parking, facility requests, CCTV clips
--   * Voting/election data and voting requests
--   * Login-security states and appeals
--   * Realtime/test/activity data
--
-- Added from latest other-window SQL:
--   * voting_request_candidates
--
-- IMPORTANT:
-- Import this into a SEPARATE fresh/defense database.
-- Do not overwrite the development database unless intentionally resetting it.
-- ============================================================

-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 02, 2026 at 11:50 AM
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
(3601, 'President', 'activity_log', 1, '2026-10-02 06:25:01'),
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

--
-- Dumping data for table `activity_logs`
--

-- Fresh start: data intentionally cleared from `activity_logs`.

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` int(11) NOT NULL,
  `homeowner_id` int(11) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `full_name` varchar(255) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3','Superadmin') NOT NULL,
  `role` enum('admin','superadmin') NOT NULL,
  `position` enum('President','Vice President','Secretary','Treasurer','Auditor','Board of Director','Superadmin') DEFAULT NULL,
  `account_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `account_issued_at` datetime DEFAULT NULL,
  `account_issued_by_admin_id` int(11) DEFAULT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
  `password_setup_token` char(64) DEFAULT NULL,
  `password_setup_expires` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admins`
--

INSERT INTO `admins`
(`id`, `homeowner_id`, `email`, `full_name`, `password`, `phase`, `role`, `position`, `account_enabled`, `account_issued_at`, `account_issued_by_admin_id`, `must_change_password`, `password_setup_token`, `password_setup_expires`)
VALUES
(1, NULL, 'superadmin@gmail.com', 'System Superadmin', '12345678', 'Superadmin', 'superadmin', 'Superadmin', 0, NULL, NULL, 0, NULL, NULL);

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

--
-- Triggers `announcement_comments`
--
DELIMITER $$
CREATE TRIGGER `trg_rt_announcement_comment_ai` AFTER INSERT ON `announcement_comments` FOR EACH ROW BEGIN
    INSERT INTO realtime_events
    (
        module_key,
        event_type,
        phase,
        entity_id,
        action,
        payload_json
    )
    SELECT
        'announcements',
        'announcement_comment',
        CASE
            WHEN a.phase = 'Superadmin' THEN NULL
            ELSE a.phase
        END,
        NEW.id,
        'created',
        JSON_OBJECT(
            'comment_id', NEW.id,
            'announcement_id', NEW.announcement_id,
            'homeowner_id', NEW.homeowner_id
        )
    FROM announcements a
    WHERE a.id = NEW.announcement_id
    LIMIT 1;
END
$$
DELIMITER ;

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
-- Table structure for table `cctv_saved_clips`
--

CREATE TABLE `cctv_saved_clips` (
  `id` int(11) NOT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3') NOT NULL,
  `camera_id` varchar(50) NOT NULL,
  `camera_name` varchar(150) NOT NULL,
  `camera_location` varchar(180) DEFAULT NULL,
  `source_type` enum('youtube_reference','video_file') NOT NULL DEFAULT 'youtube_reference',
  `source_video_id` varchar(60) DEFAULT NULL,
  `clip_title` varchar(180) NOT NULL,
  `notes` varchar(1000) DEFAULT NULL,
  `start_seconds` decimal(10,3) NOT NULL,
  `end_seconds` decimal(10,3) NOT NULL,
  `duration_seconds` decimal(10,3) NOT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `recorded_by_admin_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cctv_saved_clips`
--

-- Fresh start: data intentionally cleared from `cctv_saved_clips`.

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

--
-- Dumping data for table `complaints`
--

-- Fresh start: data intentionally cleared from `complaints`.

--
-- Triggers `complaints`
--
DELIMITER $$
CREATE TRIGGER `trg_rt_complaint_ai` AFTER INSERT ON `complaints` FOR EACH ROW BEGIN
    INSERT INTO realtime_events
    (
        module_key,
        event_type,
        phase,
        target_admin_id,
        entity_id,
        action,
        payload_json
    )
    VALUES
    (
        'complaints',
        'complaint_created',
        NEW.phase,
        NEW.admin_id,
        NEW.id,
        'created',
        JSON_OBJECT(
            'complaint_id', NEW.id,
            'homeowner_id', NEW.homeowner_id,
            'priority', NEW.priority,
            'status', NEW.status
        )
    );
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_rt_complaint_au` AFTER UPDATE ON `complaints` FOR EACH ROW BEGIN
    IF
        NOT (NEW.status <=> OLD.status)
        OR NOT (NEW.priority <=> OLD.priority)
        OR NOT (NEW.admin_id <=> OLD.admin_id)
    THEN
        INSERT INTO realtime_events
        (
            module_key,
            event_type,
            phase,
            target_admin_id,
            entity_id,
            action,
            payload_json
        )
        VALUES
        (
            'complaints',
            'complaint_state_changed',
            NEW.phase,
            NEW.admin_id,
            NEW.id,
            'updated',
            JSON_OBJECT(
                'complaint_id', NEW.id,
                'old_status', OLD.status,
                'new_status', NEW.status,
                'priority', NEW.priority
            )
        );
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `complaint_attachments`
--

CREATE TABLE `complaint_attachments` (
  `id` int(11) NOT NULL,
  `complaint_id` int(11) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `file_kind` enum('image','video') NOT NULL,
  `file_size` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `complaint_attachments`
--

-- Fresh start: data intentionally cleared from `complaint_attachments`.

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

-- Fresh start: data intentionally cleared from `complaint_messages`.

--
-- Triggers `complaint_messages`
--
DELIMITER $$
CREATE TRIGGER `trg_rt_complaint_message_ai` AFTER INSERT ON `complaint_messages` FOR EACH ROW BEGIN
    IF NEW.sender_type = 'homeowner' THEN
        INSERT INTO realtime_events
        (
            module_key,
            event_type,
            phase,
            target_admin_id,
            entity_id,
            action,
            payload_json
        )
        SELECT
            'complaints',
            'complaint_message',
            c.phase,
            c.admin_id,
            c.id,
            'message',
            JSON_OBJECT(
                'complaint_id', c.id,
                'message_id', NEW.id,
                'homeowner_id', NEW.sender_homeowner_id
            )
        FROM complaints c
        WHERE c.id = NEW.complaint_id
        LIMIT 1;
    END IF;
END
$$
DELIMITER ;

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

--
-- Triggers `facility_rental_requests`
--
DELIMITER $$
CREATE TRIGGER `trg_rt_facility_rental_ai` AFTER INSERT ON `facility_rental_requests` FOR EACH ROW BEGIN
    INSERT INTO realtime_events
    (
        module_key,
        event_type,
        phase,
        entity_id,
        action,
        payload_json
    )
    VALUES
    (
        'facility_rentals',
        'facility_rental_request',
        NEW.phase,
        NEW.id,
        'created',
        JSON_OBJECT(
            'request_id', NEW.id,
            'homeowner_id', NEW.homeowner_id,
            'facility', NEW.facility,
            'status', NEW.status
        )
    );
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_rt_facility_rental_au` AFTER UPDATE ON `facility_rental_requests` FOR EACH ROW BEGIN
    IF NOT (NEW.status <=> OLD.status) THEN
        INSERT INTO realtime_events
        (
            module_key,
            event_type,
            phase,
            entity_id,
            action,
            payload_json
        )
        VALUES
        (
            'facility_rentals',
            'facility_rental_state_changed',
            NEW.phase,
            NEW.id,
            'updated',
            JSON_OBJECT(
                'request_id', NEW.id,
                'old_status', OLD.status,
                'new_status', NEW.status
            )
        );
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `finance_audit_logs`
--

CREATE TABLE `finance_audit_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `phase` varchar(50) DEFAULT NULL,
  `action` varchar(150) NOT NULL,
  `entity_type` varchar(100) NOT NULL,
  `entity_id` bigint(20) DEFAULT NULL,
  `batch_id` varchar(64) DEFAULT NULL,
  `details` varchar(1000) DEFAULT NULL,
  `before_data` longtext DEFAULT NULL,
  `after_data` longtext DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `finance_audit_logs`
--

-- Fresh start: data intentionally cleared from `finance_audit_logs`.

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
-- Table structure for table `finance_dues_import_queue`
--

CREATE TABLE `finance_dues_import_queue` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `batch_id` varchar(64) NOT NULL,
  `source_row` int(11) NOT NULL,
  `source_filename` varchar(255) DEFAULT NULL,
  `raw_row_data` longtext DEFAULT NULL,
  `homeowner_name` varchar(255) NOT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3') NOT NULL,
  `block` varchar(50) NOT NULL,
  `lot` varchar(50) NOT NULL,
  `street_address` varchar(255) DEFAULT NULL,
  `mobile_number` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `pay_year` int(11) NOT NULL,
  `pay_month` tinyint(4) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `paid_at` datetime NOT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `source_reference` varchar(255) DEFAULT NULL,
  `matched_homeowner_id` int(11) DEFAULT NULL,
  `match_method` varchar(100) DEFAULT NULL,
  `match_score` int(11) NOT NULL DEFAULT 0,
  `match_note` varchar(255) DEFAULT NULL,
  `existing_payment_id` int(11) DEFAULT NULL,
  `finalized_payment_id` int(11) DEFAULT NULL,
  `status` enum('ready','duplicate','conflict','unmatched','needs_review','finalized','skipped') NOT NULL DEFAULT 'unmatched',
  `imported_by_admin_id` int(11) NOT NULL,
  `imported_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `finalized_by_admin_id` int(11) DEFAULT NULL,
  `finalized_at` datetime DEFAULT NULL,
  `is_archived` tinyint(1) NOT NULL DEFAULT 0,
  `archived_by_admin_id` int(11) DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  `archive_reason` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `finance_dues_reminders`
--

CREATE TABLE `finance_dues_reminders` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `homeowner_id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `pay_year` smallint(5) UNSIGNED NOT NULL,
  `pay_month` tinyint(3) UNSIGNED NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('sent','failed') NOT NULL DEFAULT 'failed',
  `attempts` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `last_error` varchar(1000) DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
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

INSERT INTO `finance_dues_settings`
(`id`, `phase`, `monthly_dues`, `updated_by_admin_id`, `updated_at`)
VALUES
(1, 'Phase 1', 0.00, NULL, CURRENT_TIMESTAMP),
(2, 'Phase 2', 0.00, NULL, CURRENT_TIMESTAMP),
(3, 'Phase 3', 0.00, NULL, CURRENT_TIMESTAMP);

-- --------------------------------------------------------

--
-- Table structure for table `finance_expenses`
--

CREATE TABLE `finance_expenses` (
  `id` int(11) NOT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3') NOT NULL,
  `category` enum('maintenance','security','utilities','other') NOT NULL DEFAULT 'other',
  `detail_type` enum('general','tools_materials') NOT NULL DEFAULT 'general',
  `vendor_payee` varchar(150) DEFAULT NULL,
  `requested_by` varchar(150) DEFAULT NULL,
  `project_name` varchar(180) DEFAULT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `payment_method` enum('cash','gcash','bank_transfer','check','other') DEFAULT NULL,
  `description` varchar(255) NOT NULL,
  `notes` text DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `expense_date` date NOT NULL,
  `receipt_path` varchar(255) DEFAULT NULL,
  `proof_original_name` varchar(255) DEFAULT NULL,
  `proof_mime` varchar(100) DEFAULT NULL,
  `proof_size_bytes` int(10) UNSIGNED DEFAULT NULL,
  `created_by_admin_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `finance_expenses`
--

-- Fresh start: data intentionally cleared from `finance_expenses`.

-- --------------------------------------------------------

--
-- Table structure for table `finance_expense_items`
--

CREATE TABLE `finance_expense_items` (
  `id` int(11) NOT NULL,
  `expense_id` int(11) NOT NULL,
  `item_name` varchar(150) NOT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT 1.00,
  `unit` varchar(30) NOT NULL DEFAULT 'pc',
  `unit_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `notes` varchar(255) DEFAULT NULL,
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

INSERT INTO `finance_opening_balance`
(`id`, `phase`, `opening_balance`, `as_of`, `updated_by_admin_id`, `updated_at`)
VALUES
(1, 'Phase 1', 0.00, CURRENT_DATE, NULL, CURRENT_TIMESTAMP),
(2, 'Phase 2', 0.00, CURRENT_DATE, NULL, CURRENT_TIMESTAMP),
(3, 'Phase 3', 0.00, CURRENT_DATE, NULL, CURRENT_TIMESTAMP);

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
-- Triggers `finance_payments`
--
DELIMITER $$
CREATE TRIGGER `trg_rt_finance_payment_ai` AFTER INSERT ON `finance_payments` FOR EACH ROW BEGIN
    IF NEW.status = 'paid' THEN
        INSERT INTO realtime_events
        (
            module_key,
            event_type,
            phase,
            entity_id,
            action,
            payload_json
        )
        VALUES
        (
            'finance_dues',
            'monthly_due_paid',
            NEW.phase,
            NEW.id,
            'created',
            JSON_OBJECT(
                'payment_id', NEW.id,
                'homeowner_id', NEW.homeowner_id,
                'pay_year', NEW.pay_year,
'pay_month', NEW.pay_month,
                'amount', NEW.amount,
                'reference_no', NEW.reference_no
            )
        );
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_rt_finance_payment_au` AFTER UPDATE ON `finance_payments` FOR EACH ROW BEGIN
    IF
        OLD.status <> 'paid'
        AND NEW.status = 'paid'
    THEN
        INSERT INTO realtime_events
        (
            module_key,
            event_type,
            phase,
            entity_id,
            action,
            payload_json
        )
        VALUES
        (
            'finance_dues',
            'monthly_due_paid',
            NEW.phase,
            NEW.id,
            'paid',
            JSON_OBJECT(
                'payment_id', NEW.id,
                'homeowner_id', NEW.homeowner_id,
                'pay_year', NEW.pay_year,
                'pay_month', NEW.pay_month,
                'amount', NEW.amount,
                'reference_no', NEW.reference_no
            )
        );
    END IF;
END
$$
DELIMITER ;

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
  `report_type` varchar(40) NOT NULL DEFAULT 'full_summary',
  `report_title` varchar(180) DEFAULT NULL,
  `report_year` int(11) NOT NULL,
  `report_month` tinyint(4) NOT NULL,
  `date_from` date DEFAULT NULL,
  `date_to` date DEFAULT NULL,
  `target_type` varchar(40) DEFAULT NULL,
  `target_id` int(11) DEFAULT NULL,
  `project_name` varchar(180) DEFAULT NULL,
  `request_purpose` varchar(500) DEFAULT NULL,
  `scope_hash` char(64) DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `requested_by_admin_id` int(11) DEFAULT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `president_approved_by_email` varchar(255) DEFAULT NULL,
  `president_action_at` datetime DEFAULT NULL,
  `president_remarks` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `finance_report_requests`
--
DELIMITER $$
CREATE TRIGGER `trg_rt_finance_report_ai` AFTER INSERT ON `finance_report_requests` FOR EACH ROW BEGIN
    INSERT INTO realtime_events
    (
        module_key,
        event_type,
        phase,
        entity_id,
        action,
        payload_json
    )
    VALUES
    (
        'finance_reports',
        'finance_report_requested',
        NEW.phase,
        NEW.id,
        'created',
        JSON_OBJECT(
            'report_request_id', NEW.id,
            'status', NEW.status,
            'report_year', NEW.report_year,
            'report_month', NEW.report_month
        )
    );
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_rt_finance_report_au` AFTER UPDATE ON `finance_report_requests` FOR EACH ROW BEGIN
    IF NOT (NEW.status <=> OLD.status) THEN
        INSERT INTO realtime_events
        (
            module_key,
            event_type,
            phase,
            entity_id,
            action,
            payload_json
        )
        VALUES
        (
            'finance_reports',
            'finance_report_state_changed',
            NEW.phase,
            NEW.id,
            'updated',
            JSON_OBJECT(
                'report_request_id', NEW.id,
                'old_status', OLD.status,
                'new_status', NEW.status
            )
        );
    END IF;
END
$$
DELIMITER ;

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
  `homeowner_id` int(11) DEFAULT NULL,
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

-- Fresh start: data intentionally cleared from `hoa_officers`.

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
  `status` enum('pending','approved','rejected','former') DEFAULT 'pending',
  `admin_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `homeowners`
--

-- Fresh start: data intentionally cleared from `homeowners`.

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
DELIMITER $$
CREATE TRIGGER `trg_rt_homeowner_pending_ai` AFTER INSERT ON `homeowners` FOR EACH ROW BEGIN
    IF NEW.status = 'pending' THEN
        INSERT INTO realtime_events
        (
            module_key,
            event_type,
            phase,
            entity_id,
            action,
            payload_json
        )
        VALUES
        (
            'homeowner_push',
            CASE
                WHEN COALESCE(NEW.valid_id_path, '') LIKE 'imports/%'
                    THEN 'import_waiting_for_push'
                ELSE 'registered_household_waiting_for_review'
            END,
            NEW.phase,
            NEW.id,
            'created',
            JSON_OBJECT(
                'homeowner_id', NEW.id,
                'status', NEW.status,
                'source',
                    CASE
                        WHEN COALESCE(NEW.valid_id_path, '') LIKE 'imports/%'
                            THEN 'import'
                        ELSE 'register_household'
                    END
            )
        );
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_rt_homeowner_pending_au` AFTER UPDATE ON `homeowners` FOR EACH ROW BEGIN
    IF
        NOT (NEW.status <=> OLD.status)
        OR NOT (NEW.valid_id_path <=> OLD.valid_id_path)
        OR NOT (NEW.proof_of_billing_path <=> OLD.proof_of_billing_path)
    THEN
        INSERT INTO realtime_events
        (
            module_key,
            event_type,
            phase,
            entity_id,
            action,
            payload_json
        )
        VALUES
        (
            'homeowner_push',
            CASE
                WHEN
                    NEW.status <=> OLD.status
                    AND (
                        NOT (NEW.valid_id_path <=> OLD.valid_id_path)
                        OR NOT (
                            NEW.proof_of_billing_path
                            <=>
                            OLD.proof_of_billing_path
                        )
                    )
                THEN 'homeowner_documents_updated'
                ELSE 'homeowner_review_state_changed'
            END,
            NEW.phase,
            NEW.id,
            CASE
                WHEN
                    NEW.status <=> OLD.status
                    AND (
                        NOT (NEW.valid_id_path <=> OLD.valid_id_path)
                        OR NOT (
                            NEW.proof_of_billing_path
                            <=>
                            OLD.proof_of_billing_path
                        )
                    )
                THEN 'documents'
                ELSE 'updated'
            END,
            JSON_OBJECT(
                'homeowner_id', NEW.id,
                'old_status', OLD.status,
                'new_status', NEW.status,
                'valid_id_changed',
                    NOT (
                        NEW.valid_id_path
                        <=>
                        OLD.valid_id_path
                    ),
                'proof_of_billing_changed',
                    NOT (
                        NEW.proof_of_billing_path
                        <=>
                        OLD.proof_of_billing_path
                    ),
                'source',
                    CASE
                        WHEN COALESCE(NEW.valid_id_path, '') LIKE 'imports/%'
                            THEN 'import'
                        ELSE 'homeowner_record'
                    END
            )
        );
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

-- Fresh start: data intentionally cleared from `homeowner_feed_state`.

-- --------------------------------------------------------

--
-- Table structure for table `homeowner_import_archive`
--

CREATE TABLE `homeowner_import_archive` (
  `id` int(10) UNSIGNED NOT NULL,
  `source_queue_id` int(11) NOT NULL,
  `existing_homeowner_id` int(11) DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `contact_number` varchar(50) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `phase` varchar(50) DEFAULT NULL,
  `block` int(11) DEFAULT NULL,
  `lot` int(11) DEFAULT NULL,
  `street` varchar(190) DEFAULT NULL,
  `residential_type` varchar(100) DEFAULT NULL,
  `existing_email` varchar(190) DEFAULT NULL,
  `archived_by_admin_id` int(11) DEFAULT NULL,
  `archived_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `homeowner_import_archive`
--

-- Fresh start: data intentionally cleared from `homeowner_import_archive`.

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

--
-- Dumping data for table `homeowner_import_queue`
--

-- Fresh start: data intentionally cleared from `homeowner_import_queue`.

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
-- Triggers `homeowner_officer_messages`
--
DELIMITER $$
CREATE TRIGGER `trg_rt_officer_chat_ai` AFTER INSERT ON `homeowner_officer_messages` FOR EACH ROW BEGIN
    IF NEW.sender_type = 'homeowner' THEN
        INSERT INTO realtime_events
        (
            module_key,
            event_type,
            phase,
            target_admin_id,
            entity_id,
            action,
            payload_json
        )
        VALUES
        (
            'community_chat',
            'private_chat_message',
            NEW.phase,
            NEW.admin_id,
            NEW.id,
            'created',
            JSON_OBJECT(
                'message_id', NEW.id,
                'homeowner_id', NEW.homeowner_id,
                'admin_id', NEW.admin_id
            )
        );
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `homeowner_ownership_transfers`
--

CREATE TABLE `homeowner_ownership_transfers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `source_type` enum('import_queue','pending_homeowner') NOT NULL DEFAULT 'import_queue',
  `source_queue_id` int(11) DEFAULT NULL,
  `source_homeowner_id` int(11) DEFAULT NULL,
  `previous_homeowner_id` int(11) NOT NULL,
  `new_homeowner_id` int(11) DEFAULT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3') NOT NULL,
  `block` varchar(50) NOT NULL,
  `lot` varchar(50) NOT NULL,
  `old_email` varchar(255) NOT NULL,
  `new_email` varchar(255) NOT NULL,
  `old_token_hash` char(64) DEFAULT NULL,
  `new_token_hash` char(64) DEFAULT NULL,
  `token_expires_at` datetime DEFAULT NULL,
  `old_confirmation` enum('pending','confirmed','denied','manual_verified') NOT NULL DEFAULT 'pending',
  `new_confirmation` enum('pending','confirmed','denied') NOT NULL DEFAULT 'pending',
  `status` enum('awaiting_confirmation','ready_for_admin','completed','cancelled','denied') NOT NULL DEFAULT 'awaiting_confirmation',
  `old_confirmed_at` datetime DEFAULT NULL,
  `new_confirmed_at` datetime DEFAULT NULL,
  `old_manual_verified_by_admin_id` int(11) DEFAULT NULL,
  `old_manual_verified_at` datetime DEFAULT NULL,
  `documents_verified` tinyint(1) NOT NULL DEFAULT 0,
  `verification_method` enum('email','office','documents','mixed') DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `initiated_by_admin_id` int(11) NOT NULL,
  `initiated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_by_admin_id` int(11) DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `homeowner_ownership_transfers`
--

-- Fresh start: data intentionally cleared from `homeowner_ownership_transfers`.

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

-- Fresh start: data intentionally cleared from `homeowner_positions`.

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
  `relation` enum('Homeowner','Spouse','Child','Parent','Relative','Tenant','Caretaker') NOT NULL,
  `relationship_detail` varchar(50) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `household_members`
--

-- Fresh start: data intentionally cleared from `household_members`.

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

--
-- Dumping data for table `login_security_appeals`
--

-- Fresh start: data intentionally cleared from `login_security_appeals`.

--
-- Triggers `login_security_appeals`
--
DELIMITER $$
CREATE TRIGGER `trg_rt_login_appeal_ai` AFTER INSERT ON `login_security_appeals` FOR EACH ROW BEGIN
    INSERT INTO realtime_events
    (
        module_key,
        event_type,
        phase,
        entity_id,
        action,
        payload_json
    )
    SELECT
        'login_security',
        'security_appeal_created',
        s.phase,
        NEW.id,
        'created',
        JSON_OBJECT(
            'appeal_id', NEW.id,
            'security_state_id', NEW.security_state_id,
            'status', NEW.status
        )
    FROM login_security_state s
    WHERE s.id = NEW.security_state_id
    LIMIT 1;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_rt_login_appeal_au` AFTER UPDATE ON `login_security_appeals` FOR EACH ROW BEGIN
    IF NOT (NEW.status <=> OLD.status) THEN
        INSERT INTO realtime_events
        (
            module_key,
            event_type,
            phase,
            entity_id,
            action,
            payload_json
        )
        SELECT
            'login_security',
            'security_appeal_state_changed',
            s.phase,
            NEW.id,
            'updated',
            JSON_OBJECT(
                'appeal_id', NEW.id,
                'security_state_id', NEW.security_state_id,
                'old_status', OLD.status,
                'new_status', NEW.status
            )
        FROM login_security_state s
        WHERE s.id = NEW.security_state_id
        LIMIT 1;
    END IF;
END
$$
DELIMITER ;

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
-- Fresh start: data intentionally cleared from `login_security_state`.

--
-- Triggers `login_security_state`
--
DELIMITER $$
CREATE TRIGGER `trg_rt_login_security_ai` AFTER INSERT ON `login_security_state` FOR EACH ROW BEGIN
    IF NEW.hard_locked = 1 THEN
        INSERT INTO realtime_events
        (
            module_key,
            event_type,
            phase,
            entity_id,
            action,
            payload_json
        )
        VALUES
        (
            'login_security',
            'account_hard_locked',
            NEW.phase,
            NEW.id,
            'created',
            JSON_OBJECT(
                'security_state_id', NEW.id,
                'account_type', NEW.account_type,
                'account_id', NEW.account_id
            )
        );
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_rt_login_security_au` AFTER UPDATE ON `login_security_state` FOR EACH ROW BEGIN
    IF
        NOT (NEW.hard_locked <=> OLD.hard_locked)
        OR NOT (NEW.unlocked_at <=> OLD.unlocked_at)
        OR NOT (NEW.failed_attempts <=> OLD.failed_attempts)
    THEN
        INSERT INTO realtime_events
        (
            module_key,
            event_type,
            phase,
            entity_id,
            action,
            payload_json
        )
        VALUES
        (
            'login_security',
            'security_state_changed',
            NEW.phase,
            NEW.id,
            'updated',
            JSON_OBJECT(
                'security_state_id', NEW.id,
                'hard_locked', NEW.hard_locked,
                'failed_attempts', NEW.failed_attempts
            )
        );
    END IF;
END
$$
DELIMITER ;

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

--
-- Triggers `parking_permits`
--
DELIMITER $$
CREATE TRIGGER `trg_rt_parking_permit_ai` AFTER INSERT ON `parking_permits` FOR EACH ROW BEGIN
    INSERT INTO realtime_events
    (
        module_key,
        event_type,
        phase,
        entity_id,
        action,
        payload_json
    )
    VALUES
    (
        'parking',
        'parking_permit_request',
        NEW.phase,
        NEW.id,
        'created',
        JSON_OBJECT(
            'permit_id', NEW.id,
            'homeowner_id', NEW.homeowner_id,
            'request_type', NEW.request_type,
            'plate_no', NEW.plate_no,
            'status', NEW.status,
            'payment_status', NEW.payment_status
        )
    );
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_rt_parking_permit_au` AFTER UPDATE ON `parking_permits` FOR EACH ROW BEGIN
    IF
        NOT (NEW.status <=> OLD.status)
        OR NOT (NEW.payment_status <=> OLD.payment_status)
    THEN
        INSERT INTO realtime_events
        (
            module_key,
            event_type,
            phase,
            entity_id,
            action,
            payload_json
        )
        VALUES
        (
            'parking',
            'parking_permit_state_changed',
            NEW.phase,
            NEW.id,
            'updated',
            JSON_OBJECT(
                'permit_id', NEW.id,
                'old_status', OLD.status,
                'new_status', NEW.status,
                'payment_status', NEW.payment_status
            )
        );
    END IF;
END
$$
DELIMITER ;

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

-- Fresh start: data intentionally cleared from `public_chat_messages`.

--
-- Triggers `public_chat_messages`
--
DELIMITER $$
CREATE TRIGGER `trg_rt_public_chat_ai` AFTER INSERT ON `public_chat_messages` FOR EACH ROW BEGIN
    INSERT INTO realtime_events
    (
        module_key,
        event_type,
        phase,
        target_admin_id,
        entity_id,
        action,
        payload_json
    )
    VALUES
    (
        'community_chat',
        'public_chat_message',
        NEW.phase,
        NULL,
        NEW.id,
        'created',
        JSON_OBJECT(
            'message_id', NEW.id,
            'homeowner_id', NEW.homeowner_id
        )
    );
END
$$
DELIMITER ;

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
-- Table structure for table `realtime_admin_state`
--

CREATE TABLE `realtime_admin_state` (
  `admin_id` int(11) NOT NULL,
  `module_key` varchar(64) NOT NULL,
  `last_seen_event_id` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `realtime_admin_state`
--

-- Fresh start: data intentionally cleared from `realtime_admin_state`.

-- --------------------------------------------------------

--
-- Table structure for table `realtime_events`
--

CREATE TABLE `realtime_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `module_key` varchar(64) NOT NULL,
  `event_type` varchar(80) NOT NULL,
  `phase` varchar(30) DEFAULT NULL,
  `target_admin_id` int(11) DEFAULT NULL,
  `entity_id` bigint(20) DEFAULT NULL,
  `action` varchar(40) NOT NULL DEFAULT 'created',
  `payload_json` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `realtime_events`
--

-- Fresh start: data intentionally cleared from `realtime_events`.

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
-- Triggers `staff_applications`
--
DELIMITER $$
CREATE TRIGGER `trg_rt_staff_application_ai` AFTER INSERT ON `staff_applications` FOR EACH ROW BEGIN
    INSERT INTO realtime_events
    (
        module_key,
        event_type,
        phase,
        target_admin_id,
        entity_id,
        action,
        payload_json
    )
    VALUES
    (
        'staff_pending',
        'staff_application_created',
        NEW.phase,
        NEW.president_admin_id,
        NEW.id,
        'created',
        JSON_OBJECT(
            'application_id', NEW.id,
            'status', NEW.status,
            'staff_type', NEW.staff_type
        )
    );
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_rt_staff_application_au` AFTER UPDATE ON `staff_applications` FOR EACH ROW BEGIN
    IF
        NOT (NEW.status <=> OLD.status)
        OR NOT (NEW.president_admin_id <=> OLD.president_admin_id)
    THEN
        INSERT INTO realtime_events
        (
            module_key,
            event_type,
            phase,
            target_admin_id,
            entity_id,
            action,
            payload_json
        )
        VALUES
        (
            'staff_pending',
            'staff_application_state_changed',
            NEW.phase,
            NEW.president_admin_id,
            NEW.id,
            'updated',
            JSON_OBJECT(
                'application_id', NEW.id,
                'old_status', OLD.status,
                'new_status', NEW.status
            )
        );
    END IF;
END
$$
DELIMITER ;

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

-- --------------------------------------------------------

--
-- Table structure for table `voting_requests`
--

CREATE TABLE `voting_requests` (
  `id` int(11) NOT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3') NOT NULL,
  `requested_by_admin_id` int(11) NOT NULL,
  `election_title` varchar(255) NOT NULL,
  `reason` varchar(500) NOT NULL,
  `status` enum('pending','processed','rejected') NOT NULL DEFAULT 'pending',
  `processed_by_admin_id` int(11) DEFAULT NULL,
  `election_session_id` int(11) DEFAULT NULL,
  `superadmin_remarks` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `processed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- --------------------------------------------------------
--
-- Table structure for table `voting_request_candidates`
-- Added from the latest President -> Superadmin voting workflow.
--

CREATE TABLE `voting_request_candidates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `voting_request_id` int(11) NOT NULL,
  `phase` enum('Phase 1','Phase 2','Phase 3') NOT NULL,
  `position` enum('President','Vice President','Secretary','Treasurer','Auditor','Board of Director') NOT NULL,
  `homeowner_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_voting_request_candidate` (`voting_request_id`,`position`,`homeowner_id`),
  KEY `idx_vrc_request` (`voting_request_id`),
  KEY `idx_vrc_phase` (`phase`),
  KEY `idx_vrc_position` (`position`),
  KEY `idx_vrc_homeowner` (`homeowner_id`)
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
  ADD UNIQUE KEY `uniq_admin_email` (`email`),
  ADD KEY `idx_admins_homeowner` (`homeowner_id`),
  ADD KEY `idx_admin_account_enabled` (`account_enabled`),
  ADD KEY `idx_admin_homeowner_position` (`homeowner_id`,`phase`,`position`),
  ADD KEY `idx_admin_password_setup_token` (`password_setup_token`);

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
-- Indexes for table `cctv_saved_clips`
--
ALTER TABLE `cctv_saved_clips`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cctv_clips_phase_created` (`phase`,`created_at`),
  ADD KEY `idx_cctv_clips_camera` (`phase`,`camera_id`,`created_at`),
  ADD KEY `idx_cctv_clips_admin` (`recorded_by_admin_id`);

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
-- Indexes for table `complaint_attachments`
--
ALTER TABLE `complaint_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_complaint_attachments_complaint_id` (`complaint_id`);

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
-- Indexes for table `finance_audit_logs`
--
ALTER TABLE `finance_audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_finance_audit_phase_created` (`phase`,`created_at`),
  ADD KEY `idx_finance_audit_entity` (`entity_type`,`entity_id`),
  ADD KEY `idx_finance_audit_admin` (`admin_id`),
  ADD KEY `idx_finance_audit_batch` (`batch_id`);

--
-- Indexes for table `finance_donations`
--
ALTER TABLE `finance_donations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_phase_date` (`phase`,`donation_date`),
  ADD KEY `fk_don_admin` (`created_by_admin_id`),
  ADD KEY `idx_finance_donations_homeowner` (`homeowner_id`);

--
-- Indexes for table `finance_dues_import_queue`
--
ALTER TABLE `finance_dues_import_queue`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_finance_dues_import_batch_row` (`batch_id`,`source_row`),
  ADD KEY `idx_finance_dues_import_phase_status` (`phase`,`status`),
  ADD KEY `idx_finance_dues_import_homeowner` (`matched_homeowner_id`),
  ADD KEY `idx_finance_dues_import_period` (`phase`,`pay_year`,`pay_month`),
  ADD KEY `idx_finance_dues_import_batch` (`batch_id`);

--
-- Indexes for table `finance_dues_reminders`
--
ALTER TABLE `finance_dues_reminders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_dues_reminder_month` (`homeowner_id`,`pay_year`,`pay_month`),
  ADD KEY `idx_dues_reminder_status` (`status`),
  ADD KEY `idx_dues_reminder_period` (`pay_year`,`pay_month`);

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
  ADD KEY `fk_exp_admin` (`created_by_admin_id`),
  ADD KEY `idx_fin_exp_project` (`phase`,`project_name`,`expense_date`);

--
-- Indexes for table `finance_expense_items`
--
ALTER TABLE `finance_expense_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_finance_expense_items_expense` (`expense_id`);

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
  ADD KEY `fk_rep_admin` (`requested_by_admin_id`),
  ADD KEY `idx_fin_report_phase_status` (`phase`,`status`),
  ADD KEY `idx_fin_report_type` (`phase`,`report_type`),
  ADD KEY `idx_fin_report_scope_hash` (`phase`,`scope_hash`,`status`);

--
-- Indexes for table `finance_report_snapshots`
--
ALTER TABLE `finance_report_snapshots`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_finance_snapshot_request` (`report_request_id`);

--
-- Indexes for table `hoa_officers`
--
ALTER TABLE `hoa_officers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_phase_position_name` (`phase`,`position`,`officer_name`),
  ADD KEY `idx_hoa_officers_homeowner` (`homeowner_id`);

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
-- Indexes for table `homeowner_import_archive`
--
ALTER TABLE `homeowner_import_archive`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_homeowner_import_archive_source` (`source_queue_id`),
  ADD KEY `idx_homeowner_import_archive_phase` (`phase`),
  ADD KEY `idx_homeowner_import_archive_archived_at` (`archived_at`);

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
-- Indexes for table `homeowner_ownership_transfers`
--
ALTER TABLE `homeowner_ownership_transfers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_ownership_transfer_queue` (`source_queue_id`),
  ADD UNIQUE KEY `uq_ownership_transfer_pending_homeowner` (`source_homeowner_id`),
  ADD KEY `idx_ownership_transfer_previous` (`previous_homeowner_id`),
  ADD KEY `idx_ownership_transfer_new` (`new_homeowner_id`),
  ADD KEY `idx_ownership_transfer_property` (`phase`,`block`,`lot`),
  ADD KEY `idx_ownership_transfer_status` (`status`),
  ADD KEY `idx_ownership_transfer_initiated` (`initiated_at`);

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
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_household_members_homeowner` (`homeowner_id`);

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
-- Indexes for table `realtime_admin_state`
--
ALTER TABLE `realtime_admin_state`
  ADD PRIMARY KEY (`admin_id`,`module_key`),
  ADD KEY `idx_rt_state_seen` (`last_seen_event_id`);

--
-- Indexes for table `realtime_events`
--
ALTER TABLE `realtime_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_rt_module_id` (`module_key`,`id`),
  ADD KEY `idx_rt_phase_id` (`phase`,`id`),
  ADD KEY `idx_rt_target_id` (`target_admin_id`,`id`),
  ADD KEY `idx_rt_created_at` (`created_at`);

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
-- Indexes for table `voting_requests`
--
ALTER TABLE `voting_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_voting_requests_phase_status` (`phase`,`status`),
  ADD KEY `idx_voting_requests_requested_by` (`requested_by_admin_id`),
  ADD KEY `idx_voting_requests_processed_by` (`processed_by_admin_id`),
  ADD KEY `idx_voting_requests_session` (`election_session_id`),
  ADD KEY `idx_voting_requests_created_at` (`created_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `access_permissions`
--
ALTER TABLE `access_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3607;

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

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
-- AUTO_INCREMENT for table `cctv_saved_clips`
--
ALTER TABLE `cctv_saved_clips`
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
-- AUTO_INCREMENT for table `complaint_attachments`
--
ALTER TABLE `complaint_attachments`
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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `facility_rental_requests`
--
ALTER TABLE `facility_rental_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `finance_audit_logs`
--
ALTER TABLE `finance_audit_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `finance_donations`
--
ALTER TABLE `finance_donations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `finance_dues_import_queue`
--
ALTER TABLE `finance_dues_import_queue`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `finance_dues_reminders`
--
ALTER TABLE `finance_dues_reminders`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

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
-- AUTO_INCREMENT for table `finance_expense_items`
--
ALTER TABLE `finance_expense_items`
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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `homeowners`
--
ALTER TABLE `homeowners`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

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
-- AUTO_INCREMENT for table `homeowner_import_archive`
--
ALTER TABLE `homeowner_import_archive`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

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
-- AUTO_INCREMENT for table `homeowner_ownership_transfers`
--
ALTER TABLE `homeowner_ownership_transfers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `homeowner_positions`
--
ALTER TABLE `homeowner_positions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `homeowner_private_messages`
--
ALTER TABLE `homeowner_private_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `household_members`
--
ALTER TABLE `household_members`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `login_security_appeals`
--
ALTER TABLE `login_security_appeals`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `login_security_state`
--
ALTER TABLE `login_security_state`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

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
-- AUTO_INCREMENT for table `realtime_events`
--
ALTER TABLE `realtime_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

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
-- AUTO_INCREMENT for table `voting_requests`
--
ALTER TABLE `voting_requests`
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
-- Constraints for table `admins`
--
ALTER TABLE `admins`
  ADD CONSTRAINT `fk_admins_homeowner` FOREIGN KEY (`homeowner_id`) REFERENCES `homeowners` (`id`) ON DELETE SET NULL;

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
-- Constraints for table `finance_dues_reminders`
--
ALTER TABLE `finance_dues_reminders`
  ADD CONSTRAINT `fk_dues_reminder_homeowner` FOREIGN KEY (`homeowner_id`) REFERENCES `homeowners` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

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
-- Constraints for table `finance_expense_items`
--
ALTER TABLE `finance_expense_items`
  ADD CONSTRAINT `fk_finance_expense_items_expense` FOREIGN KEY (`expense_id`) REFERENCES `finance_expenses` (`id`) ON DELETE CASCADE;

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
-- Constraints for table `hoa_officers`
--
ALTER TABLE `hoa_officers`
  ADD CONSTRAINT `fk_hoa_officers_homeowner` FOREIGN KEY (`homeowner_id`) REFERENCES `homeowners` (`id`) ON DELETE SET NULL;

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
