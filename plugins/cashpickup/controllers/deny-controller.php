<?php

$cashpickup = new \Cashpickups\Cashpickup;
require_once plugin_path('helpers/permissions.php');

$session = get_session();
$actorId = (int)($session->id ?? 0);

// Admin, General Manager, or Operations Manager can reject.
$canDeny = user_can('is_admin');
if (!$canDeny) {
	try {
		$dbRole = new \Core\Database;
		$canDeny = \CashpickupPermissions\is_general_manager($dbRole, $actorId)
			|| \CashpickupPermissions\is_operations_manager($dbRole, $actorId);
	} catch (\Throwable $e) {
		error_log('[cashpickup] Rejection access check failed: ' . $e->getMessage());
		$canDeny = false;
	}
}

if (!$canDeny) {
	message_fail('Access denied');
	redirect($admin_route.'/'.$plugin_route);
}

$postdata = $req->post();
$returnCustomerId = (int)($postdata['customer_id'] ?? 0);
$returnToView = (string)($postdata['return_to_view'] ?? '') === '1';
$returnViewId = $returnToView ? (int)(URL(3) ?? 0) : 0;
$denyReturnPath = $returnViewId > 0
	? $admin_route.'/'.$plugin_route.'/view/'.$returnViewId
	: ($returnCustomerId > 0
		? $admin_route.'/'.$plugin_route.'/customer/'.$returnCustomerId
		: $admin_route.'/'.$plugin_route);
$csrf = csrf_verify($postdata);
if (!$csrf) {
	message_fail('Form expired');
	redirect($denyReturnPath);
}

$reason = trim((string)($postdata['denial_reason'] ?? ''));
if ($reason === '') {
	message_fail('Reason is required');
	redirect($denyReturnPath);
}

$id = (int)(URL(3) ?? 0);
if ($id <= 0) {
	message_fail('Invalid record');
	redirect($denyReturnPath);
}

$row = $cashpickup->first(['id' => $id]);
if (empty($row)) {
	message_fail('Record not found');
	redirect($denyReturnPath);
}

$approvalAssigneeId = (int)($row->approval_assigned_to ?? 0);
$prevApproval = strtolower(trim((string)($row->approval_status ?? '')));
if ($prevApproval === '') {
	$prevApproval = strtolower(trim((string)($row->transaction_status ?? ''))) === 'approved' ? 'approved' : 'pending';
}
$isPendingApprovalTask = in_array($prevApproval, ['pending', 'draft'], true)
	|| str_starts_with(strtolower(trim((string)($row->transaction_status ?? ''))), 'pending');
if (preg_match('/reject|declin|deni|cancel/i', $prevApproval . ' ' . (string)($row->transaction_status ?? ''))) {
	message_fail('Rejected or cancelled cashpickup transactions cannot be denied again.');
	redirect($denyReturnPath);
}
$isPreviouslyApproved = $prevApproval === 'approved'
	|| strtolower(trim((string)($row->transaction_status ?? ''))) === 'approved';
if (!$isPreviouslyApproved
	&& (($approvalAssigneeId > 0 && $approvalAssigneeId !== $actorId)
		|| ($approvalAssigneeId <= 0 && !$isPendingApprovalTask))) {
	message_fail('Only the assigned approver can deny this transaction.');
	redirect($denyReturnPath);
}

$db = new \Core\Database;
$reversalHelperPath = realpath(__DIR__ . '/../helpers/reversal.php');
if (!$reversalHelperPath || !file_exists($reversalHelperPath)) {
	message_fail('Unable to load the cashpickup reversal service.');
	redirect($denyReturnPath);
}
require_once $reversalHelperPath;
try {
	\CashpickupReversals\ensure_cashdrawer_reversal_table($db);
} catch (\Throwable $e) {
	message_fail($e->getMessage());
	redirect($denyReturnPath);
}
if (!$db->beginTransaction()) {
	message_fail('Unable to prepare cashpickup rejection.');
	redirect($denyReturnPath);
}

