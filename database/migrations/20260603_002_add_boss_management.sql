ALTER TABLE customers
  ADD COLUMN `deleted_at` timestamp NULL DEFAULT NULL AFTER `realm`;

CREATE TABLE `reward_config` (
  `config_key` varchar(64) NOT NULL,
  `config_value` varchar(255) NOT NULL,
  `value_type` varchar(16) NOT NULL DEFAULT 'string',
  `label` varchar(128) NOT NULL,
  `description` varchar(255) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

INSERT INTO reward_config (`config_key`, `config_value`, `value_type`, `label`, `description`) VALUES
('monthly_interest_rate', '0.0008', 'decimal', 'Monatszins', 'Zins, der einmal pro Monat beim Login gutgeschrieben wird.'),
('savings_milestone_reward_rate', '0.0008', 'decimal', 'Spar-Level Zins', 'Einmaliger Zins beim Ueberschreiten eines Spar-Levels.'),
('input_lead_reward_rate', '0.0008', 'decimal', 'Ein/Aus Zins', 'Einmaliger Zins, wenn Einzahlungen Auszahlungen von unten ueberholen.'),
('savings_milestone_step', '100', 'decimal', 'Spar-Level Schritt', 'Kontostand-Schritt fuer Spar-Level Belohnungen.'),
('reward_deposit_enabled', 'true', 'boolean', 'Einzahlungs-Kiste', 'Goldene Kiste fuer neue Einzahlungen.'),
('reward_monthly_interest_enabled', 'true', 'boolean', 'Monatszins aktiv', 'Monatliche Zinsen und Kisten aktivieren.'),
('reward_savings_milestone_enabled', 'true', 'boolean', 'Spar-Level aktiv', 'Spar-Level Belohnungen aktivieren.'),
('reward_input_lead_enabled', 'true', 'boolean', 'Ein/Aus aktiv', 'Ein/Aus Belohnungen aktivieren.');
