<?php

if (!user_can('is_admin')) {
	message_fail('Only an administrator can review cashdrawer edit requests.');
	redirect($admin_route . '/' . $plugin_route);
}

$reviewPost = $req->post();
if (!csrf_verify($reviewPost)) {
	message_fail('Form expired. Please refresh and try again.');
	redirect($admin_route . '/' . $plugin_route . '/cashdrawer_edit_approvals');
}

$requestId = (int)($reviewPost['request_id'] ?? 0);
$decision = strtolower(trim((string)($reviewPost['decision'] ?? '')));
if ($requestId <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
	message_fail('Invalid cashdrawer edit review request.');
	redirect($admin_route . '/' . $plugin_route . '/cashdrawer_edit_approvals');
}

try {
	$db = new \Core\Database;
	operations_account_ensure_cashdrawer_edit_approval_schema($db);
	$requests = $db->query(
		"SELECT * FROM cashdrawer_edit_approval_requests WHERE id = :id AND status = 'pending' LIMIT 1",
		['id' => $requestId]
	) ?: [];
	$approvalRequest = $requests[0] ?? null;
	if (!$approvalRequest) {
		message_fail('This cashdrawer edit request is no longer pending.');
		redirect($admin_route . '/' . $plugin_route . '/cashdrawer_edit_approvals');
	}
	$reviewerId = (int)(get_session()->id ?? 0);
	$assignedAdminId = (int)($approvalRequest->assigned_admin_id ?? 0);
	if ($assignedAdminId > 0 && $assignedAdminId !== $reviewerId) {
		message_fail('This cashdrawer edit is assigned to another administrator.');
		redirect($admin_route . '/' . $plugin_route . '/cashdrawer_edit_approvals');
	}

	if ($decision === 'reject') {
		$db->query(
			"UPDATE cashdrawer_edit_approval_requests
			 SET status = 'rejected', reviewed_by = :reviewed_by, reviewed_at = :reviewed_at,
			     review_note = :review_note
			 WHERE id = :id AND status = 'pending'",
			[
				'reviewed_by' => $reviewerId,
				'reviewed_at' => date('Y-m-d H:i:s'),
				'review_note' => trim((string)($reviewPost['review_note'] ?? '')) ?: 'Rejected by administrator.',
				'id' => $requestId,
			]
		);
		if ($db->has_error) {
			throw new \RuntimeException('Unable to reject the cashdrawer edit request: ' . (string)$db->error);
		}
		if ((int)$db->affected_rows !== 1) {
			message_fail('This cashdrawer edit request was already reviewed.');
			redirect($admin_route . '/' . $plugin_route . '/cashdrawer_edit_approvals');
		}
		message_success('Cashdrawer edit request rejected.');
		redirect($admin_route . '/' . $plugin_route . '/cashdrawer_edit_approvals');
	}

	$requestData = json_decode((string)($approvalRequest->request_data ?? ''), true);
	$proofData = json_decode((string)($approvalRequest->proof_data ?? ''), true);
	$drawerId = (int)($approvalRequest->drawer_id ?? 0);
	if (
		!is_array($requestData)
		|| !is_array($requestData['post'] ?? null)
		|| !is_array($proofData)
		|| $drawerId <= 0
	) {
		throw new \RuntimeException('The stored cashdrawer edit request is incomplete.');
	}

	$drawerRows = $db->query(
		"SELECT id, date_created, date_updated FROM cashdrawers WHERE id = :id LIMIT 1",
		['id' => $drawerId]
	) ?: [];
	$drawerRow = $drawerRows[0] ?? null;
	if (!$drawerRow) {
		message_fail('The cashdrawer no longer exists. The request was not applied.');
		redirect($admin_route . '/' . $plugin_route . '/cashdrawer_edit_approvals');
	}
	if (!operations_account_is_current_cashdrawer($drawerRow)) {
		$db->query(
			"UPDATE cashdrawer_edit_approval_requests
			 SET status = 'expired', reviewed_by = :reviewed_by, reviewed_at = :reviewed_at,
			     review_note = 'Expired because only current-day cashdrawers can be edited.'
			 WHERE id = :id AND status = 'pending'",
			[
				'reviewed_by' => $reviewerId,
				'reviewed_at' => date('Y-m-d H:i:s'),
				'id' => $requestId,
			]
		);
		if ($db->has_error) {
			throw new \RuntimeException('Unable to expire the outdated cashdrawer edit request: ' . (string)$db->error);
		}
		message_fail("This request expired because only the current day's cashdrawer can be edited.");
		redirect($admin_route . '/' . $plugin_route . '/cashdrawer_edit_approvals');
	}

	$currentUpdated = trim((string)($drawerRow->date_updated ?? ''));
	$baseUpdated = trim((string)($approvalRequest->base_date_updated ?? ''));
	if ($currentUpdated === '0000-00-00 00:00:00') {
		$currentUpdated = '';
	}
	if ($baseUpdated === '0000-00-00 00:00:00') {
		$baseUpdated = '';
	}
	if ($currentUpdated !== $baseUpdated) {
		message_fail('The cashdrawer changed after this request was submitted. Ask the operations manager to submit the edit again.');
		redirect($admin_route . '/' . $plugin_route . '/cashdrawer_edit_approvals');
	}

	$db->query(
		"UPDATE cashdrawer_edit_approval_requests
		 SET status = 'processing', reviewed_by = :reviewed_by, reviewed_at = :reviewed_at
		 WHERE id = :id AND status = 'pending'",
		[
			'reviewed_by' => $reviewerId,
			'reviewed_at' => date('Y-m-d H:i:s'),
			'id' => $requestId,
		]
	);
	if ($db->has_error || (int)$db->affected_rows !== 1) {
		message_fail('This cashdrawer edit request is already being reviewed.');
		redirect($admin_route . '/' . $plugin_route . '/cashdrawer_edit_approvals');
	}

	$GLOBALS['operations_account_approved_cashdrawer_edit'] = [
		'request_id' => $requestId,
		'drawer_id' => $drawerId,
		'post' => $requestData['post'],
		'proofs' => $proofData,
	];
	$cashdrawer = new \MyWallets\Cashdrawer;
	$row = $cashdrawer->first(['id' => $drawerId]);
	if (!$row) {
		throw new \RuntimeException('The cashdrawer could not be loaded for approval.');
	}
	require plugin_path('controllers/edit_cashdrawer-controller.php');
	$db->query(
		"UPDATE cashdrawer_edit_approval_requests
		 SET status = 'pending', reviewed_by = NULL, reviewed_at = NULL
		 WHERE id = :id AND status = 'processing'",
		['id' => $requestId]
	);
	if ($db->has_error) {
		throw new \RuntimeException('Unable to restore the request after the edit could not be applied.');
	}
	message_fail('The cashdrawer edit could not be applied. Review the validation message and try again.');
	redirect($admin_route . '/' . $plugin_route . '/cashdrawer_edit_approvals');
} catch (\Throwable $e) {
	error_log('[CashdrawerEditApproval] ' . $e->getMessage());
	message_fail('Unable to apply this cashdrawer edit request. No approval was recorded.');
	redirect($admin_route . '/' . $plugin_route . '/cashdrawer_edit_approvals');
}