try {
	$lockedRows = $db->query(
		"SELECT * FROM cashpickups
		 WHERE id = :id AND (deleted IS NULL OR deleted = 0)
		 LIMIT 1 FOR UPDATE",
		['id' => $id]
	) ?: [];
	$lockedRow = $lockedRows[0] ?? null;
	if (!$lockedRow) {
		throw new \RuntimeException('Cashpickup transaction not found.');
	}

	$approvalAssigneeId = (int)($lockedRow->approval_assigned_to ?? 0);
	$prevApproval = strtolower(trim((string)($lockedRow->approval_status ?? '')));
	if ($prevApproval === '') {
		$prevApproval = strtolower(trim((string)($lockedRow->transaction_status ?? ''))) === 'approved' ? 'approved' : 'pending';
	}
	$isPendingApprovalTask = in_array($prevApproval, ['pending', 'draft'], true)
		|| str_starts_with(strtolower(trim((string)($lockedRow->transaction_status ?? ''))), 'pending');
	if (preg_match('/reject|declin|deni|cancel/i', $prevApproval . ' ' . (string)($lockedRow->transaction_status ?? ''))) {
		throw new \RuntimeException('Rejected or cancelled cashpickup transactions cannot be denied again.');
	}
	$isPreviouslyApproved = $prevApproval === 'approved'
		|| strtolower(trim((string)($lockedRow->transaction_status ?? ''))) === 'approved';
	if (!$isPreviouslyApproved
		&& (($approvalAssigneeId > 0 && $approvalAssigneeId !== $actorId)
			|| ($approvalAssigneeId <= 0 && !$isPendingApprovalTask))) {
		throw new \RuntimeException('Only the assigned approver can deny this transaction.');
	}

	$row = $lockedRow;
	$side = strtolower(trim((string)($row->transaction_side ?? '')));
	$pairId = strtolower(trim((string)($row->payment_method ?? ''))) === 'customer_transfer'
		? (int)($row->ledger_transfer_pair_id ?? 0)
		: 0;
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
			throw new \RuntimeException('Reject or cancel linked payouts before rejecting this received transaction.');
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

	$now = date('Y-m-d H:i:s');
	if ($pairId > 0) {
		$db->query(
			"UPDATE cashpickups
			 SET approval_status = 'denied',
			     approval_assigned_to = NULL,
			     approved_by = :approved_by,
			     approved_at = :approved_at,
			     denial_reason = :denial_reason,
			     transaction_status = 'Denied',
			     updated_by = :updated_by,
			     date_updated = :date_updated
			 WHERE ledger_transfer_pair_id = :pair_id
			   AND (deleted IS NULL OR deleted = 0)",
			[
				'approved_by' => $actorId,
				'approved_at' => $now,
				'denial_reason' => $reason,
				'updated_by' => $actorId,
				'date_updated' => $now,
				'pair_id' => $pairId,
			]
		);
		if ($db->has_error || $db->affected_rows !== 2) {
			throw new \RuntimeException('Unable to deny both sides of the customer transfer.');
		}
	} else {
		$db->query(
			"UPDATE cashpickups
			 SET approval_status = 'denied',
			     approval_assigned_to = NULL,
			     approved_by = :approved_by,
			     approved_at = :approved_at,
			     denial_reason = :denial_reason,
			     transaction_status = 'Denied',
			     updated_by = :updated_by,
			     date_updated = :date_updated
			 WHERE id = :id",
			[
				'approved_by' => $actorId,
				'approved_at' => $now,
				'denial_reason' => $reason,
				'updated_by' => $actorId,
				'date_updated' => $now,
				'id' => $id,
			]
		);
		if ($db->has_error || $db->affected_rows <= 0) {
			throw new \RuntimeException('Unable to reject this cashpickup. Please try again.');
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
					'denial_reason' => $reason,
					'updated_by' => $actorId,
					'date_updated' => $now,
					'id' => $linkReceiveId,
				]
			);
			if ($db->has_error) {
				throw new \RuntimeException('Unable to update the linked credit payout.');
			}
		}
	}

	if ($db->table_exists(['commissions'])) {
		$needle = '%| ID ' . $id;
		$db->query(
			"UPDATE commissions SET deleted = 1, date_deleted = :now
			 WHERE commission_type = :ctype AND description LIKE :needle",
			['now' => $now, 'ctype' => 'CASHPICKUP CHARGE', 'needle' => $needle]
		);
		if ($db->has_error) {
			throw new \RuntimeException('Unable to reverse the cashpickup charge commission.');
		}
	}

	if (!\CashpickupActivity\record(
		'denied',
		$actorId,
		'Cashpickup transaction' . ($pairId > 0 ? ' pair #' . $pairId : ' #' . $id) . ' denied. Reason: ' . $reason
	)) {
		throw new \RuntimeException('Unable to record the rejection activity.');
	}
	$deniedRows = $db->query("SELECT * FROM cashpickups WHERE id = :id LIMIT 1", ['id' => $id]) ?: [];
	$deniedRow = $deniedRows[0] ?? null;
	if (!$deniedRow
		|| strtolower(trim((string)($deniedRow->approval_status ?? ''))) !== 'denied'
		|| trim((string)($deniedRow->denial_reason ?? '')) !== $reason) {
		throw new \RuntimeException('Cashpickup rejection was not saved. Please refresh and try again.');
	}
	if (!$db->commit()) {
		throw new \RuntimeException('Unable to commit cashpickup rejection.');
	}
} catch (\Throwable $e) {
	$db->rollBack();
	error_log('[cashpickup] Rejection failed for transaction ' . $id . ': ' . $e->getMessage());
	message_fail($e->getMessage());
	redirect($denyReturnPath);
	exit;
}

$denialNotificationRecord = array_merge((array)$deniedRow, [
	'approval_status' => 'denied',
	'denial_reason' => $reason,
]);
$denialRecipients = \cashpickup_whatsapp_participant_ids($denialNotificationRecord, [$actorId]);
if ($side === 'receive') {
	$denialRecipients = array_values(array_unique(array_merge($denialRecipients, \cashpickup_whatsapp_approver_ids())));
}
\cashpickup_whatsapp_notify(
	$denialNotificationRecord,
	$denialRecipients,
	'cashpickup creator, assigned agent, and next reviewer',
	'REJECTED',
	'Review the rejection reason and correct or reassign the cashpickup as appropriate.',
	$admin_route.'/'.$plugin_route.'/view/'.$id
);

message_success('Cashpickup denied');
redirect($denyReturnPath);
