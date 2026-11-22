<?php

if (!function_exists('operations_account_ensure_cashdrawer_edit_approval_schema')) {
	function operations_account_ensure_cashdrawer_edit_approval_schema(\Core\Database $db): void
	{
		$db->query(
			"CREATE TABLE IF NOT EXISTS cashdrawer_edit_approval_requests (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				drawer_id BIGINT UNSIGNED NOT NULL,
				requested_by BIGINT UNSIGNED NOT NULL,
				assigned_admin_id BIGINT UNSIGNED NULL,
				request_data LONGTEXT NOT NULL,
				proof_data LONGTEXT NOT NULL,
				base_date_updated DATETIME NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'pending',
				reviewed_by BIGINT UNSIGNED NULL,
				reviewed_at DATETIME NULL,
				review_note TEXT NULL,
				date_created DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY idx_cashdrawer_edit_approval_status (status, date_created),
				KEY idx_cashdrawer_edit_approval_drawer (drawer_id, status),
				KEY idx_cashdrawer_edit_approval_admin (assigned_admin_id, status)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
		);
		if ($db->has_error) {
			throw new \RuntimeException('Unable to prepare cashdrawer edit approvals: ' . (string)$db->error);
		}
		$assignedAdminColumn = $db->query("SHOW COLUMNS FROM cashdrawer_edit_approval_requests LIKE 'assigned_admin_id'") ?: [];
		if ($db->has_error) {
			throw new \RuntimeException('Unable to inspect cashdrawer approval assignments: ' . (string)$db->error);
		}
		if (empty($assignedAdminColumn)) {
			$db->query("ALTER TABLE cashdrawer_edit_approval_requests ADD COLUMN assigned_admin_id BIGINT UNSIGNED NULL AFTER requested_by");
			if ($db->has_error) {
				throw new \RuntimeException('Unable to add cashdrawer approval assignments: ' . (string)$db->error);
			}
		}
	}
}

if (!function_exists('operations_account_list_cashdrawer_approval_admins')) {
	function operations_account_list_cashdrawer_approval_admins(\Core\Database $db): array
	{
		$timezone = new \DateTimeZone('Africa/Accra');
		$today = new \DateTimeImmutable('now', $timezone);
		$dayStart = $today->setTime(0, 0)->format('Y-m-d H:i:s');
		$dayEnd = $today->modify('+1 day')->setTime(0, 0)->format('Y-m-d H:i:s');
		$candidates = $db->query(
			"SELECT u.id, u.first_name, u.last_name, MAX(own_drawer.date_created) AS latest_drawer_created
			 FROM users u
			 INNER JOIN user_roles_map urm ON urm.user_id = u.id AND COALESCE(urm.disabled, 0) = 0
			 INNER JOIN user_roles ur ON ur.id = urm.role_id AND COALESCE(ur.disabled, 0) = 0
			 INNER JOIN cashdrawers own_drawer
			   ON own_drawer.user_id = u.id AND own_drawer.created_by = u.id
			  AND own_drawer.date_created >= :day_start AND own_drawer.date_created < :day_end
			 WHERE COALESCE(u.deleted, 0) = 0
			   AND LOWER(TRIM(ur.role)) IN ('admin', 'is_admin', 'administrator', 'is_administrator')
			 GROUP BY u.id
			 ORDER BY latest_drawer_created DESC, u.id ASC",
			['day_start' => $dayStart, 'day_end' => $dayEnd]
		) ?: [];
		if ($db->has_error) {
			throw new \RuntimeException('Unable to find an eligible cashdrawer approver: ' . (string)$db->error);
		}
		if (empty($candidates)) {
			return [];
		}

		$whatsappHelper = realpath(__DIR__ . '/../../backoffice/helpers/whatsapp-notifications.php');
		if (!$whatsappHelper || !is_file($whatsappHelper)) {
			throw new \RuntimeException('The WhatsApp notification helper is unavailable.');
		}
		require_once $whatsappHelper;
		if (!function_exists('backoffice_whatsapp_phone')) {
			throw new \RuntimeException('The WhatsApp contact resolver is unavailable.');
		}

		$eligibleAdmins = [];
		foreach ($candidates as $candidate) {
			$adminId = (int)($candidate->id ?? 0);
			$phone = backoffice_whatsapp_phone($adminId);
			if ($adminId > 0 && trim((string)($phone['phone'] ?? '')) !== '') {
				$name = trim((string)($candidate->first_name ?? '') . ' ' . (string)($candidate->last_name ?? ''));
				$eligibleAdmins[] = [
					'id' => $adminId,
					'name' => $name !== '' ? $name : 'User #' . $adminId,
				];
			}
		}

		return $eligibleAdmins;
	}
}

