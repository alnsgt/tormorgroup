<?php

$admin_route = get_value()['admin_route'];
$plugin_route = get_value()['plugin_route'];

// Get commission rate ID from URL
$rate_id = URL(3) ?? null;
$commission_rates = new \Settings\CommissionRate;
$session = get_session();
$charge_route = 'commission-rates';

/**
 * Automatically creates the commission_rates table if it does not exist
 */
function ensureCommissionRatesTableExists($model) {
    $table = 'commission_rates';
    
    // Check if table already exists
    $checkQuery = "SHOW TABLES LIKE '$table'";
    $exists = $model->query($checkQuery);
    
    if (empty($exists)) {
        $sql = "CREATE TABLE IF NOT EXISTS `$table` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `commission_type` VARCHAR(255) NOT NULL COMMENT 'e.g., MTN EVD, Telecel EVD, GhanaPay Cashout, etc.',
            `applies_to` VARCHAR(255) DEFAULT NULL COMMENT 'Human-readable transaction or service this charge applies to',
            `transaction_type` VARCHAR(255) DEFAULT NULL,
            `network` VARCHAR(255) DEFAULT NULL,
            `source_option` VARCHAR(255) DEFAULT NULL,
            `source_value` VARCHAR(255) DEFAULT NULL,
        `charge_type` VARCHAR(20) NOT NULL DEFAULT 'percentage',
            `commission_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Commission rate as percentage of amount',
        `starting_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Minimum applicable transaction amount',
            `is_capped` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = capped, 0 = not capped',
            `cap_value` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Maximum commission value if capped',
            `expected_value` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Expected value as percentage of commission collected',
            `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = active, 0 = inactive',
            `date_created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `date_updated` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `created_by` INT(11) DEFAULT NULL,
            `updated_by` INT(11) DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_commission_type` (`commission_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        $model->query($sql);
    } else {
        $columns = $model->query("SHOW COLUMNS FROM `$table`");
        $columnNames = [];
        if (is_array($columns)) {
            foreach ($columns as $column) {
                $columnNames[] = $column->Field ?? $column['Field'] ?? '';
            }
        }
        $ruleColumns = [
            'applies_to' => "VARCHAR(255) DEFAULT NULL",
            'transaction_type' => "VARCHAR(255) DEFAULT NULL",
            'network' => "VARCHAR(255) DEFAULT NULL",
            'source_option' => "VARCHAR(255) DEFAULT NULL",
            'source_value' => "VARCHAR(255) DEFAULT NULL",
        ];
        foreach ($ruleColumns as $column => $type) {
            if (!in_array($column, $columnNames, true)) {
                $model->query("ALTER TABLE `$table` ADD COLUMN `$column` $type");
            }
        }
    }
}

if (URL(2) === 'commission-rates') {
    if (function_exists('dynamic_charge_ensure_defaults')) {
        dynamic_charge_ensure_defaults();
    }
    
    // Handle POST requests for add/edit
    if (!empty($_POST)) {
        // Ensure table exists on form submission
        ensureCommissionRatesTableExists($commission_rates);

        $action = URL(3) === 'add' ? 'add' : (is_numeric(URL(3)) ? 'edit' : 'list');
        
        $commission_type = trim($_POST['commission_type'] ?? '');
        $applies_to = $commission_type;
        if (function_exists('dynamic_charge_service_registry')) {
            foreach (dynamic_charge_service_registry() as $service) {
                if (strcasecmp((string)($service['key'] ?? ''), $commission_type) === 0) {
                    $applies_to = (string)($service['label'] ?? $commission_type);
                    break;
                }
            }
        }
        $transaction_type = trim($_POST['transaction_type'] ?? '');
        $network = trim($_POST['network'] ?? '');
        $source_option = trim($_POST['source_option'] ?? '');
        $source_value = trim($_POST['source_value'] ?? '');
        $charge_type = in_array(strtolower(trim($_POST['charge_type'] ?? 'percentage')), ['flat', 'percentage'], true)
            ? strtolower(trim($_POST['charge_type'] ?? 'percentage'))
            : 'percentage';
        $commission_rate = (float)($_POST['commission_rate'] ?? 0);
        $starting_amount = (float)($_POST['starting_amount'] ?? 0);
        $is_capped = isset($_POST['is_capped']) ? 1 : 0;
        $cap_value = (float)($_POST['cap_value'] ?? 0);
        $expected_value = (float)($_POST['expected_value'] ?? 0);
        $status = isset($_POST['status']) ? 1 : 0;
        
        // Validation
        $errors = [];
        
        if (empty($commission_type)) {
            $errors[] = 'Commission type is required';
        }

        if ($commission_rate < 0 || $commission_rate > 100) {
            $errors[] = 'Commission rate must be between 0 and 100';
        }
        
        if ($is_capped && $cap_value <= 0) {
            $errors[] = 'Cap value must be greater than 0 when cap is enabled';
        }
        
        if ($expected_value < 0 || $expected_value > 100) {
            $errors[] = 'Expected value must be between 0 and 100';
        }
        
        if (empty($errors)) {
            $data = [
                'commission_type' => $commission_type,
                'applies_to' => $applies_to,
                'transaction_type' => $transaction_type ?: null,
                'network' => $network ?: null,
                'source_option' => $source_option ?: null,
                'source_value' => $source_value ?: null,
                'charge_type' => $charge_type,
                'commission_rate' => $commission_rate,
                'starting_amount' => $starting_amount,
                'is_capped' => $is_capped,
                'cap_value' => $cap_value,
                'expected_value' => $expected_value,
                'status' => $status,
                'updated_by' => $session->id ?? null
            ];
            
            if ($action === 'add') {
                $data['created_by'] = $session->id ?? null;
                
                // Check if commission type already exists
                $existing = $commission_rates->first(['commission_type' => $commission_type]);
                if ($existing) {
                    $errors[] = 'This commission type already exists';
                } else {
                    $commission_rates->insert($data);
                    $_SESSION['success_message'] = 'Commission rate added successfully';
                    header('Location: ' . ROOT . '/' . $admin_route . '/' . $plugin_route . '/' . $charge_route);
                    exit;
                }
            } else {
                // Edit existing
                if ($rate_id && is_numeric($rate_id)) {
                    $rate = $commission_rates->first(['id' => $rate_id]);
                    if ($rate) {
                        // Check if commission type is being changed to an existing one
                        if ($rate->commission_type !== $commission_type) {
                            $existing = $commission_rates->first(['commission_type' => $commission_type]);
                            if ($existing) {
                                $errors[] = 'This commission type already exists';
                            }
                        }
                        
                        if (empty($errors)) {
                            $commission_rates->update($rate_id, $data);
                            $_SESSION['success_message'] = 'Commission rate updated successfully';
                            header('Location: ' . ROOT . '/' . $admin_route . '/' . $plugin_route . '/' . $charge_route);
                            exit;
                        }
                    }
                }
            }
        }
    }
}