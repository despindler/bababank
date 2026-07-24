-- phpMyAdmin SQL Dump
-- version 4.9.6
-- https://www.phpmyadmin.net/
--
-- Host: e93ud.myd.infomaniak.com
-- Generation Time: Jul 24, 2026 at 10:18 AM
-- Server version: 10.6.18-MariaDB-deb11-log
-- PHP Version: 7.4.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `e93ud_bank`
--

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` int(11) NOT NULL,
  `fullname` varchar(256) NOT NULL,
  `username` varchar(128) NOT NULL,
  `userpassword` varchar(128) NOT NULL,
  `google_sub` varchar(64) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `display_name` varchar(255) DEFAULT NULL,
  `boss` tinyint(4) NOT NULL DEFAULT 0,
  `realm` int(11) NOT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`id`, `fullname`, `username`, `userpassword`, `google_sub`, `email`, `display_name`, `boss`, `realm`, `deleted_at`) VALUES
(1, 'Alexandre de Spindler', 'aldespin', '$2y$10$55KQTwuVJF86Iapq6AWpSuN0J5XT5c.4SkFrew0wRPvd6bqkPPbDi', '110331299757487527147', 'despindler@gmail.com', 'Alexandre de Spindler', 1, 1, NULL),
(2, 'Lyan de Spindler', 'lyx', '$2y$10$dIFn7bqtSGLYMZeYPmgo5uNCpYin5neas8gwG2oGS8QT2A33/DYse', '108432745890159396312', 'lyan.de.spindler@gmail.com', 'Lyan de Spindler', 0, 1, NULL),
(3, 'Shoya von Spindler', 'shox', '$2y$10$stWqUa2lgAuGfPm.FcsijOQM8s1DpJ6a9KJVWUarV0L42/Mky7Xvy', NULL, 'shoya.de.spindler@gmail.com', NULL, 0, 1, NULL),
(4, 'Nael de Spindler', 'nax', '$2y$10$tAaLS3.UsC0T70MXMmmQ4eE8bw2fJ1nCdBjvoNwVPqvuXBSwThwmu', '114889809336423497549', 'nael.de.spindler@gmail.com', 'Nael de Spindler', 0, 1, NULL),
(5, 'Devin de Spindler', 'vipx', '$2y$10$wD6kQ97A3ie253xOmxSQ/ejQ9nspVyD/ths4s2OJsqUYzh4GE/rSK', '111287517045760965479', 'devin.de.spindler@gmail.com', 'Devin', 0, 1, NULL),
(6, 'Bernadette Siebertz', 'berni', '$2y$10$z0lBvTjFehU1IsIxSHaXueg4ycljHm7tvPbjQrE9g.jrDp2S15He6', NULL, NULL, NULL, 1, 2, NULL),
(7, 'Luca de Spindler', 'lux', '$2y$10$hgqOu5kz.2MPb3O/csijMu.TG.ckTzPdU5ty9I62gKv3frg0Vg40C', NULL, NULL, NULL, 0, 1, NULL),
(8, 'Fabienne de Spindler', 'fax', '$2y$10$ab7FqfarcB/E6WOcOtxhzeWP6fPpRr1OevPaU52n3vOqSp2yvC.3q', NULL, NULL, NULL, 0, 1, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `customer_reward_state`
--

CREATE TABLE `customer_reward_state` (
  `customer` int(11) NOT NULL,
  `state_key` varchar(64) NOT NULL,
  `state_value` varchar(255) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `customer_reward_state`
--

INSERT INTO `customer_reward_state` (`customer`, `state_key`, `state_value`, `updated_at`) VALUES
(1, 'last_daily_chest_date', '2026-06-03', '2026-06-03 09:01:48'),
(1, 'monthly_interest_period', '2026-06', '2026-06-03 09:01:48'),
(2, 'input_lead_active', '1', '2026-06-03 09:01:17'),
(2, 'last_daily_chest_date', '2026-07-17', '2026-07-17 12:53:41'),
(2, 'monthly_interest_period', '2026-07', '2026-07-17 12:53:41'),
(2, 'savings_level', '1', '2026-07-10 17:54:10'),
(3, 'input_lead_active', '1', '2026-06-03 09:01:17'),
(3, 'savings_level', '1', '2026-07-16 10:01:28'),
(4, 'input_lead_active', '1', '2026-06-03 09:01:17'),
(4, 'last_daily_chest_date', '2026-07-21', '2026-07-21 08:47:08'),
(4, 'monthly_interest_period', '2026-07', '2026-07-09 18:17:38'),
(4, 'savings_level', '0', '2026-07-22 13:31:26'),
(5, 'input_lead_active', '1', '2026-06-03 09:01:17'),
(5, 'last_daily_chest_date', '2026-06-11', '2026-06-11 21:24:57'),
(5, 'monthly_interest_period', '2026-06', '2026-06-11 21:24:57'),
(5, 'savings_level', '1', '2026-06-03 09:01:17'),
(7, 'input_lead_active', '1', '2026-06-03 09:04:14'),
(7, 'last_daily_chest_date', '2026-07-24', '2026-07-24 07:31:28'),
(7, 'monthly_interest_period', '2026-07', '2026-07-24 07:31:27'),
(7, 'savings_level', '0', '2026-06-03 11:07:31'),
(8, 'input_lead_active', '1', '2026-06-03 11:07:06'),
(8, 'last_daily_chest_date', '2026-07-24', '2026-07-24 07:32:12'),
(8, 'monthly_interest_period', '2026-07', '2026-07-24 07:32:12'),
(8, 'savings_level', '0', '2026-06-03 10:51:57');

-- --------------------------------------------------------

--
-- Table structure for table `leases`
--

CREATE TABLE `leases` (
  `id` int(11) NOT NULL,
  `customer` int(11) NOT NULL,
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  `lease` varchar(128) NOT NULL,
  `valid` tinyint(4) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reward_config`
--

