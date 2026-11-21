<?php

namespace CashpickupPermissions;

use \Core\Database;

defined('ROOT') or die("Direct script access denied");

function has_role(Database $db, int $userId, array $roles): bool
{
	if ($userId <= 0) {
		return false;
	}

	$rows = $db->query(
		"SELECT LOWER(TRIM(ur.role)) AS role_name
		 FROM user_roles_map urm
		 JOIN user_roles ur ON ur.id = urm.role_id
		 WHERE urm.disabled = 0
		 AND urm.user_id = :user_id",
		['user_id' => $userId]
	) ?: [];
	if ($db->has_error) {
		throw new \RuntimeException('Unable to verify Cashpickup role.');
	}

	foreach ($rows as $row) {
		if (in_array(strtolower(trim((string)($row->role_name ?? ''))), $roles, true)) {
			return true;
		}
	}
	return false;
}

function role_tier(string $roleName): int
{
	$roleName = strtolower(trim($roleName));
	if (in_array($roleName, ['admin', 'is_admin'], true)) {
		return 4;
	}
	if (in_array($roleName, ['general manager', 'general_manager', 'generalmanager', 'gm', 'is_general_manager'], true)) {
		return 3;
	}
	if (in_array($roleName, [
		'operations manager', 'operations_manager', 'operationsmanager',
		'ops manager', 'ops_manager', 'opsmanager', 'is_operations_manager',
	], true)) {
		return 2;
	}
	if (in_array($roleName, ['manager', 'is_manager'], true)) {
		return 1;
	}
	return 0;
}

function user_role_tier(Database $db, int $userId): int
{
	if ($userId <= 0) {
		return 0;
	}
	$rows = $db->query(
		"SELECT LOWER(TRIM(ur.role)) AS role_name
		 FROM user_roles_map urm
		 JOIN user_roles ur ON ur.id = urm.role_id
		 WHERE urm.disabled = 0 AND urm.user_id = :user_id",
		['user_id' => $userId]
	) ?: [];
	if ($db->has_error) {
		throw new \RuntimeException('Unable to verify Cashpickup role.');
	}

	$tier = 0;
	foreach ($rows as $row) {
		$tier = max($tier, role_tier((string)($row->role_name ?? '')));
	}
	if (\user_can('is_admin')) {
		return max($tier, 4);
	}
	if (\user_can('is_general_manager')) {
		return max($tier, 3);
	}
	if (\user_can('is_operations_manager')) {
		return max($tier, 2);
	}
	if (\user_can('is_manager')) {
		return max($tier, 1);
	}
	return $tier;
}

function eligible_approvers(Database $db, int $actorId, bool $adminOnly = false): array
{
	$actorTier = user_role_tier($db, $actorId);
	if (!$db->table_exists(['cashdrawers'])) {
		throw new \RuntimeException('Unable to verify approver cashdrawer availability.');
	}
	$cashdrawerToday = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Accra')))->format('Y-m-d');
	$rows = $db->query(
		"SELECT DISTINCT u.id, u.first_name, u.last_name, LOWER(TRIM(ur.role)) AS role_name
		 FROM users u
		 JOIN user_roles_map urm ON urm.user_id = u.id AND urm.disabled = 0
		 JOIN user_roles ur ON ur.id = urm.role_id
		 WHERE u.deleted = 0
		 AND EXISTS (
			SELECT 1
			FROM cashdrawers cd
			WHERE cd.user_id = u.id
			AND DATE(cd.date_created) = :cashdrawer_today
		 )
		 AND LOWER(TRIM(ur.role)) IN (
			'admin','is_admin',
			'general manager','general_manager','generalmanager','gm','is_general_manager',
			'operations manager','operations_manager','operationsmanager',
			'ops manager','ops_manager','opsmanager','is_operations_manager'
		 )
		 ORDER BY u.first_name ASC, u.last_name ASC",
		['cashdrawer_today' => $cashdrawerToday]
	) ?: [];
	if ($db->has_error) {
		throw new \RuntimeException('Unable to load Cashpickup approvers.');
	}

	$eligible = [];
	foreach ($rows as $row) {
		$userId = (int)($row->id ?? 0);
		$tier = role_tier((string)($row->role_name ?? ''));
		if ($userId <= 0 || $userId === $actorId) {
			continue;
		}
		if ($adminOnly ? $tier !== 4 : ($tier < $actorTier || ($tier === $actorTier && $actorTier !== 4))) {
			continue;
		}
		$eligible[$userId] = (object)[
			'id' => $userId,
			'first_name' => (string)($row->first_name ?? ''),
			'last_name' => (string)($row->last_name ?? ''),
			'role_tier' => $tier,
			'role_label' => [2 => 'Operations manager', 3 => 'General manager', 4 => 'Admin'][$tier] ?? 'Approver',
		];
	}
	$eligible = array_values($eligible);
	usort($eligible, static function($left, $right): int {
		return ($left->role_tier <=> $right->role_tier)
			?: strcmp($left->first_name . ' ' . $left->last_name, $right->first_name . ' ' . $right->last_name);
	});
	return $eligible;
}

