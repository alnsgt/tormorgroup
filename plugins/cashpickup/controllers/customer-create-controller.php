<?php

require_once plugin_path('helpers/customer-ledger.php');
require_once plugin_path('helpers/permissions.php');

if (!(user_can('add_cashpickup') || user_can('edit_cashpickup'))) {
	message_fail('Access denied');
	redirect($admin_route . '/' . $plugin_route . '/customers');
}

$postdata = $req->post();
$csrf = csrf_verify($postdata);
if (!$csrf) {
	message_fail('Form expired');
	redirect($admin_route . '/' . $plugin_route . '/customer_new');
}

$session = get_session();
$userId = (int)($session->id ?? 0);
$isApprover = user_can('is_admin');
$approvalStatus = $isApprover ? 'approved' : 'pending';
$approvalAssigneeId = (int)($postdata['approval_assigned_to'] ?? 0);

$fullName = trim((string)($postdata['full_name'] ?? ''));
$phone = trim((string)($postdata['phone_number'] ?? ''));
$idType = trim((string)($postdata['id_type'] ?? ''));
$idNumber = trim((string)($postdata['id_number'] ?? ''));
$location = trim((string)($postdata['location'] ?? ''));
$existingId = (int)($postdata['existing_customer_id'] ?? 0);

if ($phone === '' && $idNumber === '') {
	message_fail('Phone number or ID number is required.');
	redirect($admin_route . '/' . $plugin_route . '/customer_new');
}

try {
	$db = new \Core\Database;
	\CashpickupLedger\ensure_cashpickup_customer_schema($db);
	\CashpickupLedger\ensure_cashpickup_columns($db);
	\CashpickupLedger\ensure_cashpickup_customer_approval_columns($db);
	if (!$isApprover && !\CashpickupPermissions\is_eligible_approver($db, $userId, $approvalAssigneeId)) {
		message_fail('Select a superior role to approve this customer registration.');
		redirect($admin_route . '/' . $plugin_route . '/customer_new');
	}

	$payload = [
		'full_name' => $fullName,
		'phone_number' => $phone,
		'id_type' => $idType,
		'id_number' => $idNumber,
		'location' => $location,
	];

	if ($existingId > 0) {
		$db->query(
			"UPDATE cashpickup_customers
			 SET full_name = :full_name,
			     phone_number = :phone_number,
			     id_type = :id_type,
			     id_number = :id_number,
			     location = :location,
			     approval_status = :approval_status,
			     approved_by = :approved_by,
			     approved_at = :approved_at,
			     approval_assigned_to = :approval_assigned_to,
			     denial_reason = NULL,
			     updated_by = :updated_by,
			     date_updated = :date_updated
			 WHERE id = :id",
			[
				'full_name' => $fullName !== '' ? $fullName : null,
				'phone_number' => $phone !== '' ? $phone : null,
				'id_type' => $idType !== '' ? $idType : null,
				'id_number' => $idNumber !== '' ? $idNumber : null,
				'location' => $location !== '' ? $location : null,
				'approval_status' => $approvalStatus,
				'approved_by' => $approvalStatus === 'approved' ? ($userId > 0 ? $userId : null) : null,
				'approved_at' => $approvalStatus === 'approved' ? date('Y-m-d H:i:s') : null,
				'approval_assigned_to' => $approvalStatus === 'pending' ? $approvalAssigneeId : null,
				'updated_by' => (string)$userId,
				'date_updated' => date('Y-m-d H:i:s'),
				'id' => $existingId,
			]
		);
		\CashpickupLedger\link_customer_transactions($db, (int)$existingId, $payload);
		\CashpickupActivity\record(
			'updated',
			$userId,
			'Customer #' . $existingId . ' registration details updated (' . $approvalStatus . ').',
			'cashpickup_customers'
		);
		message_success($approvalStatus === 'approved'
			? 'Customer already existed. Details updated.'
			: 'Customer registration updated and submitted for approval.');
		redirect($admin_route . '/' . $plugin_route . '/customer/' . $existingId);
	}

	$customer = \CashpickupLedger\upsert_customer($payload, $userId, $db, [
		'approval_status' => $approvalStatus,
		'approved_by' => $approvalStatus === 'approved' ? $userId : null,
		'approval_assigned_to' => $approvalStatus === 'pending' ? $approvalAssigneeId : null,
	]);
	if (empty($customer) || empty($customer['id'])) {
		message_fail('Unable to register customer.');
		redirect($admin_route . '/' . $plugin_route . '/customer_new');
	}

	\CashpickupLedger\link_customer_transactions($db, (int)$customer['id'], $payload);
	\CashpickupActivity\record(
		'registered',
		$userId,
		'Customer #' . (int)$customer['id'] . ' registered (' . $approvalStatus . ').',
		'cashpickup_customers'
	);

	message_success($approvalStatus === 'approved'
		? 'Customer registered successfully.'
		: 'Customer registration submitted for approval.');
	redirect($admin_route . '/' . $plugin_route . '/customer/' . (int)$customer['id']);
} catch (\Throwable $e) {
	message_fail('Unable to register customer.');
	redirect($admin_route . '/' . $plugin_route . '/customer_new');
}