CREATE TABLE `reward_config` (
  `config_key` varchar(64) NOT NULL,
  `config_value` varchar(255) NOT NULL,
  `value_type` varchar(16) NOT NULL DEFAULT 'string',
  `label` varchar(128) NOT NULL,
  `description` varchar(255) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `reward_config`
--

INSERT INTO `reward_config` (`config_key`, `config_value`, `value_type`, `label`, `description`, `updated_at`) VALUES
('input_lead_reward_rate', '0.0008', 'decimal', 'Ein/Aus Zins', 'Einmaliger Zins, wenn Einzahlungen Auszahlungen von unten ueberholen.', '2026-06-03 12:10:03'),
('monthly_interest_rate', '0.0008', 'decimal', 'Monatszins', 'Zins, der einmal pro Monat beim Login gutgeschrieben wird.', '2026-06-03 12:10:03'),
('reward_deposit_enabled', 'true', 'boolean', 'Einzahlungs-Kiste', 'Goldene Kiste fuer neue Einzahlungen.', '2026-06-03 12:10:03'),
('reward_input_lead_enabled', 'true', 'boolean', 'Ein/Aus aktiv', 'Ein/Aus Belohnungen aktivieren.', '2026-06-03 12:10:03'),
('reward_monthly_interest_enabled', 'true', 'boolean', 'Monatszins aktiv', 'Monatliche Zinsen und Kisten aktivieren.', '2026-06-03 12:10:03'),
('reward_savings_milestone_enabled', 'true', 'boolean', 'Spar-Level aktiv', 'Spar-Level Belohnungen aktivieren.', '2026-06-03 12:10:03'),
('savings_milestone_reward_rate', '0.0008', 'decimal', 'Spar-Level Zins', 'Einmaliger Zins beim Ueberschreiten eines Spar-Levels.', '2026-06-03 12:10:03'),
('savings_milestone_step', '100', 'decimal', 'Spar-Level Schritt', 'Kontostand-Schritt fuer Spar-Level Belohnungen.', '2026-06-03 12:10:03');

-- --------------------------------------------------------

--
-- Table structure for table `reward_events`
--

CREATE TABLE `reward_events` (
  `id` int(11) NOT NULL,
  `customer` int(11) NOT NULL,
  `reward_key` varchar(64) NOT NULL,
  `reward_type` varchar(64) NOT NULL,
  `chest_variant` varchar(32) NOT NULL,
  `title` varchar(128) NOT NULL,
  `description` varchar(255) NOT NULL,
  `trigger_value` varchar(64) DEFAULT NULL,
  `interest_rate` decimal(12,8) DEFAULT NULL,
  `amount` double NOT NULL DEFAULT 0,
  `balance_before` double NOT NULL DEFAULT 0,
  `balance_after` double NOT NULL DEFAULT 0,
  `transaction_id` int(11) DEFAULT NULL,
  `earned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `opened_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `reward_events`
--

INSERT INTO `reward_events` (`id`, `customer`, `reward_key`, `reward_type`, `chest_variant`, `title`, `description`, `trigger_value`, `interest_rate`, `amount`, `balance_before`, `balance_after`, `transaction_id`, `earned_at`, `opened_at`) VALUES
(1, 7, 'deposit', 'money', 'gold', 'Einzahlung erhalten', 'Geld ist in deinem Pocket gelandet.', NULL, NULL, 80, 0, 80, 190, '2026-06-03 09:04:14', '2026-06-03 09:13:04'),
(2, 7, 'input_lead', 'interest', 'crystals', 'Mehr Ein als Aus', 'Deine Einzahlungen liegen vorne.', '1/0', '0.00080000', 0.06, 80, 80.06, 191, '2026-06-03 09:04:14', '2026-06-03 09:13:10'),
(3, 7, 'deposit', 'money', 'gold', 'Einzahlung erhalten', 'Geld ist in deinem Pocket gelandet.', NULL, NULL, 30, 80.06, 110.06, 192, '2026-06-03 09:06:08', '2026-06-03 09:13:12'),
(4, 7, 'savings_milestone', 'interest', 'crystals', 'Level 1 erreicht', 'Du hast 100 erreicht und bekommst Zins.', '100', '0.00080000', 0.09, 110.06, 110.15, 193, '2026-06-03 09:06:08', '2026-06-03 09:13:15'),
(5, 8, 'deposit', 'money', 'gold', 'Einzahlung erhalten', 'Geld ist in deinem Pocket gelandet.', NULL, NULL, 10, 0, 10, 194, '2026-06-03 11:07:06', '2026-06-03 11:10:09'),
(6, 8, 'input_lead', 'interest', 'crystals', 'Mehr Ein als Aus', 'Deine Einzahlungen liegen vorne.', '1/0', '0.00080000', 0.01, 10, 10.01, 195, '2026-06-03 11:07:06', '2026-06-03 11:10:13'),
(7, 7, 'deposit', 'money', 'gold', 'Einzahlung erhalten', 'Geld ist in deinem Pocket gelandet.', NULL, NULL, 10, 0, 10, 197, '2026-06-03 11:08:13', '2026-07-24 07:31:38'),
(8, 8, 'monthly_interest', 'interest', 'gold', 'Monatszins', 'Dein Pocket hat Zins bekommen.', '2026-06', '0.00080000', 0.01, 10.01, 10.02, 198, '2026-06-03 11:10:06', '2026-06-03 11:10:16'),
(9, 5, 'deposit', 'money', 'gold', 'Einzahlung erhalten', 'Geld ist in deinem Pocket gelandet.', NULL, NULL, 10, 126.8, 136.8, 199, '2026-06-03 12:12:26', '2026-06-11 21:24:59'),
(10, 2, 'deposit', 'money', 'gold', 'Einzahlung erhalten', 'Geld ist in deinem Pocket gelandet.', NULL, NULL, 10, 88.49, 98.49, 200, '2026-06-03 12:12:32', '2026-06-03 18:58:49'),
(11, 3, 'deposit', 'money', 'gold', 'Einzahlung erhalten', 'Geld ist in deinem Pocket gelandet.', NULL, NULL, 10, 0.05, 10.05, 201, '2026-06-03 12:12:41', NULL),
(12, 4, 'monthly_interest', 'interest', 'gold', 'Monatszins', 'Dein Pocket hat Zins bekommen.', '2026-06', '0.00080000', 0.07, 87.99, 88.06, 202, '2026-06-03 15:36:03', '2026-06-03 15:36:08'),
(13, 2, 'monthly_interest', 'interest', 'gold', 'Monatszins', 'Dein Pocket hat Zins bekommen.', '2026-06', '0.00080000', 0.08, 98.49, 98.57, 205, '2026-06-03 18:58:46', '2026-06-03 18:58:53'),
(14, 4, 'deposit', 'money', 'gold', 'Einzahlung erhalten', 'Geld ist in deinem Pocket gelandet.', NULL, NULL, 10, 63.06, 73.06, 206, '2026-06-06 16:51:14', '2026-06-06 16:51:45'),
(15, 4, 'deposit', 'money', 'gold', 'Einzahlung erhalten', 'Geld ist in deinem Pocket gelandet.', NULL, NULL, 5, 73.06, 78.06, 207, '2026-06-06 16:51:22', '2026-06-06 16:51:53'),
(16, 4, 'deposit', 'money', 'gold', 'Einzahlung erhalten', 'Geld ist in deinem Pocket gelandet.', NULL, NULL, 5, 78.06, 83.06, 208, '2026-06-06 16:51:28', '2026-06-06 16:51:55'),
(17, 4, 'deposit', 'money', 'gold', 'Einzahlung erhalten', 'Geld ist in deinem Pocket gelandet.', NULL, NULL, 2, 83.06, 85.06, 209, '2026-06-06 18:50:26', '2026-06-07 07:02:04'),
(18, 5, 'monthly_interest', 'interest', 'gold', 'Monatszins', 'Dein Pocket hat Zins bekommen.', '2026-06', '0.00080000', 0.11, 136.8, 136.91, 211, '2026-06-11 21:24:57', '2026-06-11 21:25:01'),
(19, 4, 'deposit', 'money', 'gold', 'Einzahlung erhalten', 'Geld ist in deinem Pocket gelandet.', NULL, NULL, 5, 14.79, 19.79, 214, '2026-06-25 18:38:01', '2026-06-27 14:42:52'),
(20, 4, 'deposit', 'money', 'gold', 'Einzahlung erhalten', 'Geld ist in deinem Pocket gelandet.', NULL, NULL, 15, 2.29, 17.29, 217, '2026-06-28 21:32:19', '2026-07-09 18:17:40'),
(21, 4, 'deposit', 'money', 'gold', 'Einzahlung erhalten', 'Geld ist in deinem Pocket gelandet.', NULL, NULL, 5, 17.29, 22.29, 218, '2026-07-05 21:53:05', '2026-07-09 18:17:42'),
(22, 4, 'monthly_interest', 'interest', 'gold', 'Monatszins', 'Dein Pocket hat Zins bekommen.', '2026-07', '0.00080000', 0.02, 22.29, 22.31, 219, '2026-07-09 18:17:38', '2026-07-09 18:17:44'),
(23, 4, 'deposit', 'money', 'gold', 'Einzahlung erhalten', 'Geld ist in deinem Pocket gelandet.', NULL, NULL, 11, 14.31, 25.31, 221, '2026-07-10 17:52:00', '2026-07-10 22:18:23'),
(24, 2, 'deposit', 'money', 'gold', 'Einzahlung erhalten', 'Geld ist in deinem Pocket gelandet.', NULL, NULL, 0.43, 98.57, 99, 222, '2026-07-10 17:53:49', '2026-07-17 12:53:43'),
(25, 2, 'deposit', 'money', 'gold', 'Einzahlung erhalten', 'Geld ist in deinem Pocket gelandet.', NULL, NULL, 1, 99, 100, 223, '2026-07-10 17:54:10', '2026-07-17 12:53:45'),
(26, 2, 'savings_milestone', 'interest', 'crystals', 'Level 1 erreicht', 'Du hast 100 erreicht und bekommst Zins.', '100', '0.00080000', 0.08, 100, 100.08, 224, '2026-07-10 17:54:10', '2026-07-17 12:53:51'),
(27, 3, 'deposit', 'money', 'gold', 'Einzahlung erhalten', 'Geld ist in deinem Pocket gelandet.', NULL, NULL, 140, 10.05, 150.05, 225, '2026-07-16 10:01:28', NULL),
(28, 3, 'savings_milestone', 'interest', 'crystals', 'Level 1 erreicht', 'Du hast 100 erreicht und bekommst Zins.', '100', '0.00080000', 0.12, 150.05, 150.17, 226, '2026-07-16 10:01:28', NULL),
(29, 2, 'deposit', 'money', 'gold', 'Einzahlung erhalten', 'Geld ist in deinem Pocket gelandet.', NULL, NULL, 50, 100.08, 150.08, 227, '2026-07-17 10:27:21', '2026-07-17 12:53:52'),
(30, 2, 'monthly_interest', 'interest', 'gold', 'Monatszins', 'Dein Pocket hat Zins bekommen.', '2026-07', '0.00080000', 0.12, 150.08, 150.2, 228, '2026-07-17 12:53:41', '2026-07-17 12:53:56'),
(31, 4, 'deposit', 'money', 'gold', 'Einzahlung erhalten', 'Geld ist in deinem Pocket gelandet.', NULL, NULL, 20, 25.31, 45.31, 229, '2026-07-17 16:40:05', '2026-07-17 21:10:37'),
(32, 4, 'deposit', 'money', 'gold', 'Einzahlung erhalten', 'Geld ist in deinem Pocket gelandet.', NULL, NULL, 13.67, 20.31, 33.98, 231, '2026-07-17 21:24:52', '2026-07-17 21:26:13'),
(33, 4, 'deposit', 'money', 'gold', 'Einzahlung erhalten', 'Geld ist in deinem Pocket gelandet.', NULL, NULL, 0.02, 33.98, 34, 232, '2026-07-17 21:27:23', '2026-07-17 21:27:41'),
(34, 4, 'deposit', 'money', 'gold', 'Einzahlung erhalten', 'Geld ist in deinem Pocket gelandet.', NULL, NULL, 50, 32.1, 82.1, 234, '2026-07-21 08:46:54', '2026-07-21 08:47:12'),
(35, 4, 'deposit', 'money', 'gold', 'Einzahlung erhalten', 'Geld ist in deinem Pocket gelandet.', NULL, NULL, 20, 82.1, 102.1, 235, '2026-07-21 09:09:20', '2026-07-21 09:09:47'),
(36, 4, 'savings_milestone', 'interest', 'crystals', 'Level 1 erreicht', 'Du hast 100 erreicht und bekommst Zins.', '100', '0.00080000', 0.08, 102.1, 102.18, 236, '2026-07-21 09:09:20', '2026-07-21 09:09:50'),
(37, 7, 'monthly_interest', 'interest', 'gold', 'Monatszins', 'Dein Pocket hat Zins bekommen.', '2026-07', '0.00080000', 0.01, 10, 10.01, 240, '2026-07-24 07:31:27', '2026-07-24 07:31:47'),
(38, 8, 'monthly_interest', 'interest', 'gold', 'Monatszins', 'Dein Pocket hat Zins bekommen.', '2026-07', '0.00080000', 0.01, 10.02, 10.03, 241, '2026-07-24 07:32:12', '2026-07-24 07:32:18');

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `id` int(11) NOT NULL,
  `customer` int(11) NOT NULL,
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  `amount` double NOT NULL,
  `balance` double NOT NULL,
  `kind` varchar(32) NOT NULL DEFAULT 'manual',
  `note` varchar(255) DEFAULT NULL,
  `approved` tinyint(4) NOT NULL DEFAULT 1,
  `undone` tinyint(4) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `transactions`
--

INSERT INTO `transactions` (`id`, `customer`, `datetime`, `amount`, `balance`, `kind`, `note`, `approved`, `undone`) VALUES
(1, 2, '2022-12-01 15:22:25', 287.5, 287.5, 'manual', NULL, 1, 0),
(2, 4, '2022-12-01 17:40:12', 117.5, 117.5, 'manual', NULL, 1, 0),
(3, 3, '2022-12-01 17:43:43', -194, -194, 'manual', NULL, 1, 0),
(4, 5, '2022-12-01 17:44:28', 50, 50, 'manual', NULL, 1, 0),
(6, 4, '2022-12-04 19:48:43', 7, 124.5, 'manual', NULL, 1, 0),
(7, 4, '2022-12-08 17:41:20', 30, 154.5, 'manual', NULL, 1, 0),
(8, 2, '2022-12-12 11:23:15', -287, 0.5, 'manual', NULL, 1, 0),
(9, 2, '2022-12-13 17:14:53', 7.45, 7.95, 'manual', NULL, 1, 0),
(10, 2, '2022-12-16 05:58:06', -1, 6.95, 'manual', NULL, 1, 1),
(11, 4, '2022-12-16 05:58:16', 1, 155.5, 'manual', NULL, 1, 1),
(12, 2, '2022-12-16 05:58:56', -0.5, 7.45, 'manual', NULL, 1, 0),
(13, 4, '2022-12-16 05:59:05', 0.5, 155, 'manual', NULL, 1, 0),
(14, 4, '2022-12-27 17:33:12', 50, 205, 'manual', NULL, 1, 0),
(15, 2, '2022-12-28 13:52:09', 50, 57.45, 'manual', NULL, 1, 0),
(16, 4, '2022-12-28 13:52:16', 50, 255, 'manual', NULL, 1, 0),
(17, 4, '2022-12-31 11:43:28', -21.5, 233.5, 'manual', NULL, 1, 0),
(18, 2, '2022-12-31 11:44:18', -6.5, 50.95, 'manual', NULL, 1, 0),
(19, 4, '2023-01-05 19:31:58', 10, 243.5, 'manual', NULL, 1, 0),
(20, 4, '2023-01-05 19:32:05', 15, 258.5, 'manual', NULL, 1, 0),
(21, 3, '2023-01-06 06:54:38', 16, -178, 'manual', NULL, 1, 0),
(22, 4, '2023-01-19 17:13:12', 21, 279.5, 'manual', NULL, 1, 0),
(23, 4, '2023-01-19 17:13:19', 7, 286.5, 'manual', NULL, 1, 0),
(24, 4, '2023-01-27 06:05:21', 4.7, 291.2, 'manual', NULL, 1, 0),
(25, 4, '2023-01-28 05:30:29', 0.2, 291.4, 'manual', NULL, 1, 0),
(26, 2, '2023-01-28 20:31:34', 15, 64.95, 'manual', NULL, 1, 1),
(27, 4, '2023-01-28 20:31:50', 15, 306.4, 'manual', NULL, 1, 0),
(28, 4, '2023-01-29 18:09:41', 12.5, 318.9, 'manual', NULL, 1, 0),
(29, 4, '2023-02-02 17:48:46', 40, 358.9, 'manual', NULL, 1, 0),
(30, 4, '2023-02-02 23:16:49', 0.2, 360.09999999999997, 'manual', NULL, 1, 1),
(31, 4, '2023-02-02 23:17:35', 0.1, 359, 'manual', NULL, 1, 0),
(32, 4, '2023-02-05 20:33:10', -28, 331, 'manual', NULL, 1, 0),
(33, 3, '2023-02-05 20:33:19', 7, -171, 'manual', NULL, 1, 0),
(34, 2, '2023-02-05 20:33:35', 8, 58.95, 'manual', NULL, 1, 0),
(35, 4, '2023-02-05 20:33:47', 5, 336, 'manual', NULL, 1, 0),
(36, 3, '2023-02-05 20:33:57', 80, -91, 'manual', NULL, 1, 0),
(37, 2, '2023-02-08 18:12:25', 10, 68.95, 'manual', NULL, 1, 0),
(38, 3, '2023-02-08 18:13:08', -80, -171, 'manual', NULL, 1, 0),
(39, 4, '2023-02-11 12:10:28', -2.5, 333.5, 'manual', NULL, 1, 0),
(40, 3, '2023-02-19 17:59:19', 16, -155, 'manual', NULL, 1, 0),
(41, 4, '2023-02-19 17:59:42', 8, 341.5, 'manual', NULL, 1, 0),
(42, 4, '2023-02-25 16:09:01', 2, 343.5, 'manual', NULL, 1, 0),
(43, 4, '2023-02-26 13:46:39', 0.65, 344.15, 'manual', NULL, 1, 0),
(44, 4, '2023-03-02 09:45:50', -10, 334.15, 'manual', NULL, 1, 0),
(45, 4, '2023-03-09 10:25:59', -7, 327.15, 'manual', NULL, 1, 0),
(46, 4, '2023-03-19 12:34:47', 20, 347.15, 'manual', NULL, 1, 0),
(47, 2, '2023-03-19 12:34:59', 20, 88.95, 'manual', NULL, 1, 0),
(48, 2, '2023-03-30 16:20:09', 20, 108.95, 'manual', NULL, 1, 0),
(49, 5, '2023-03-30 16:20:14', 20, 70, 'manual', NULL, 1, 0),
(50, 4, '2023-03-30 16:20:19', 20, 367.15, 'manual', NULL, 1, 0),
(51, 4, '2023-03-30 16:20:32', -5, 362.15, 'manual', NULL, 1, 0),
(52, 4, '2023-04-08 20:33:46', 30, 392.15, 'manual', NULL, 1, 0),
(53, 4, '2023-04-12 12:01:20', 130, 522.15, 'manual', NULL, 1, 0),
(54, 4, '2023-04-12 12:03:11', 20, 542.15, 'manual', NULL, 1, 0),
(55, 4, '2023-04-20 18:59:14', 13, 555.15, 'manual', NULL, 1, 0),
(56, 4, '2023-05-06 20:50:45', 5, 560.15, 'manual', NULL, 1, 0),
(57, 4, '2023-05-07 17:02:24', 30, 590.15, 'manual', NULL, 1, 0),
(58, 4, '2023-05-14 23:44:27', 7, 597.15, 'manual', NULL, 1, 0),
(59, 4, '2023-05-18 18:37:03', -50, 547.15, 'manual', NULL, 1, 0),
(60, 4, '2023-05-27 11:12:50', -30, 517.15, 'manual', NULL, 1, 0),
(61, 4, '2023-05-27 18:16:08', 14, 531.15, 'manual', NULL, 1, 0),
(62, 4, '2023-06-09 04:59:15', 10, 541.15, 'manual', NULL, 1, 0),
(63, 4, '2023-06-11 16:49:47', 0.7, 541.85, 'manual', NULL, 1, 0),
(64, 4, '2023-07-19 09:49:29', -1.5, 540.35, 'manual', NULL, 1, 0),
(65, 4, '2023-07-19 09:49:42', 5.7, 546.05, 'manual', NULL, 1, 0),
(66, 4, '2023-07-19 09:51:54', -380, 166.05, 'manual', NULL, 1, 0),
(67, 2, '2023-07-22 10:35:19', -100, 8.95, 'manual', NULL, 1, 0),
(68, 4, '2023-08-06 20:14:45', -16, 150.05, 'manual', NULL, 1, 0),
(69, 4, '2023-08-06 20:15:02', 2.3, 152.35, 'manual', NULL, 1, 0),
(70, 4, '2023-08-06 20:15:09', 3, 155.35, 'manual', NULL, 1, 0),
(71, 4, '2023-08-24 20:33:06', 0.3, 155.65, 'manual', NULL, 1, 0),
(72, 4, '2023-08-24 20:33:37', -10.97, 144.68, 'manual', NULL, 1, 0),
(73, 4, '2023-09-16 17:08:01', -16, 128.68, 'manual', NULL, 1, 0),
(74, 4, '2023-10-03 09:29:43', -53, 75.68, 'manual', NULL, 1, 0),
(75, 4, '2023-10-03 09:29:49', -15, 60.68, 'manual', NULL, 1, 0),
(76, 4, '2023-10-03 09:31:01', 30, 90.68, 'manual', NULL, 1, 0),
(77, 4, '2023-10-10 20:10:34', 3.5, 94.18, 'manual', NULL, 1, 0),
(78, 4, '2023-10-18 12:32:49', -15, 79.18, 'manual', NULL, 1, 0),
(79, 4, '2023-10-18 15:32:08', 5, 84.18, 'manual', NULL, 1, 0),
(80, 4, '2023-11-28 20:34:58', 190, 274.18, 'manual', NULL, 1, 0),
(81, 4, '2023-11-28 20:37:03', 2, 276.18, 'manual', NULL, 1, 0),
(82, 2, '2023-11-28 20:40:26', 760, 768.95, 'manual', NULL, 1, 0),
(83, 2, '2023-12-03 06:26:17', 160, 928.95, 'manual', NULL, 1, 0),
(84, 2, '2023-12-03 06:27:16', 9.5, 938.45, 'manual', NULL, 1, 0),
(85, 4, '2023-12-03 06:29:25', 2.8, 278.98, 'manual', NULL, 1, 0),
(86, 3, '2023-12-03 06:31:19', 151, -4, 'manual', NULL, 1, 0),
(87, 5, '2023-12-03 06:31:59', 0.7, 70.7, 'manual', NULL, 1, 0),
(88, 2, '2023-12-05 18:11:03', 0.5, 938.95, 'manual', NULL, 1, 0),
(89, 4, '2024-01-05 11:58:24', -6.95, 272.03, 'manual', NULL, 1, 0),
(90, 4, '2024-01-05 11:58:54', 10, 282.03, 'manual', NULL, 1, 0),
(91, 4, '2024-01-05 11:59:00', 50, 332.03, 'manual', NULL, 1, 0),
(92, 4, '2024-01-05 11:59:17', -4, 328.03, 'manual', NULL, 1, 0),
(93, 2, '2024-01-05 11:59:21', 4, 942.95, 'manual', NULL, 1, 0),
(94, 2, '2024-01-05 12:01:13', -5, 937.95, 'manual', NULL, 1, 0),
(95, 2, '2024-01-05 12:01:40', -50, 887.95, 'manual', NULL, 1, 0),
(96, 4, '2024-01-05 12:02:10', 12, 340.03, 'manual', NULL, 1, 0),
(97, 4, '2024-01-05 12:02:43', 3.41, 343.44, 'manual', NULL, 1, 0),
(98, 2, '2024-01-05 12:03:08', 2, 889.95, 'manual', NULL, 1, 0),
(99, 2, '2024-01-05 12:03:30', 9.04, 898.99, 'manual', NULL, 1, 0),
(100, 5, '2024-01-05 12:03:56', 50, 120.7, 'manual', NULL, 1, 0),
(101, 5, '2024-01-05 12:04:19', 1.2, 121.9, 'manual', NULL, 1, 0),
(102, 4, '2024-01-14 14:51:40', -3.5, 339.94, 'manual', NULL, 1, 0),
(103, 4, '2024-02-24 18:48:41', -20, 319.94, 'manual', NULL, 1, 0),
(104, 4, '2024-02-24 18:48:54', 2.1, 322.04, 'manual', NULL, 1, 0),
(105, 2, '2024-03-16 14:43:17', -30, 868.99, 'manual', NULL, 1, 0),
(106, 4, '2024-03-16 14:43:50', 1, 323.04, 'manual', NULL, 1, 0),
(107, 2, '2024-04-01 12:50:12', -1, 867.99, 'manual', NULL, 1, 0),
(108, 4, '2024-04-01 12:50:21', 1, 324.04, 'manual', NULL, 1, 0),
(109, 4, '2024-04-01 12:50:38', -7, 317.04, 'manual', NULL, 1, 0),
(110, 4, '2024-04-02 18:02:05', -10, 307.04, 'manual', NULL, 1, 0),
(111, 2, '2024-04-03 11:32:54', -40, 827.99, 'manual', NULL, 1, 0),
(112, 4, '2024-05-05 07:07:41', -200, 107.04, 'manual', NULL, 1, 0),
(113, 2, '2024-05-05 07:07:53', -40, 787.99, 'manual', NULL, 1, 0),
(114, 4, '2024-05-05 07:09:41', -40, 68.24, 'manual', NULL, 1, 1),
(115, 2, '2024-05-05 07:12:01', -40, 747.99, 'manual', NULL, 1, 0),
(116, 4, '2024-05-05 07:12:14', -34, 73.04, 'manual', NULL, 1, 0),
(117, 4, '2024-05-05 07:12:24', 7, 80.04, 'manual', NULL, 1, 0),
(118, 4, '2024-05-05 07:17:13', 12, 92.04, 'manual', NULL, 1, 0),
(119, 2, '2024-05-05 07:17:51', 30.5, 778.49, 'manual', NULL, 1, 0),
(120, 5, '2024-05-05 07:18:23', 4.9, 126.8, 'manual', NULL, 1, 0),
(121, 3, '2024-05-05 07:18:52', 5, 1, 'manual', NULL, 1, 0),
(122, 3, '2024-05-05 07:19:19', 0.05, 1.05, 'manual', NULL, 1, 0),
(123, 4, '2024-06-08 16:23:28', -15, 77.04, 'manual', NULL, 1, 0),
(124, 4, '2024-07-24 13:32:41', -13, 64.04, 'manual', NULL, 1, 0),
(125, 4, '2024-10-03 10:23:30', 80, 144.04, 'manual', NULL, 1, 0),
(126, 4, '2024-10-05 19:32:09', 25, 169.04, 'manual', NULL, 1, 0),
(127, 3, '2024-10-05 19:32:19', 5, 6.05, 'manual', NULL, 1, 0),
(128, 4, '2024-10-06 20:18:36', 17, 186.04, 'manual', NULL, 1, 0),
(129, 4, '2025-01-02 12:29:03', 5, 191.04, 'manual', NULL, 1, 0),
(130, 2, '2025-01-02 12:33:28', -200, 578.49, 'manual', NULL, 1, 0),
(131, 2, '2025-01-02 12:33:38', -250, 328.49, 'manual', NULL, 1, 0),
(132, 4, '2025-01-23 15:56:50', 5, 196.04, 'manual', NULL, 1, 0),
(133, 4, '2025-01-23 15:57:17', -20, 176.04, 'manual', NULL, 1, 0),
(134, 4, '2025-01-23 15:58:42', -130, 46.04, 'manual', NULL, 1, 0),
(135, 4, '2025-03-05 17:53:26', 20, 66.04, 'manual', NULL, 1, 0),
(136, 4, '2025-03-05 17:53:36', 46.15, 112.19, 'manual', NULL, 1, 0),
(137, 4, '2025-03-27 17:16:27', -30, 82.19, 'manual', NULL, 1, 0),
(138, 4, '2025-03-27 17:18:26', 5, 87.19, 'manual', NULL, 1, 0),
(139, 4, '2025-04-21 13:24:18', 20, 107.19, 'manual', NULL, 1, 0),
(140, 4, '2025-04-25 18:32:32', -20, 87.19, 'manual', NULL, 1, 0),
(141, 4, '2025-05-07 12:47:27', -15, 72.19, 'manual', NULL, 1, 0),
(142, 4, '2025-05-21 17:46:05', 136, 208.19, 'manual', NULL, 1, 0),
(143, 4, '2025-06-04 19:47:39', -119, 89.19, 'manual', NULL, 1, 0),
(144, 4, '2025-06-04 19:47:55', -49, 40.19, 'manual', NULL, 1, 0),
(145, 4, '2025-06-04 19:48:32', 90, 130.19, 'manual', NULL, 1, 0),
(146, 4, '2025-06-04 19:49:36', 50, 180.19, 'manual', NULL, 1, 0),
(147, 4, '2025-07-17 13:16:15', -40, 140.19, 'manual', NULL, 1, 0),
(148, 4, '2025-07-17 13:16:21', -35, 105.19, 'manual', NULL, 1, 0),
(149, 2, '2025-11-14 06:05:23', 180, 508.49, 'manual', NULL, 1, 0),
(150, 4, '2025-11-14 06:05:30', 50, 155.19, 'manual', NULL, 1, 0),
(151, 3, '2025-11-14 06:05:34', 50, 56.05, 'manual', NULL, 1, 0),
(152, 3, '2025-11-14 06:05:45', 70, 126.05, 'manual', NULL, 1, 0),
(153, 3, '2025-11-14 06:06:04', -25, 101.05, 'manual', NULL, 1, 0),
(154, 3, '2025-11-14 06:06:31', -35, 66.05, 'manual', NULL, 1, 0),
(155, 3, '2025-11-15 16:34:56', 200, 266.05, 'manual', NULL, 1, 0),
(156, 3, '2025-11-15 16:35:26', -120, 146.05, 'manual', NULL, 1, 0),
(157, 2, '2025-11-20 06:05:42', 50, 558.49, 'manual', NULL, 1, 0),
(158, 3, '2025-12-12 19:40:29', -10, 136.05, 'manual', NULL, 1, 0),
(159, 4, '2025-12-20 12:23:10', 10, 165.19, 'manual', NULL, 1, 0),
(160, 3, '2025-12-20 12:23:41', -20, 116.05, 'manual', NULL, 1, 0),
(161, 3, '2025-12-21 12:34:02', -75, 41.05, 'manual', NULL, 1, 0),
(162, 2, '2025-12-27 17:01:57', 80, 638.49, 'manual', NULL, 1, 0),
(163, 3, '2025-12-27 17:02:03', 80, 121.05, 'manual', NULL, 1, 0),
(164, 4, '2025-12-27 17:02:15', 80, 245.19, 'manual', NULL, 1, 0),
(165, 4, '2025-12-27 17:02:26', -120, 125.19, 'manual', NULL, 1, 0),
(166, 4, '2025-12-27 17:02:35', -20, 105.19, 'manual', NULL, 1, 0),
(167, 3, '2026-01-05 21:00:35', -14, 107.05, 'manual', NULL, 1, 0),
(168, 4, '2026-01-05 21:00:57', -48, 57.19, 'manual', NULL, 1, 0),
(169, 3, '2026-01-15 21:38:03', -107, 0.05, 'manual', NULL, 1, 0),
(170, 4, '2026-01-24 14:47:49', 20, 77.19, 'manual', NULL, 1, 0),
(171, 4, '2026-01-28 19:39:45', -32, 45.19, 'manual', NULL, 1, 0),
(172, 4, '2026-01-28 19:40:15', 20, 65.19, 'manual', NULL, 1, 0),
(173, 4, '2026-01-28 19:44:24', -13, 52.19, 'manual', NULL, 1, 0),
(174, 2, '2026-01-30 19:42:53', -350, 288.49, 'manual', NULL, 1, 0),
(175, 4, '2026-02-08 18:34:15', -13.39, 38.8, 'manual', NULL, 1, 0),
(176, 2, '2026-02-25 18:59:02', -200, 88.49, 'manual', NULL, 1, 0),
(177, 4, '2026-03-18 16:29:10', 10, 48.8, 'manual', NULL, 1, 0),
(178, 4, '2026-03-18 16:29:27', 10.2, 59, 'manual', NULL, 1, 0),
(179, 4, '2026-03-18 16:31:23', -13, 46, 'manual', NULL, 1, 0),
(180, 4, '2026-04-09 11:09:22', 10, 56, 'manual', NULL, 1, 0),
(181, 4, '2026-05-28 18:55:20', 30, 86, 'manual', NULL, 1, 0),
(182, 4, '2026-05-30 13:56:29', 10, 96, 'manual', NULL, 1, 0),
(183, 4, '2026-05-30 13:56:39', 40, 136, 'manual', NULL, 1, 0),
(184, 4, '2026-05-30 13:57:06', -11.8, 124.2, 'manual', NULL, 1, 0),
(185, 4, '2026-06-03 04:43:35', -3.9, 120.3, 'manual', NULL, 1, 0),
(186, 4, '2026-06-03 04:43:48', -7.9, 112.4, 'manual', NULL, 1, 0),
(187, 4, '2026-06-03 04:44:04', 15, 127.4, 'manual', NULL, 1, 0),
(188, 4, '2026-06-03 04:44:13', -12.51, 114.89, 'manual', NULL, 1, 0),
(189, 4, '2026-06-03 04:44:24', -26.9, 87.99, 'manual', NULL, 1, 0),
(190, 7, '2026-06-03 09:04:14', 80, 80, 'manual', NULL, 1, 1),
(191, 7, '2026-06-03 09:04:14', 0.06, 0.06, 'reward_interest', 'Mehr Ein als Aus', 1, 1),
(192, 7, '2026-06-03 09:06:08', 30, 110.06, 'manual', NULL, 1, 1),
(193, 7, '2026-06-03 09:06:08', 0.09, 80.15, 'reward_interest', 'Level 1 erreicht', 1, 1),
(194, 8, '2026-06-03 11:07:06', 10, 10, 'manual', NULL, 1, 0),
(195, 8, '2026-06-03 11:07:06', 0.01, 10.01, 'reward_interest', 'Mehr Ein als Aus', 1, 0),
(196, 7, '2026-06-03 11:07:31', -70, -70, 'manual', NULL, 1, 1),
(197, 7, '2026-06-03 11:08:13', 10, 10, 'manual', NULL, 1, 0),
(198, 8, '2026-06-03 11:10:06', 0.01, 10.02, 'reward_interest', 'Monatszins', 1, 0),
(199, 5, '2026-06-03 12:12:26', 10, 136.8, 'manual', NULL, 1, 0),
(200, 2, '2026-06-03 12:12:32', 10, 98.49, 'manual', NULL, 1, 0),
(201, 3, '2026-06-03 12:12:41', 10, 10.05, 'manual', NULL, 1, 0),
(202, 4, '2026-06-03 15:36:03', 0.07, 88.06, 'reward_interest', 'Monatszins', 1, 0),
(203, 4, '2026-06-03 15:41:28', -15, 73.06, 'manual', NULL, 1, 1),
(204, 4, '2026-06-03 15:42:19', -25, 63.06, 'manual', NULL, 1, 0),
(205, 2, '2026-06-03 18:58:46', 0.08, 98.57, 'reward_interest', 'Monatszins', 1, 0),
(206, 4, '2026-06-06 16:51:13', 10, 73.06, 'manual', NULL, 1, 0),
(207, 4, '2026-06-06 16:51:22', 5, 78.06, 'manual', NULL, 1, 0),
(208, 4, '2026-06-06 16:51:28', 5, 83.06, 'manual', NULL, 1, 0),
(209, 4, '2026-06-06 18:50:26', 2, 85.06, 'manual', NULL, 1, 0),
(210, 4, '2026-06-07 07:03:52', -3.9, 81.16, 'manual', NULL, 1, 0),
(211, 5, '2026-06-11 21:24:57', 0.11, 136.91, 'reward_interest', 'Monatszins', 1, 0),
(212, 4, '2026-06-13 19:32:36', -43.37, 37.79, 'manual', NULL, 1, 0),
(213, 4, '2026-06-14 17:53:32', -23, 14.79, 'manual', NULL, 1, 0),
(214, 4, '2026-06-25 18:38:01', 5, 19.79, 'manual', NULL, 1, 0),
(215, 4, '2026-06-25 18:39:35', -5.8, 13.99, 'manual', NULL, 1, 0),
(216, 4, '2026-06-28 21:32:08', -11.7, 2.29, 'manual', NULL, 1, 0),
(217, 4, '2026-06-28 21:32:19', 15, 17.29, 'manual', NULL, 1, 0),
(218, 4, '2026-07-05 21:53:05', 5, 22.29, 'manual', NULL, 1, 0),
(219, 4, '2026-07-09 18:17:38', 0.02, 22.31, 'reward_interest', 'Monatszins', 1, 0),
(220, 4, '2026-07-09 18:21:48', -8, 14.31, 'manual', NULL, 1, 0),
(221, 4, '2026-07-10 17:52:00', 11, 25.31, 'manual', NULL, 1, 0),
(222, 2, '2026-07-10 17:53:49', 0.43, 99, 'manual', NULL, 1, 0),
(223, 2, '2026-07-10 17:54:10', 1, 100, 'manual', NULL, 1, 0),
(224, 2, '2026-07-10 17:54:10', 0.08, 100.08, 'reward_interest', 'Level 1 erreicht', 1, 0),
(225, 3, '2026-07-16 10:01:28', 140, 150.05, 'manual', NULL, 1, 0),
(226, 3, '2026-07-16 10:01:28', 0.12, 150.17, 'reward_interest', 'Level 1 erreicht', 1, 0),
(227, 2, '2026-07-17 10:27:21', 50, 150.08, 'manual', NULL, 1, 0),
(228, 2, '2026-07-17 12:53:41', 0.12, 150.2, 'reward_interest', 'Monatszins', 1, 0),
(229, 4, '2026-07-17 16:40:05', 20, 45.31, 'manual', NULL, 1, 0),
(230, 4, '2026-07-17 21:24:21', -25, 20.31, 'manual', NULL, 1, 0),
(231, 4, '2026-07-17 21:24:52', 13.67, 33.98, 'manual', NULL, 1, 0),
(232, 4, '2026-07-17 21:27:23', 0.02, 34, 'manual', NULL, 1, 0),
(233, 4, '2026-07-19 23:00:04', -1.9, 32.1, 'manual', NULL, 1, 0),
(234, 4, '2026-07-21 08:46:54', 50, 82.1, 'manual', NULL, 1, 0),
(235, 4, '2026-07-21 09:09:20', 20, 102.1, 'manual', NULL, 1, 0),
(236, 4, '2026-07-21 09:09:20', 0.08, 102.18, 'reward_interest', 'Level 1 erreicht', 1, 0),
(237, 4, '2026-07-22 13:31:25', -71, 31.18, 'manual', NULL, 1, 1),
(238, 4, '2026-07-22 13:31:35', -5, 26.18, 'manual', NULL, 1, 1),
(239, 4, '2026-07-22 13:59:58', -77, 25.18, 'manual', NULL, 1, 0),
(240, 7, '2026-07-24 07:31:27', 0.01, 10.01, 'reward_interest', 'Monatszins', 1, 0),
(241, 8, '2026-07-24 07:32:12', 0.01, 10.03, 'reward_interest', 'Monatszins', 1, 0);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `customers_google_sub_unique` (`google_sub`),
  ADD UNIQUE KEY `customers_email_unique` (`email`);

--
-- Indexes for table `customer_reward_state`
--
ALTER TABLE `customer_reward_state`
  ADD PRIMARY KEY (`customer`,`state_key`);

--
-- Indexes for table `leases`
--
ALTER TABLE `leases`
  ADD PRIMARY KEY (`id`),
  ADD KEY `leases_fk_customers` (`customer`);

--
-- Indexes for table `reward_config`
--
ALTER TABLE `reward_config`
  ADD PRIMARY KEY (`config_key`);

--
-- Indexes for table `reward_events`
--
ALTER TABLE `reward_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `reward_events_fk_customers` (`customer`),
  ADD KEY `reward_events_fk_transactions` (`transaction_id`),
  ADD KEY `reward_events_customer_opened` (`customer`,`opened_at`,`earned_at`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `transactions_fk_customers` (`customer`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `leases`
--
ALTER TABLE `leases`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reward_events`
--
ALTER TABLE `reward_events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=242;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `customer_reward_state`
--
ALTER TABLE `customer_reward_state`
  ADD CONSTRAINT `customer_reward_state_fk_customers` FOREIGN KEY (`customer`) REFERENCES `customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `leases`
--
ALTER TABLE `leases`
  ADD CONSTRAINT `leases_fk_customers` FOREIGN KEY (`customer`) REFERENCES `customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `reward_events`
--
ALTER TABLE `reward_events`
  ADD CONSTRAINT `reward_events_fk_customers` FOREIGN KEY (`customer`) REFERENCES `customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `reward_events_fk_transactions` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_fk_customers` FOREIGN KEY (`customer`) REFERENCES `customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
