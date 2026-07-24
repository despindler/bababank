-- Sanitized configuration for deterministic local tests.
-- This file intentionally contains no production identities or transactions.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
START TRANSACTION;

INSERT INTO reward_config (`config_key`, `config_value`, `value_type`, `label`, `description`) VALUES
('monthly_interest_rate', '0.0008', 'decimal', 'Monatszins', 'Monatszins auf dem Kontostand am Monatsende; Gutschrift am ersten Tag des Folgemonats.'),
('savings_milestone_reward_rate', '0.0008', 'decimal', 'Spar-Level Zins', 'Einmaliger Zins beim Ueberschreiten eines Spar-Levels.'),
('input_lead_reward_rate', '0.0008', 'decimal', 'Ein/Aus Zins', 'Einmaliger Zins, wenn Einzahlungen Auszahlungen von unten ueberholen.'),
('savings_milestone_step', '100', 'decimal', 'Spar-Level Schritt', 'Kontostand-Schritt fuer Spar-Level Belohnungen.'),
('reward_deposit_enabled', 'true', 'boolean', 'Einzahlungs-Kiste', 'Goldene Kiste fuer neue Einzahlungen.'),
('reward_monthly_interest_enabled', 'true', 'boolean', 'Monatszins aktiv', 'Monatliche Zinsen und Kisten aktivieren.'),
('reward_savings_milestone_enabled', 'true', 'boolean', 'Spar-Level aktiv', 'Spar-Level Belohnungen aktivieren.'),
('reward_input_lead_enabled', 'true', 'boolean', 'Ein/Aus aktiv', 'Ein/Aus Belohnungen aktivieren.');

INSERT INTO monthly_interest_rates (`effective_period`, `rate`) VALUES
('2026-08-01', '0.00080000');

COMMIT;
