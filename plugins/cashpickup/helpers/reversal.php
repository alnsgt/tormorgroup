<?php

namespace CashpickupReversals;

use \Core\Database;

function ensure_cashdrawer_reversal_table(Database $db): void
{
	$db->query(
		"CREATE TABLE IF NOT EXISTS cashpickup_cashdrawer_reversals (
			cashpickup_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
			movement_type VARCHAR(16) NOT NULL,
			date_created DATETIME NOT NULL,
			created_by INT UNSIGNED NOT NULL
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
	);
	if ($db->has_error) {
		throw new \RuntimeException('Unable to prepare cashpickup cashdrawer reversal tracking.');
	}
}

function record_cashdrawer_reversal(Database $db, int $transId, int $actorId, string $movementType): void
{
	$db->query(
		"INSERT INTO cashpickup_cashdrawer_reversals (cashpickup_id, movement_type, date_created, created_by)
		 VALUES (:cashpickup_id, :movement_type, :date_created, :created_by)",
		[
			'cashpickup_id' => $transId,
			'movement_type' => $movementType,
			'date_created' => date('Y-m-d H:i:s'),
			'created_by' => max(0, $actorId),
		]
	);
	if ($db->has_error || $db->affected_rows !== 1) {
		throw new \RuntimeException('Unable to record the cashdrawer reversal.');
	}
}