if (!function_exists('operations_account_find_cashdrawer_approval_admin')) {
	function operations_account_find_cashdrawer_approval_admin(\Core\Database $db): int
	{
		$eligibleAdmins = operations_account_list_cashdrawer_approval_admins($db);
		return (int)($eligibleAdmins[0]['id'] ?? 0);
	}
}

if (!function_exists('operations_account_expire_old_cashdrawer_edit_approvals')) {
	function operations_account_expire_old_cashdrawer_edit_approvals(\Core\Database $db): void
	{
		$requests = $db->query(
			"SELECT r.id, c.date_created
			 FROM cashdrawer_edit_approval_requests r
			 LEFT JOIN cashdrawers c ON c.id = r.drawer_id
			 WHERE r.status = 'pending'"
		) ?: [];
		if ($db->has_error) {
			throw new \RuntimeException('Unable to check pending cashdrawer edit dates: ' . (string)$db->error);
		}

		$now = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Accra')))->format('Y-m-d H:i:s');
		foreach ($requests as $request) {
			if (operations_account_is_current_cashdrawer($request)) {
				continue;
			}
			$db->query(
				"UPDATE cashdrawer_edit_approval_requests
				 SET status = 'expired', reviewed_at = :reviewed_at,
				     review_note = 'Expired because only current-day cashdrawers can be edited.'
				 WHERE id = :id AND status = 'pending'",
				['reviewed_at' => $now, 'id' => (int)($request->id ?? 0)]
			);
			if ($db->has_error) {
				throw new \RuntimeException('Unable to expire an outdated cashdrawer edit request: ' . (string)$db->error);
			}
		}
	}
}

if (!function_exists('operations_account_notify_cashdrawer_edit_approval_admin')) {
	function operations_account_notify_cashdrawer_edit_approval_admin(int $requestId, int $drawerId, int $requesterId, int $adminId): array
	{
		$result = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
		try {
			$db = new \Core\Database;
			$whatsappHelper = realpath(__DIR__ . '/../../backoffice/helpers/whatsapp-notifications.php');
			if (!$whatsappHelper || !is_file($whatsappHelper)) {
				throw new \RuntimeException('The WhatsApp notification helper is unavailable.');
			}
			require_once $whatsappHelper;
			if (!function_exists('backoffice_whatsapp_phone') || !function_exists('backoffice_whatsapp_send')) {
				throw new \RuntimeException('The WhatsApp notification service is unavailable.');
			}

			$phoneResult = backoffice_whatsapp_phone($adminId);
			$phone = trim((string)($phoneResult['phone'] ?? ''));
			if ($phone === '') {
				$result['failed']++;
				error_log('[CashdrawerEditApproval] WhatsApp notification failed: assigned administrator has no valid WhatsApp contact.');
				return $result;
			}

			$requesterRows = $db->query(
				"SELECT first_name, last_name FROM users WHERE id = :id LIMIT 1",
				['id' => $requesterId]
			) ?: [];
			$requesterName = trim((string)($requesterRows[0]->first_name ?? '') . ' ' . (string)($requesterRows[0]->last_name ?? ''));
			if ($requesterName === '') {
				$requesterName = 'User #' . $requesterId;
			}
			$approvalUrl = rtrim((string)ROOT, '/') . '/admin/operations_accounts/cashdrawer_edit_approvals?request_id=' . $requestId;
			$message = "Cashdrawer edit requires your approval.\n"
				. 'Drawer: #' . $drawerId . "\n"
				. 'Requested by: ' . $requesterName . "\n"
				. 'Request: #' . $requestId . "\n"
				. 'Review: ' . $approvalUrl;

			$delivery = backoffice_whatsapp_send($phone, $message);
			if (!empty($delivery['sent'])) {
				$result['sent']++;
			} else {
				$result['failed']++;
				error_log('[CashdrawerEditApproval] WhatsApp notification failed: ' . (string)($delivery['reason'] ?? 'Delivery failed.'));
			}
		} catch (\Throwable $e) {
			$result['failed']++;
			error_log('[CashdrawerEditApproval] WhatsApp notification error: ' . $e->getMessage());
		}

		return $result;
	}
}

