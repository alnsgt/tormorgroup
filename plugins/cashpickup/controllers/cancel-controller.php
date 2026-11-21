<?php

if (!user_can('is_admin')) {
	message_fail('Only an admin can cancel cashpickup transactions.');
	redirect($admin_route . '/' . $plugin_route);
	exit;
}

$postdata = $req->post();
$repairCashdrawerOnly = (string)($postdata['repair_cashdrawer'] ?? '') === '1';
if (!csrf_verify($postdata)) {
	message_fail('Form expired.');
	redirect($admin_route . '/' . $plugin_route);
	exit;
}

$cancellationReason = trim((string)($postdata['cancellation_reason'] ?? ''));
if (!$repairCashdrawerOnly && ($cancellationReason === '' || strlen($cancellationReason) > 500)) {
	message_fail('Enter a cancellation reason (up to 500 characters).');
	redirect($admin_route . '/' . $plugin_route);
	exit;
}

$id = (int)(URL(3) ?? 0);
$db = new \Core\Database;
$reversalHelperPath = realpath(__DIR__ . '/../helpers/reversal.php');
if ($id <= 0 || !$reversalHelperPath || !file_exists($reversalHelperPath)) {
	message_fail('Unable to prepare transaction cancellation.');
	redirect($admin_route . '/' . $plugin_route);
	exit;
}
require_once $reversalHelperPath;
try {
	\CashpickupReversals\ensure_cashdrawer_reversal_table($db);
} catch (\Throwable $e) {
	message_fail($e->getMessage());
	redirect($admin_route . '/' . $plugin_route . '/view/' . $id);
	exit;
}
$customerLedgerPath = realpath(__DIR__ . '/../helpers/customer-ledger.php');
if ($customerLedgerPath && file_exists($customerLedgerPath)) {
	require_once $customerLedgerPath;
	if (function_exists('CashpickupLedger\\ensure_cashpickup_transfer_columns')) {
		\CashpickupLedger\ensure_cashpickup_transfer_columns($db);
	}
}
if (!$db->beginTransaction()) {
	message_fail('Unable to prepare transaction cancellation.');
	redirect($admin_route . '/' . $plugin_route);
	exit;
}