function reverse_approved_cashdrawer_effects(Database $db, object $row, int $actorId, bool $repairCancelled = false): void
{
	$approvalStatus = strtolower(trim((string)($row->approval_status ?? '')));
	$transactionStatus = strtolower(trim((string)($row->transaction_status ?? '')));
	$isCancelled = preg_match('/cancelled|canceled/i', $approvalStatus . ' ' . $transactionStatus) === 1;
	$isApproved = $approvalStatus === 'approved' || $transactionStatus === 'approved';
	$id = (int)($row->id ?? 0);
	$drawerUserId = (int)($row->created_by ?? 0);
	$side = strtolower(trim((string)($row->transaction_side ?? '')));
	$source = strtolower(trim((string)($row->source ?? '')));
	$paymentMethod = strtolower(trim((string)($row->payment_method ?? '')));

	if ($id <= 0) {
		throw new \RuntimeException('Unable to identify the approved cashpickup cashdrawer movement.');
	}
	if (!$isApproved && !($repairCancelled && $isCancelled)) {
		return;
	}
	if (!$db->table_exists(['cashpickup_cashdrawer_reversals'])) {
		throw new \RuntimeException('Cashpickup cashdrawer reversal tracking is unavailable.');
	}
	$reversalRows = $db->query(
		"SELECT cashpickup_id FROM cashpickup_cashdrawer_reversals
		 WHERE cashpickup_id = :cashpickup_id LIMIT 1 FOR UPDATE",
		['cashpickup_id' => $id]
	) ?: [];
	if ($db->has_error) {
		throw new \RuntimeException('Unable to verify the cashdrawer reversal status.');
	}
	if (!empty($reversalRows)) {
		return;
	}
	if ($source === 'customer_transfer'
		|| $paymentMethod === 'customer_transfer'
		|| ($side === 'receive' && $source === 'credit')) {
		return;
	}
	if ($repairCancelled
		&& (!$isCancelled
			|| (empty($row->approved_at) && (int)($row->approved_by ?? 0) <= 0))) {
		return;
	}
	if ($drawerUserId <= 0) {
		throw new \RuntimeException('Unable to identify the cashdrawer owner for this approved cashpickup.');
	}

	$cashTables = [];
	foreach (['drawer_denos', 'drawer_fx_denos'] as $cashTable) {
		if (!$db->table_exists([$cashTable])) {
			continue;
		}
		$originalRows = $db->query(
			"SELECT id FROM {$cashTable}
			 WHERE trans_id = :trans_id AND user_id = :user_id
			   AND (
			   	LOWER(TRIM(COALESCE(trans_status,''))) = 'approved'
			   	OR LOWER(TRIM(COALESCE(trans_status,''))) LIKE '%: approved'
			   )
			 LIMIT 1",
			['trans_id' => $id, 'user_id' => $drawerUserId]
		) ?: [];
		if ($db->has_error) {
			throw new \RuntimeException('Unable to verify the approved cashdrawer denomination movement.');
		}
		if (!empty($originalRows)) {
			$cashTables[] = $cashTable;
		}
	}

	if (!empty($cashTables)) {
		$bridgePath = realpath(__DIR__ . '/../../backoffice/helpers/cashdrawer-bridge.php');
		if (!$bridgePath || !file_exists($bridgePath)) {
			throw new \RuntimeException('Unable to load the cashdrawer reversal service.');
		}
		require_once $bridgePath;
		\BackOfficeCashdrawer\loadCfaHelpers();
		if (!function_exists('Cfas\\cfa_reverse_transaction_entries')) {
			throw new \RuntimeException('The cashdrawer reversal service is unavailable.');
		}

		$result = \Cfas\cfa_reverse_transaction_entries(
			$id,
			$actorId,
			date('Y-m-d H:i:s'),
			$drawerUserId,
			$cashTables,
			'Cashpickup reversal',
			'Approved',
			true
		);
		if (empty($result['ok'])) {
			throw new \RuntimeException((string)($result['message'] ?? 'Unable to reverse the cashdrawer denomination movement.'));
		}
		record_cashdrawer_reversal($db, $id, $actorId, 'cash');
		return;
	}

	$expectsCash = ($side === 'receive' && in_array($source, ['', 'cash'], true))
		|| ($side === 'pay' && in_array($paymentMethod, ['cash', 'cash_ghs', 'cash_cfa'], true));
	if ($expectsCash) {
		throw new \RuntimeException('The approved cashdrawer denomination movement could not be found; cancellation was not applied.');
	}

	$channel = '';
	$amount = 0.0;
	$reversalDirection = '';
	$additionalChannelCandidates = [];
	if ($side === 'receive' && in_array($source, ['mtn', 'telecel', 'at', 'bank'], true)) {
		if ($source === 'bank') {
			$bankName = trim((string)($row->account_paid_to ?? ''));
			$bankSlug = trim((string)preg_replace('/[^A-Z0-9]+/i', '_', $bankName), '_');
			$channel = $bankSlug !== '' ? 'ECASH_' . $bankSlug : '';
			$additionalChannelCandidates = [$bankName, $bankSlug];
		} else {
			$channel = strtoupper($source);
		}
		$amount = (float)($row->amount_involved ?? 0);
		$reversalDirection = 'out';
	} elseif ($side === 'pay' && in_array($paymentMethod, ['transfer', 'transfer_telcos', 'transfer_bank'], true)) {
		$channel = strtoupper(trim((string)($row->payout_channel ?? '')));
		$amount = (float)($row->amount_paid_out ?? 0);
		$reversalDirection = 'in';
	} else {
		throw new \RuntimeException('Unable to determine the cashdrawer movement for this approved cashpickup.');
	}

	if ($channel === '' || $amount <= 0 || !$db->table_exists(['drawer_balances'])) {
		throw new \RuntimeException('Unable to identify the approved e-cash cashdrawer movement to reverse.');
	}

	$normalizeChannel = static function (string $value): string {
		return preg_replace('/[^A-Z0-9]+/', '', strtoupper(trim($value))) ?: '';
	};
	$channelSlug = trim((string)preg_replace('/[^A-Z0-9]+/i', '_', $channel), '_');
	$channelCandidates = array_unique(array_merge([
		$channel,
		'ECASH_' . $channel,
		'ECASH_' . $channelSlug,
	], $additionalChannelCandidates));
	$normalizedCandidates = array_fill_keys(array_filter(array_map($normalizeChannel, $channelCandidates)), true);

	$balanceRows = $db->query(
		"SELECT id, drawer_id, user_id, channel_key, opening_balance, credit, debit, trans_id, trans_status
		 FROM drawer_balances
		 WHERE trans_id = :trans_id AND user_id = :user_id AND channel_type = 'ecash'
		 ORDER BY id DESC
		 FOR UPDATE",
		['trans_id' => $id, 'user_id' => $drawerUserId]
	) ?: [];
	if ($db->has_error) {
		throw new \RuntimeException('Unable to locate the approved e-cash cashdrawer movement.');
	}

	$balanceRow = null;
	foreach ($balanceRows as $candidate) {
		if (isset($normalizedCandidates[$normalizeChannel((string)($candidate->channel_key ?? ''))])) {
			$balanceRow = $candidate;
			break;
		}
	}

	if (!$balanceRow) {
		$approvedAt = trim((string)($row->approved_at ?? ''));
		$approvedDate = preg_match('/^\d{4}-\d{2}-\d{2}/', $approvedAt) ? substr($approvedAt, 0, 10) : date('Y-m-d');
		$drawerRows = $db->query(
			"SELECT id, user_id FROM cashdrawers
			 WHERE user_id = :user_id AND DATE(date_created) = :approved_date
			   AND date_created <= :approved_at
			 ORDER BY date_created DESC, id DESC LIMIT 1 FOR UPDATE",
			[
				'user_id' => $drawerUserId,
				'approved_date' => $approvedDate,
				'approved_at' => $approvedAt !== '' ? $approvedAt : date('Y-m-d H:i:s'),
			]
		) ?: [];
		if ($db->has_error) {
			throw new \RuntimeException('Unable to locate the cashdrawer used by the approved transaction.');
		}
		if (empty($drawerRows)) {
			$drawerRows = $db->query(
				"SELECT id, user_id FROM cashdrawers
				 WHERE user_id = :user_id AND DATE(date_created) = :approved_date
				 ORDER BY date_created DESC, id DESC LIMIT 1 FOR UPDATE",
				['user_id' => $drawerUserId, 'approved_date' => $approvedDate]
			) ?: [];
			if ($db->has_error) {
				throw new \RuntimeException('Unable to locate the cashdrawer used by the approved transaction.');
			}
		}
		if (empty($drawerRows) && $approvedAt === '') {
			$drawerRows = $db->query(
				"SELECT id, user_id FROM cashdrawers
				 WHERE user_id = :user_id
				 ORDER BY date_created DESC, id DESC LIMIT 1 FOR UPDATE",
				['user_id' => $drawerUserId]
			) ?: [];
			if ($db->has_error) {
				throw new \RuntimeException('Unable to locate the cashdrawer used by the approved transaction.');
			}
		}
		if (empty($drawerRows)) {
			throw new \RuntimeException('Unable to locate the cashdrawer used by the approved transaction.');
		}

		$drawerId = (int)($drawerRows[0]->id ?? 0);
		$drawerBalances = $db->query(
			"SELECT id, drawer_id, user_id, channel_key, opening_balance, credit, debit, trans_id, trans_status
			 FROM drawer_balances
			 WHERE drawer_id = :drawer_id AND user_id = :user_id AND channel_type = 'ecash'
			 ORDER BY id DESC
			 FOR UPDATE",
			['drawer_id' => $drawerId, 'user_id' => $drawerUserId]
		) ?: [];
		if ($db->has_error) {
			throw new \RuntimeException('Unable to read the cashdrawer e-cash balance.');
		}
		foreach ($drawerBalances as $candidate) {
			if (isset($normalizedCandidates[$normalizeChannel((string)($candidate->channel_key ?? ''))])) {
				$balanceRow = $candidate;
				break;
			}
		}
	}

	if (!$balanceRow) {
		throw new \RuntimeException('The approved e-cash cashdrawer balance could not be found; cancellation was not applied.');
	}
	$balanceTransId = (int)($balanceRow->trans_id ?? 0);
	$balanceStatus = strtolower(trim((string)($balanceRow->trans_status ?? '')));
	if ($balanceTransId === $id && $balanceStatus === 'cashpickup reversal') {
		record_cashdrawer_reversal($db, $id, $actorId, 'ecash');
		return;
	}
	if ($repairCancelled
		&& ($balanceTransId !== $id || $balanceStatus !== 'approved')) {
		throw new \RuntimeException('This cancelled e-cash transaction cannot be safely repaired automatically because its drawer history has since changed.');
	}

	$opening = (float)($balanceRow->opening_balance ?? 0);
	$credit = (float)($balanceRow->credit ?? 0) + ($reversalDirection === 'in' ? $amount : 0.0);
	$debit = (float)($balanceRow->debit ?? 0) + ($reversalDirection === 'out' ? $amount : 0.0);
	$db->query(
		"UPDATE drawer_balances
		 SET credit = :credit, debit = :debit, closing_balance = :closing,
		     trans_id = :trans_id, trans_status = :trans_status
		 WHERE id = :id",
		[
			'credit' => $credit,
			'debit' => $debit,
			'closing' => $opening + $credit - $debit,
			'trans_id' => $id,
			'trans_status' => 'Cashpickup reversal',
			'id' => (int)($balanceRow->id ?? 0),
		]
	);
	if ($db->has_error || $db->affected_rows <= 0) {
		throw new \RuntimeException('Unable to save the e-cash cashdrawer reversal.');
	}
	record_cashdrawer_reversal($db, $id, $actorId, 'ecash');
}
