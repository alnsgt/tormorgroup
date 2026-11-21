<?php

if (!function_exists('operations_account_ensure_cashdrawer_edit_approval_schema')) {
	function operations_account_ensure_cashdrawer_edit_approval_schema(\Core\Database $db): void
	{
		$db->query(
			"CREATE TABLE IF NOT EXISTS cashdrawer_edit_approval_requests (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				drawer_id BIGINT UNSIGNED NOT NULL,
				requested_by BIGINT UNSIGNED NOT NULL,
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
				KEY idx_cashdrawer_edit_approval_drawer (drawer_id, status)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
		);
		if ($db->has_error) {
			throw new \RuntimeException('Unable to prepare cashdrawer edit approvals: ' . (string)$db->error);
		}
	}
}
