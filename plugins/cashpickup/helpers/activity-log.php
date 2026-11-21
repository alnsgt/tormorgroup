<?php

namespace CashpickupActivity;

defined('ROOT') or die("Direct script access denied");

function record(string $action, int $userId, string $description, string $tableName = 'cashpickups'): bool
{
	$session = get_session();
	$userName = trim((string)($session->first_name ?? '') . ' ' . (string)($session->last_name ?? ''));
	if ($userName === '') {
		$userName = $userId > 0 ? 'User #' . $userId : 'System';
	}

	$db = new \Core\Database;
	$db->query(
		"INSERT INTO logs (table_name, action, user_id, user_name, description, date_created)
		 VALUES (:table_name, :action, :user_id, :user_name, :description, :date_created)",
		[
			'table_name' => $tableName,
			'action' => $action,
			'user_id' => $userId > 0 ? $userId : null,
			'user_name' => $userName,
			'description' => $description,
			'date_created' => date('Y-m-d H:i:s'),
		]
	);

	if ($db->has_error || $db->affected_rows !== 1) {
		error_log('[cashpickup] Activity log insert failed: ' . (string)($db->error ?? 'unknown database error'));
		return false;
	}
	return true;
}
