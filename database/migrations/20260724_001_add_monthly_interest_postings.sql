-- The new month-end engine starts with the first full period after the
-- July 2026 cutover. August 2026 interest is first effective on 2026-09-01.
SET @monthly_interest_cutover_period = '2026-08-01';

ALTER TABLE transactions
  MODIFY COLUMN `amount` decimal(15,2) NOT NULL,
  MODIFY COLUMN `balance` decimal(15,2) NOT NULL;

ALTER TABLE reward_events
  MODIFY COLUMN `amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  MODIFY COLUMN `balance_before` decimal(15,2) NOT NULL DEFAULT '0.00',
  MODIFY COLUMN `balance_after` decimal(15,2) NOT NULL DEFAULT '0.00';

CREATE TABLE `monthly_interest_rates` (
  `effective_period` date NOT NULL,
  `rate` decimal(12,8) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`effective_period`),
  CONSTRAINT `monthly_interest_rates_period_first_day` CHECK (DAYOFMONTH(`effective_period`) = 1),
  CONSTRAINT `monthly_interest_rates_nonnegative` CHECK (`rate` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

CREATE TABLE `customer_interest_eligibility` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `customer` int NOT NULL,
  `start_period` date NOT NULL,
  `end_period` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `customer_interest_eligibility_start` (`customer`, `start_period`),
  KEY `customer_interest_eligibility_periods` (`customer`, `start_period`, `end_period`),
  CONSTRAINT `customer_interest_eligibility_fk_customers` FOREIGN KEY (`customer`) REFERENCES `customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `customer_interest_eligibility_start_first_day` CHECK (DAYOFMONTH(`start_period`) = 1),
  CONSTRAINT `customer_interest_eligibility_end_first_day` CHECK (`end_period` IS NULL OR DAYOFMONTH(`end_period`) = 1),
  CONSTRAINT `customer_interest_eligibility_valid_range` CHECK (`end_period` IS NULL OR `end_period` > `start_period`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

CREATE TABLE `monthly_interest_postings` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `customer` int NOT NULL,
  `period_start` date NOT NULL,
  `balance_basis` decimal(15,2) NOT NULL,
  `interest_rate` decimal(12,8) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `effective_at` timestamp NOT NULL,
  `processed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `transaction_id` int DEFAULT NULL,
  `reward_event_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `monthly_interest_postings_customer_period` (`customer`, `period_start`),
  UNIQUE KEY `monthly_interest_postings_transaction` (`transaction_id`),
  UNIQUE KEY `monthly_interest_postings_reward_event` (`reward_event_id`),
  KEY `monthly_interest_postings_effective_at` (`effective_at`),
  CONSTRAINT `monthly_interest_postings_fk_customers` FOREIGN KEY (`customer`) REFERENCES `customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `monthly_interest_postings_fk_transactions` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `monthly_interest_postings_fk_reward_events` FOREIGN KEY (`reward_event_id`) REFERENCES `reward_events` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `monthly_interest_postings_period_first_day` CHECK (DAYOFMONTH(`period_start`) = 1),
  CONSTRAINT `monthly_interest_postings_nonnegative_rate` CHECK (`interest_rate` >= 0),
  CONSTRAINT `monthly_interest_postings_nonnegative_amount` CHECK (`amount` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

INSERT INTO monthly_interest_rates (`effective_period`, `rate`)
SELECT @monthly_interest_cutover_period, CAST(config_value AS DECIMAL(12,8))
FROM reward_config
WHERE config_key = 'monthly_interest_rate';

INSERT INTO customer_interest_eligibility (`customer`, `start_period`)
SELECT c.id, @monthly_interest_cutover_period
FROM customers c
WHERE c.boss = 0
AND c.deleted_at IS NULL;

UPDATE reward_config
SET description = 'Monatszins auf dem Kontostand am Monatsende; Gutschrift am ersten Tag des Folgemonats.'
WHERE config_key = 'monthly_interest_rate';

-- The posting ledger is now the sole record of completed interest periods.
-- Remove the obsolete login-triggered marker in the same production migration.
DELETE FROM customer_reward_state
WHERE state_key = 'monthly_interest_period';
