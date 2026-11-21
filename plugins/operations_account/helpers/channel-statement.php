<?php

require_once dirname(__DIR__, 2) . '/users-manager/helpers/shared-account-assignments.php';

if (!function_exists('operations_account_normalize_statement_channel_key')) {
	function operations_account_normalize_statement_channel_key(string $channelType, string $channelKey): string
	{
		$channelKey = strtoupper(trim($channelKey));
		if ($channelType === 'ecash') {
			$sharedKey = users_manager_normalize_shared_account_key($channelKey);
			if ($sharedKey !== null) {
				return $sharedKey;
			}
			$channelKey = preg_replace('/^ECASH[\s_-]*/', '', $channelKey);
		}

		return trim((string)preg_replace('/-+/', '-', str_replace(['_', ' '], '-', $channelKey)), '-');
	}
}

if (!function_exists('operations_account_get_channel_statement')) {
	function operations_account_get_channel_statement(int $drawerId, string $channelType, string $channelKey): array
	{
		$channelType = strtolower(trim($channelType));
		if ($drawerId <= 0 || !in_array($channelType, ['cash', 'ecash'], true)) {
			throw new \InvalidArgumentException('Invalid cashdrawer channel statement request.');
		}

		$channelKey = operations_account_normalize_statement_channel_key($channelType, $channelKey);
		$isShared = $channelType === 'ecash' && users_manager_normalize_shared_account_key($channelKey) !== null;
		if ($channelKey === '' || ($channelType === 'cash' && !in_array($channelKey, ['CASH-DRAWER', 'CASH-CFA'], true))) {
			throw new \InvalidArgumentException('Unsupported cashdrawer channel.');
		}

		$db = new \Core\Database;
		if (!$db->table_exists(['cashdrawers'])) {
			throw new \RuntimeException('Cashdrawers are unavailable.');
		}
		$drawerRows = $db->query(
			"SELECT cd.id, cd.user_id, cd.date_created,
			        TRIM(CONCAT_WS(' ', NULLIF(u.first_name, ''), NULLIF(u.last_name, ''))) AS owner_name
			 FROM cashdrawers cd
			 LEFT JOIN users u ON u.id = cd.user_id
			 WHERE cd.id = :drawer_id
			 LIMIT 1",
			['drawer_id' => $drawerId]
		) ?: [];
		$drawer = $drawerRows[0] ?? null;
		if (!$drawer) {
			throw new \RuntimeException('Cashdrawer not found.');
		}

		$session = get_session();
		$viewerId = (int)($session->id ?? 0);
		$ownerId = (int)($drawer->user_id ?? 0);
		$roles = $session->roles ?? [];
		if (!is_array($roles)) {
			$roles = [$roles];
		}
		$sharedEligible = $isShared && users_manager_shared_account_roles_eligible($roles);
		$canView = user_can('view_wallet_details') || user_can('view_wallet')
			|| ($viewerId > 0 && $viewerId === $ownerId)
			|| $sharedEligible;
		if (!$canView) {
			http_response_code(403);
			throw new \RuntimeException('You are not allowed to view this cashdrawer statement.');
		}
		if ($isShared && !$sharedEligible) {
			http_response_code(403);
			throw new \RuntimeException('You are not eligible to view shared-account statements.');
		}

		$hasBalanceTable = $db->table_exists(['drawer_balances']);
		$balanceColumns = [];
		if ($hasBalanceTable) {
			$schema = $db->query('SHOW COLUMNS FROM drawer_balances') ?: [];
			if ($db->has_error) {
				throw new \RuntimeException('Unable to inspect channel balance records: ' . (string)$db->error);
			}
			foreach ($schema as $column) {
				$balanceColumns[strtolower((string)($column->Field ?? ''))] = true;
			}
		}
		$balanceRows = [];
		if ($hasBalanceTable) {
			$params = ['channel_type' => $channelType];
			$userFilter = '';
			if (!$isShared) {
				$userFilter = ' AND b.user_id = :user_id AND cd.user_id = :drawer_owner_id';
				$params['user_id'] = $ownerId;
				$params['drawer_owner_id'] = $ownerId;
			}
			$actorJoin = !empty($balanceColumns['created_by'])
				? ' LEFT JOIN users actor ON actor.id = b.created_by'
				: '';
			$actorName = !empty($balanceColumns['created_by'])
				? "TRIM(CONCAT_WS(' ', NULLIF(actor.first_name, ''), NULLIF(actor.last_name, '')))"
				: "''";
			$balanceDate = !empty($balanceColumns['date_updated'])
				? 'COALESCE(b.date_updated, b.date_created, cd.date_created)'
				: 'COALESCE(b.date_created, cd.date_created)';
			$balanceRows = $db->query(
				"SELECT b.id, b.drawer_id, b.user_id, b.channel_key, b.opening_balance,
				        b.credit, b.debit, b.closing_balance, b.trans_id, b.trans_status,
				        {$balanceDate} AS movement_date,
				        {$actorName} AS actor_name
				 FROM drawer_balances b
				 INNER JOIN cashdrawers cd ON cd.id = b.drawer_id
				 {$actorJoin}
				 WHERE b.channel_type = :channel_type{$userFilter}
				 ORDER BY {$balanceDate}, b.id",
				$params
			) ?: [];
			if ($db->has_error) {
				throw new \RuntimeException('Unable to load channel balance records: ' . (string)$db->error);
			}
		}

		$entries = [];
		if ($channelType === 'ecash') {
			foreach ($balanceRows as $record) {
				if (operations_account_normalize_statement_channel_key('ecash', (string)($record->channel_key ?? '')) !== $channelKey) {
					continue;
				}
				if (!$isShared && (int)($record->user_id ?? 0) !== $ownerId) {
					continue;
				}
				$entries[] = [
					'date' => (string)($record->movement_date ?? $record->date_created ?? $record->drawer_date ?? ''),
					'drawer_id' => (int)($record->drawer_id ?? 0),
					'reference' => !empty($record->trans_id) ? (string)$record->trans_id : '',
					'status' => trim((string)($record->trans_status ?? '')) ?: 'Recorded',
					'actor' => trim((string)($record->actor_name ?? '')),
					'description' => 'E-cash channel balance record',
					'denomination' => '',
					'count' => '',
					'opening' => (float)($record->opening_balance ?? 0),
					'credit' => (float)($record->credit ?? 0),
					'debit' => (float)($record->debit ?? 0),
					'closing' => (float)($record->closing_balance ?? (
						(float)($record->opening_balance ?? 0)
						+ (float)($record->credit ?? 0)
						- (float)($record->debit ?? 0)
					)),
				];
			}
		} else {
			$cashTable = $channelKey === 'CASH-CFA' ? 'drawer_fx_denos' : 'drawer_denos';
			if ($db->table_exists([$cashTable])) {
				$cashColumns = [];
				$cashSchema = $db->query("SHOW COLUMNS FROM `{$cashTable}`") ?: [];
				if ($db->has_error) {
					throw new \RuntimeException('Unable to inspect cash channel movements: ' . (string)$db->error);
				}
				foreach ($cashSchema as $column) {
					$cashColumns[strtolower((string)($column->Field ?? ''))] = true;
				}
				$cashActorJoin = !empty($cashColumns['created_by'])
					? ' LEFT JOIN users actor ON actor.id = f.created_by'
					: '';
				$cashActorName = !empty($cashColumns['created_by'])
					? "TRIM(CONCAT_WS(' ', NULLIF(actor.first_name, ''), NULLIF(actor.last_name, '')))"
					: "''";
				$cashSelect = [];
				foreach (['id', 'drawer_id', 'user_id', 'denomination', 'count', 'volume', 'date_created', 'trans_id', 'trans_status', 'created_by'] as $columnName) {
					$cashSelect[] = !empty($cashColumns[$columnName]) ? "f.`{$columnName}`" : "NULL AS `{$columnName}`";
				}
				$currencyFilter = $cashTable === 'drawer_fx_denos' ? " AND f.currency = 'CFA'" : '';
				$cashRows = $db->query(
					"SELECT " . implode(', ', $cashSelect) . ",
					        cd.date_created AS drawer_date,
					        {$cashActorName} AS actor_name
					 FROM `{$cashTable}` f
					 INNER JOIN cashdrawers cd ON cd.id = f.drawer_id
					 {$cashActorJoin}
					 WHERE f.user_id = :user_id
					   AND cd.user_id = :drawer_owner_id{$currencyFilter}
					 ORDER BY COALESCE(f.date_created, cd.date_created), f.id",
					['user_id' => $ownerId, 'drawer_owner_id' => $ownerId]
				) ?: [];
				if ($db->has_error) {
					throw new \RuntimeException('Unable to load cash channel movements: ' . (string)$db->error);
				}
				foreach ($cashRows as $record) {
					$volume = (float)($record->volume ?? 0);
					$status = trim((string)($record->trans_status ?? '')) ?: 'Recorded';
					$isOpening = stripos($status, 'opening') !== false;
					$entries[] = [
						'date' => (string)($record->date_created ?? $record->drawer_date ?? ''),
						'drawer_id' => (int)($record->drawer_id ?? 0),
						'reference' => !empty($record->trans_id) ? (string)$record->trans_id : '',
						'status' => $status,
						'actor' => trim((string)($record->actor_name ?? '')),
						'description' => $isOpening ? 'Opening balance snapshot' : 'Cash denomination movement',
						'denomination' => (float)($record->denomination ?? 0),
						'count' => (int)($record->count ?? 0),
						'opening' => $isOpening ? $volume : 0.0,
						'credit' => !$isOpening && $volume > 0 ? $volume : 0.0,
						'debit' => !$isOpening && $volume < 0 ? abs($volume) : 0.0,
						'closing' => null,
					];
				}
			}
		}

		$creditTotal = 0.0;
		$debitTotal = 0.0;
		$openingTotal = 0.0;
		$runningBalance = 0.0;
		$runningDrawerId = 0;
		if ($channelType === 'cash') {
			foreach ($entries as &$entry) {
				$entryDrawerId = (int)$entry['drawer_id'];
				if ($runningDrawerId !== $entryDrawerId) {
					$runningDrawerId = $entryDrawerId;
					$runningBalance = 0.0;
				}
				$runningBalance += (float)$entry['opening'] + (float)$entry['credit'] - (float)$entry['debit'];
				$entry['closing'] = $runningBalance;
			}
			unset($entry);
		}
		foreach ($entries as $entry) {
			$creditTotal += (float)$entry['credit'];
			$debitTotal += (float)$entry['debit'];
			$openingTotal += (float)$entry['opening'];
		}
		$channelLabel = str_replace('-', ' ', $channelKey);

		return [
			'drawer_id' => $drawerId,
			'owner_id' => $ownerId,
			'owner_name' => trim((string)($drawer->owner_name ?? '')) ?: 'User #' . $ownerId,
			'channel_type' => $channelType,
			'channel_key' => $channelKey,
			'channel_label' => $channelLabel,
			'is_shared' => $isShared,
			'entries' => $entries,
			'opening_total' => $openingTotal,
			'credit_total' => $creditTotal,
			'debit_total' => $debitTotal,
			'closing_total' => $entries ? (float)($entries[count($entries) - 1]['closing'] ?? 0) : 0.0,
		];
	}
}
