<?php

if (!function_exists('operations_account_ensure_channel_movement_history_schema')) {
	function operations_account_ensure_channel_movement_history_schema(\Core\Database $db): void
	{
		static $ensured = false;
		if ($ensured) {
			return;
		}
		$ensured = true;

		if (!$db->table_exists(['drawer_balances'])) {
			return;
		}

		$db->query(
			"CREATE TABLE IF NOT EXISTS drawer_balance_movements (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				drawer_id BIGINT UNSIGNED NOT NULL,
				user_id BIGINT UNSIGNED NOT NULL,
				channel_type VARCHAR(20) NOT NULL,
				channel_key VARCHAR(64) NOT NULL,
				trans_id BIGINT UNSIGNED NOT NULL,
				trans_status VARCHAR(50) NOT NULL DEFAULT '',
				date_created DATETIME NOT NULL,
				balance_before DECIMAL(18,2) NOT NULL DEFAULT 0.00,
				credit DECIMAL(18,2) NOT NULL DEFAULT 0.00,
				debit DECIMAL(18,2) NOT NULL DEFAULT 0.00,
				balance_after DECIMAL(18,2) NOT NULL DEFAULT 0.00,
				PRIMARY KEY (id),
				UNIQUE KEY drawer_balance_movement_transaction (drawer_id, channel_type, channel_key, trans_id),
				KEY drawer_balance_movement_statement (channel_type, channel_key, date_created, drawer_id)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
		);
		if ($db->has_error) {
			throw new \RuntimeException('Unable to prepare transaction-level cashdrawer history: ' . (string)$db->error);
		}

		$triggerRows = $db->query(
			"SELECT TRIGGER_NAME
			 FROM information_schema.TRIGGERS
			 WHERE TRIGGER_SCHEMA = DATABASE()
			   AND TRIGGER_NAME IN ('operations_account_channel_balance_ai', 'operations_account_channel_balance_au')"
		) ?: [];
		if ($db->has_error) {
			throw new \RuntimeException('Unable to inspect transaction-level cashdrawer history triggers: ' . (string)$db->error);
		}
		$triggers = [];
		foreach ($triggerRows as $triggerRow) {
			$triggers[(string)($triggerRow->TRIGGER_NAME ?? '')] = true;
		}

		$triggerStatements = [
			'operations_account_channel_balance_ai' => "CREATE TRIGGER operations_account_channel_balance_ai
				AFTER INSERT ON drawer_balances
				FOR EACH ROW
				BEGIN
					IF LOWER(TRIM(COALESCE(NEW.channel_type, ''))) = 'ecash'
						AND COALESCE(NEW.drawer_id, 0) > 0
						AND COALESCE(NEW.user_id, 0) > 0
						AND COALESCE(NEW.trans_id, 0) > 0
						AND LOWER(TRIM(COALESCE(NEW.trans_status, ''))) NOT IN ('opening snapshot', 'opening cfa snapshot')
						AND ABS(COALESCE(NEW.closing_balance, 0) - COALESCE(NEW.opening_balance, 0)) > 0.00001 THEN
						INSERT INTO drawer_balance_movements
							(drawer_id, user_id, channel_type, channel_key, trans_id, trans_status, date_created, balance_before, credit, debit, balance_after)
						VALUES
							(NEW.drawer_id, NEW.user_id, NEW.channel_type, COALESCE(NEW.channel_key, ''), NEW.trans_id, COALESCE(NEW.trans_status, ''), COALESCE(NEW.date_created, NOW()), COALESCE(NEW.opening_balance, 0),
							 GREATEST(COALESCE(NEW.closing_balance, 0) - COALESCE(NEW.opening_balance, 0), 0),
							 GREATEST(COALESCE(NEW.opening_balance, 0) - COALESCE(NEW.closing_balance, 0), 0),
							 COALESCE(NEW.closing_balance, 0))
						ON DUPLICATE KEY UPDATE
							date_created = VALUES(date_created),
							trans_status = VALUES(trans_status),
							balance_after = VALUES(balance_after),
							credit = IF(VALUES(balance_after) >= balance_before, VALUES(balance_after) - balance_before, 0),
							debit = IF(VALUES(balance_after) < balance_before, balance_before - VALUES(balance_after), 0);
					END IF;
				END",
			'operations_account_channel_balance_au' => "CREATE TRIGGER operations_account_channel_balance_au
				AFTER UPDATE ON drawer_balances
				FOR EACH ROW
				BEGIN
					IF LOWER(TRIM(COALESCE(NEW.channel_type, ''))) = 'ecash'
						AND COALESCE(NEW.drawer_id, 0) > 0
						AND COALESCE(NEW.user_id, 0) > 0
						AND COALESCE(NEW.trans_id, 0) > 0
						AND LOWER(TRIM(COALESCE(NEW.trans_status, ''))) NOT IN ('opening snapshot', 'opening cfa snapshot')
						AND ABS(COALESCE(NEW.closing_balance, 0) - COALESCE(OLD.closing_balance, 0)) > 0.00001 THEN
						INSERT INTO drawer_balance_movements
							(drawer_id, user_id, channel_type, channel_key, trans_id, trans_status, date_created, balance_before, credit, debit, balance_after)
						VALUES
							(NEW.drawer_id, NEW.user_id, NEW.channel_type, COALESCE(NEW.channel_key, ''), NEW.trans_id, COALESCE(NEW.trans_status, ''), NOW(), COALESCE(OLD.closing_balance, 0),
							 GREATEST(COALESCE(NEW.closing_balance, 0) - COALESCE(OLD.closing_balance, 0), 0),
							 GREATEST(COALESCE(OLD.closing_balance, 0) - COALESCE(NEW.closing_balance, 0), 0),
							 COALESCE(NEW.closing_balance, 0))
						ON DUPLICATE KEY UPDATE
							date_created = VALUES(date_created),
							trans_status = VALUES(trans_status),
							balance_after = VALUES(balance_after),
							credit = IF(VALUES(balance_after) >= balance_before, VALUES(balance_after) - balance_before, 0),
							debit = IF(VALUES(balance_after) < balance_before, balance_before - VALUES(balance_after), 0);
					END IF;
				END",
		];

		foreach ($triggerStatements as $triggerName => $statement) {
			if (isset($triggers[$triggerName])) {
				continue;
			}
			$db->query($statement);
			if ($db->has_error) {
				throw new \RuntimeException('Unable to enable transaction-level cashdrawer history: ' . (string)$db->error);
			}
		}
	}
}
