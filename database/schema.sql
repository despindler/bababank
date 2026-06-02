-- Latest Ba Ba Bank schema.
-- Keep this file in sync with database/migrations/.
-- Use this for fresh database creation, then apply database/seed.sql for live-like data.

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
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `customers_google_sub_unique` (`google_sub`),
  UNIQUE KEY `customers_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
CREATE TABLE `leases` (
  `id` int NOT NULL AUTO_INCREMENT,
  `customer` int NOT NULL,
  `datetime` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `lease` varchar(128) NOT NULL,
  `valid` tinyint NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `leases_fk_customers` (`customer`),
  CONSTRAINT `leases_fk_customers` FOREIGN KEY (`customer`) REFERENCES `customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
CREATE TABLE `transactions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `customer` int NOT NULL,
  `datetime` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `amount` double NOT NULL,
  `balance` double NOT NULL,
  `approved` tinyint NOT NULL DEFAULT '1',
  `undone` tinyint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `transactions_fk_customers` (`customer`),
  CONSTRAINT `transactions_fk_customers` FOREIGN KEY (`customer`) REFERENCES `customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
SET FOREIGN_KEY_CHECKS = 1;