if (!function_exists('operations_account_cashdrawer_approval_current_values')) {
	function operations_account_cashdrawer_approval_current_values(\Core\Database $db, int $drawerId): array
	{
			$drawerRows = $db->query(
				"SELECT id, user_id, currency, amount FROM cashdrawers WHERE id = :id LIMIT 1",
				['id' => $drawerId]
			) ?: [];
			if ($db->has_error) {
				throw new \RuntimeException('Unable to load current cashdrawer values: ' . (string)$db->error);
			}
			$drawer = $drawerRows[0] ?? null;
			$current = [
				'found' => (bool)$drawer,
				'user_id' => (int)($drawer->user_id ?? 0),
				'currency' => (string)($drawer->currency ?? 'GHS'),
				'amount' => (float)($drawer->amount ?? 0),
				'ghs_denominations' => [],
				'cfa_denominations' => [],
				'ecash' => [],
				'rates' => [],
			];
			if (!$drawer) {
				return $current;
			}

			$loadDenominations = static function(string $table, string $status, ?string $currency = null) use ($db, $drawerId, $current): array {
				if (!$db->table_exists([$table])) {
					return [];
				}
				$sql = "SELECT denomination, SUM(`count`) AS total_count FROM {$table}
					WHERE drawer_id = :drawer_id AND user_id = :user_id
					  AND LOWER(TRIM(COALESCE(trans_status, ''))) = :status";
				$params = ['drawer_id' => $drawerId, 'user_id' => $current['user_id'], 'status' => $status];
				if ($currency !== null) {
					$sql .= ' AND currency = :currency';
					$params['currency'] = $currency;
				}
				$sql .= ' GROUP BY denomination';
				$rows = $db->query($sql, $params) ?: [];
				if ($db->has_error) {
					throw new \RuntimeException("Unable to load current {$table} values: " . (string)$db->error);
				}
				$values = [];
				foreach ($rows as $item) {
					$denomination = number_format((float)($item->denomination ?? 0), 2, '.', '');
					if ((float)$denomination > 0) {
						$values[$denomination] = (int)round((float)($item->total_count ?? 0));
					}
				}
				return $values;
			};
			$current['ghs_denominations'] = $loadDenominations('drawer_denos', 'opening snapshot');
			$current['cfa_denominations'] = $loadDenominations('drawer_fx_denos', 'opening cfa snapshot', 'CFA');

			if ($db->table_exists(['drawer_balances'])) {
				$rows = $db->query(
					"SELECT b.channel_key, b.opening_balance
					 FROM drawer_balances b
					 INNER JOIN (
						SELECT channel_key, MAX(id) AS max_id
						FROM drawer_balances
						WHERE drawer_id = :drawer_id AND user_id = :user_id AND channel_type = 'ecash'
						GROUP BY channel_key
					 ) latest ON latest.max_id = b.id",
					['drawer_id' => $drawerId, 'user_id' => $current['user_id']]
				) ?: [];
				if ($db->has_error) {
					throw new \RuntimeException('Unable to load current e-cash balances: ' . (string)$db->error);
				}
				foreach ($rows as $item) {
					$key = strtoupper(trim((string)($item->channel_key ?? '')));
					if ($key !== '') {
						$current['ecash'][$key] = (float)($item->opening_balance ?? 0);
					}
				}
			}
			if ($db->table_exists(['settings'])) {
				$rateRows = $db->query(
					"SELECT buying_rate, selling_rate FROM settings ORDER BY id DESC LIMIT 1"
				) ?: [];
				if ($db->has_error) {
					throw new \RuntimeException('Unable to load current CFA rates: ' . (string)$db->error);
				}
				if (!empty($rateRows)) {
					$current['rates'] = [
						'buying_rate' => (float)($rateRows[0]->buying_rate ?? 0),
						'selling_rate' => (float)($rateRows[0]->selling_rate ?? 0),
					];
				}
			}

		return $current;
	}
}