try {
	$rows = $db->query(
		"SELECT * FROM cashpickups WHERE id = :id AND (deleted IS NULL OR deleted = 0) LIMIT 1 FOR UPDATE",
		['id' => $id]
	) ?: [];
	$row = $rows[0] ?? null;
	if (!$row) {
		throw new \RuntimeException('Cashpickup transaction not found.');
	}

	$approvalStatus = strtolower(trim((string)($row->approval_status ?? 'pending')));
	$transactionStatus = strtolower(trim((string)($row->transaction_status ?? '')));
	$currentStatuses = $approvalStatus . ' ' . $transactionStatus;
	if (preg_match('/reject|declin|deni/i', $currentStatuses)) {
		throw new \RuntimeException('Rejected, declined, or denied cashpickup transactions cannot be cancelled.');
	}
	if (preg_match('/cancelled|canceled/i', $currentStatuses)) {
		$actorId = (int)(get_session()->id ?? 0);
		\CashpickupReversals\reverse_approved_cashdrawer_effects($db, $row, $actorId, true);
		if (!$db->commit()) {
			throw new \RuntimeException('Unable to complete cashpickup cancellation.');
		}
		message_success($repairCashdrawerOnly
			? 'Cashdrawer reversal was verified or repaired.'
			: 'Cashpickup transaction is already cancelled; its cashdrawer status was checked.');
		redirect($admin_route . '/' . $plugin_route . '/view/' . $id);
		exit;
	}
	if ($repairCashdrawerOnly) {
		throw new \RuntimeException('Cashdrawer reversal repair is only available for already-cancelled transactions.');
	}

	$side = strtolower(trim((string)($row->transaction_side ?? '')));
	$source = strtolower(trim((string)($row->source ?? '')));
	$paymentMethod = strtolower(trim((string)($row->payment_method ?? '')));
	$isCustomerTransfer = $source === 'customer_transfer' || $paymentMethod === 'customer_transfer';
	$actorId = (int)(get_session()->id ?? 0);
	$cancelledTransactionIds = [$id];
	if ($isCustomerTransfer && (int)($row->ledger_transfer_pair_id ?? 0) > 0) {
		$pairRows = $db->query(
			"SELECT id, approval_status, transaction_status
			 FROM cashpickups
			 WHERE ledger_transfer_pair_id = :pair_id
			   AND (deleted IS NULL OR deleted = 0)
			 FOR UPDATE",
			['pair_id' => (int)$row->ledger_transfer_pair_id]
		) ?: [];
		if ($db->has_error || count($pairRows) < 2) {
			 throw new \RuntimeException('Unable to find both sides of the customer transfer.');
		}
		foreach ($pairRows as $pairRow) {
			$pairStatus = strtolower(trim((string)($pairRow->approval_status ?? 'pending'))) . ' ' . strtolower(trim((string)($pairRow->transaction_status ?? '')));
			if (preg_match('/reject|declin|deni/i', $pairStatus)) {
				throw new \RuntimeException('A customer transfer cannot be cancelled because its paired transaction was rejected or denied.');
			}
			$pairId = (int)($pairRow->id ?? 0);
			if ($pairId > 0 && !preg_match('/cancelled|canceled/i', $pairStatus) && !in_array($pairId, $cancelledTransactionIds, true)) {
				$cancelledTransactionIds[] = $pairId;
			}
		}
	}

	if ($side === 'receive') {
		$linkedPayouts = $db->query(
			"SELECT id FROM cashpickups
			 WHERE receive_id = :receive_id
			   AND LOWER(transaction_side) = 'pay'
			   AND (deleted IS NULL OR deleted = 0)
			   AND LOWER(COALESCE(approval_status,'')) NOT IN ('cancelled','canceled','denied','rejected')
			   AND LOWER(COALESCE(transaction_status,'')) NOT IN ('cancelled','canceled','denied','rejected')
			 LIMIT 1 FOR UPDATE",
			['receive_id' => $id]
		) ?: [];
		if ($db->has_error) {
			throw new \RuntimeException('Unable to check for linked cashpickup payouts.');
		}
		if (!empty($linkedPayouts)) {
			throw new \RuntimeException('Cancel or reject linked payouts before cancelling this received transaction.');
		}
	}

	\CashpickupReversals\reverse_approved_cashdrawer_effects($db, $row, $actorId);

	if ($side === 'receive' && $db->table_exists(['cashpickup_credit_repayments'])) {
		$db->query(
			"DELETE FROM cashpickup_credit_repayments WHERE receive_id = :receive_id",
			['receive_id' => $id]
		);
		if ($db->has_error) {
			throw new \RuntimeException('Unable to reverse the linked customer credit repayments.');
		}
	}

	foreach ($cancelledTransactionIds as $cancelledId) {
		$db->query(
			"UPDATE cashpickups SET approval_status = 'cancelled', transaction_status = 'Cancelled', denial_reason = :denial_reason, updated_by = :updated_by, date_updated = :date_updated WHERE id = :id",
			[
				'denial_reason' => $cancellationReason,
				'updated_by' => $actorId,
				'date_updated' => date('Y-m-d H:i:s'),
				'id' => $cancelledId,
			]
		);
		if ($db->has_error) {
			throw new \RuntimeException('Unable to cancel cashpickup transaction #' . $cancelledId . '.');
		}
	}

	if ($side === 'pay' && strtolower(trim((string)($row->credit_mode ?? ''))) === 'credit') {
		$linkReceiveId = (int)($row->receive_id ?? 0);
		if ($linkReceiveId > 0) {
			$db->query(
				"UPDATE cashpickups
				 SET approval_status = 'pending',
				     approved_by = NULL,
				     approved_at = NULL,
				     transaction_status = 'Pending payout',
				     denial_reason = :denial_reason,
				     updated_by = :updated_by,
				     date_updated = :date_updated
				 WHERE id = :id",
				[
					'denial_reason' => $cancellationReason,
					'updated_by' => $actorId,
					'date_updated' => date('Y-m-d H:i:s'),
					'id' => $linkReceiveId,
				]
			);
			if ($db->has_error) {
				throw new \RuntimeException('Unable to update the linked credit payout.');
			}
		}
	}

	if ($db->table_exists(['commissions'])) {
		foreach ($cancelledTransactionIds as $cancelledId) {
			$needle = '%| ID ' . $cancelledId;
			$db->query(
				"UPDATE commissions SET deleted = 1, date_deleted = :now WHERE commission_type = :ctype AND description LIKE :needle",
				['now' => date('Y-m-d H:i:s'), 'ctype' => 'CASHPICKUP CHARGE', 'needle' => $needle]
			);
			if ($db->has_error) {
				throw new \RuntimeException('Unable to remove the cashpickup charge commission for transaction #' . $cancelledId . '.');
			}
		}
	}

	if (!\CashpickupActivity\record(
		'cancelled',
		$actorId,
		'Cashpickup transaction(s) #' . implode(', #', $cancelledTransactionIds) . ' cancelled. Reason: ' . $cancellationReason
	)) {
		throw new \RuntimeException('Unable to record the cancellation activity.');
	}
	if (!$db->commit()) {
		throw new \RuntimeException('Unable to commit cashpickup cancellation.');
	}
	$cancelNotificationRecord = array_merge((array)$row, [
		'approval_status' => 'cancelled',
		'transaction_status' => 'Cancelled',
		'denial_reason' => $cancellationReason,
	]);
	\cashpickup_whatsapp_notify(
		$cancelNotificationRecord,
		\cashpickup_whatsapp_participant_ids($cancelNotificationRecord, [(int)(get_session()->id ?? 0)]),
		'cashpickup creator and assigned agent',
		'CANCELLED',
		'Review the cancellation and coordinate any required reassignment or reconciliation.',
		$admin_route.'/'.$plugin_route.'/view/'.$id
	);
	message_success($isCustomerTransfer && count($cancelledTransactionIds) > 1
		? 'Customer transfer and its paired cashpickup transaction cancelled.'
		: 'Cashpickup transaction cancelled.');
	redirect($admin_route . '/' . $plugin_route . '/view/' . $id);
	exit;
} catch (\Throwable $e) {
	$db->rollBack();
	message_fail($e->getMessage());
	redirect($admin_route . '/' . $plugin_route . '/view/' . $id);
	exit;
}