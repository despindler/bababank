SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE `customers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `fullname` varchar(256) NOT NULL,
  `username` varchar(128) NOT NULL,
  `userpassword` varchar(128) NOT NULL,
  `google_sub` varchar(64) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `display_name` varchar(255) DEFAULT NULL,
  `boss` tinyint NOT NULL DEFAULT '0',
  `realm` int NOT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `customers_google_sub_unique` (`google_sub`),
  UNIQUE KEY `customers_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

CREATE TABLE `transactions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `customer` int NOT NULL,
  `datetime` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `amount` double NOT NULL,
  `balance` double NOT NULL,
  `kind` varchar(32) NOT NULL DEFAULT 'manual',
  `note` varchar(255) DEFAULT NULL,
  `approved` tinyint NOT NULL DEFAULT '1',
  `undone` tinyint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `transactions_fk_customers` (`customer`),
  CONSTRAINT `transactions_fk_customers` FOREIGN KEY (`customer`) REFERENCES `customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

CREATE TABLE `customer_reward_state` (
  `customer` int NOT NULL,
  `state_key` varchar(64) NOT NULL,
  `state_value` varchar(255) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`customer`, `state_key`),
  CONSTRAINT `customer_reward_state_fk_customers` FOREIGN KEY (`customer`) REFERENCES `customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

CREATE TABLE `reward_events` (
  `id` int NOT NULL AUTO_INCREMENT,
  `customer` int NOT NULL,
  `reward_key` varchar(64) NOT NULL,
  `reward_type` varchar(64) NOT NULL,
  `chest_variant` varchar(32) NOT NULL,
  `title` varchar(128) NOT NULL,
  `description` varchar(255) NOT NULL,
  `trigger_value` varchar(64) DEFAULT NULL,
  `interest_rate` decimal(12,8) DEFAULT NULL,
  `amount` double NOT NULL DEFAULT '0',
  `balance_before` double NOT NULL DEFAULT '0',
  `balance_after` double NOT NULL DEFAULT '0',
  `transaction_id` int DEFAULT NULL,
  `earned_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `opened_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `reward_events_fk_customers` (`customer`),
  KEY `reward_events_fk_transactions` (`transaction_id`),
  KEY `reward_events_customer_opened` (`customer`, `opened_at`, `earned_at`),
  CONSTRAINT `reward_events_fk_customers` FOREIGN KEY (`customer`) REFERENCES `customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `reward_events_fk_transactions` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

CREATE TABLE `reward_config` (
  `config_key` varchar(64) NOT NULL,
  `config_value` varchar(255) NOT NULL,
  `value_type` varchar(16) NOT NULL DEFAULT 'string',
  `label` varchar(128) NOT NULL,
  `description` varchar(255) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

SET FOREIGN_KEY_CHECKS = 1;
