ALTER TABLE transactions
  ADD COLUMN `kind` varchar(32) NOT NULL DEFAULT 'manual' AFTER `balance`,
  ADD COLUMN `note` varchar(255) DEFAULT NULL AFTER `kind`;

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

INSERT INTO customer_reward_state (`customer`, `state_key`, `state_value`)
SELECT c.id, 'savings_level', CAST(FLOOR(GREATEST(COALESCE(SUM(CASE WHEN t.undone = 0 AND t.approved = 1 THEN t.amount ELSE 0 END), 0), 0) / 100) AS CHAR)
FROM customers c
LEFT JOIN transactions t ON t.customer = c.id
WHERE c.boss = 0
GROUP BY c.id;

INSERT INTO customer_reward_state (`customer`, `state_key`, `state_value`)
SELECT c.id, 'input_lead_active',
  CASE
    WHEN COALESCE(SUM(CASE WHEN t.undone = 0 AND t.approved = 1 AND t.kind = 'manual' AND t.amount >= 0 THEN 1 ELSE 0 END), 0) >
      COALESCE(SUM(CASE WHEN t.undone = 0 AND t.approved = 1 AND t.kind = 'manual' AND t.amount < 0 THEN 1 ELSE 0 END), 0)
    THEN '1'
    ELSE '0'
  END
FROM customers c
LEFT JOIN transactions t ON t.customer = c.id
WHERE c.boss = 0
GROUP BY c.id;