function is_eligible_approver(Database $db, int $actorId, int $approverId, bool $adminOnly = false): bool
{
	if ($approverId <= 0 || $approverId === $actorId) {
		return false;
	}
	foreach (eligible_approvers($db, $actorId, $adminOnly) as $approver) {
		if ((int)$approver->id === $approverId) {
			return true;
		}
	}
	return false;
}

function is_operations_manager(Database $db, int $userId): bool
{
	return \user_can('is_operations_manager') || has_role($db, $userId, [
		'operations manager',
		'operations_manager',
		'operationsmanager',
		'ops manager',
		'ops_manager',
		'opsmanager',
		'is_operations_manager',
	]);
}

function is_general_manager(Database $db, int $userId): bool
{
	return \user_can('is_general_manager') || has_role($db, $userId, [
		'general manager',
		'general_manager',
		'generalmanager',
		'gm',
		'is_general_manager',
	]);
}

function can_assign_customer_payout(Database $db, int $userId): bool
{
	return \user_can('is_admin')
		|| is_operations_manager($db, $userId)
		|| is_general_manager($db, $userId);
}

function can_withdraw_customer_payout(Database $db, int $userId): bool
{
	if (\user_can('is_admin')) {
		return false;
	}
	if (is_operations_manager($db, $userId)) {
		return true;
	}
	return \user_can('add_cashpickup')
		&& !\user_can('is_manager')
		&& !is_general_manager($db, $userId);
}

function is_top_authority(Database $db, int $userId): bool
{
	if (\user_can('is_admin')
		|| \user_can('is_manager')
		|| \user_can('is_operations_manager')
		|| \user_can('is_general_manager')) {
		return true;
	}
	if ($userId <= 0) {
		return false;
	}

	$rows = $db->query(
		"SELECT LOWER(TRIM(ur.role)) AS role_name
		 FROM user_roles_map urm
		 JOIN user_roles ur ON ur.id = urm.role_id
		 WHERE urm.disabled = 0
		 AND urm.user_id = :user_id",
		['user_id' => $userId]
	) ?: [];
	if ($db->has_error) {
		throw new \RuntimeException('Unable to verify Cashpickup authority.');
	}

	$roleNames = [];
	foreach ($rows as $row) {
		$roleName = strtolower(trim((string)($row->role_name ?? '')));
		if ($roleName !== '') {
			$roleNames[] = $roleName;
		}
	}

	return !empty(array_intersect($roleNames, [
		'admin',
		'is_admin',
		'manager',
		'is_manager',
		'operations manager',
		'operations_manager',
		'operationsmanager',
		'ops manager',
		'ops_manager',
		'opsmanager',
		'is_operations_manager',
		'general manager',
		'general_manager',
		'generalmanager',
		'gm',
		'is_general_manager',
	]));
}
