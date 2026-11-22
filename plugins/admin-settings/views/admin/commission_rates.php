<?php

$admin_route = get_value()['admin_route'];
$plugin_route = get_value()['plugin_route'];
$charge_route = 'commission-rates';

$commission_rates = new \Settings\CommissionRate;
$session = get_session();

// Get all commission types for reference
$predefined_types = [
    'EZWICH CHARGE',
    'MTN DATA BUNDLE',
    'MOMOREMIT GHANAPAY CASHIN',
    'AGENCYBANKING WITHDRAWAL CIT GHQR',
    'AGENCYBANKING WITHDRAWAL CIT GCB/FIDELITY',
    'AGENCYBANKING INTERBANK',
    'MTN EVD',
    'Telecel EVD',
    'GhanaPay Cashout',
    'Telecel Cashout',
    'MTN Cashout',
    'GMoney Cashout',
    'AT Cashout',
    'Airtime Top-up',
    'Data Bundle',
    'Utility Payments'
];

$rate_id = is_numeric(URL(3)) ? URL(3) : (URL(3) === 'view' ? (URL(4) ?? null) : null);
$rate = null;
$view_mode = (URL(3) === 'view' && is_numeric(URL(4)));
$errors = $_SESSION['errors'] ?? [];
$success_message = $_SESSION['success_message'] ?? '';

if ($rate_id && is_numeric($rate_id) && URL(2) === 'commission-rates') {
    $rate = $commission_rates->first(['id' => $rate_id]);
}

unset($_SESSION['errors']);
unset($_SESSION['success_message']);

?>

<style>
    .commission-rates-shell { max-width: 1200px; margin: auto; }
    .commission-card {
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 16px;
        box-shadow: 0 12px 30px rgba(0, 0, 0, 0.2);
        background: var(--bs-body-bg);
    }
    .table-actions { white-space: nowrap; }
    .status-badge { padding: 0.4rem 0.85rem; border-radius: 20px; font-size: 0.82rem; font-weight: 600; }
    .status-active { background: rgba(34, 197, 94, 0.15); color: #22c55e; border: 1px solid rgba(34, 197, 94, 0.3); }
    .status-inactive { background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3); }
    
    .badge-subtle-primary { background: rgba(13, 110, 253, 0.12); color: #0d6efd; border: 1px solid rgba(13, 110, 253, 0.2); }
    .badge-subtle-warning { background: rgba(255, 193, 7, 0.15); color: #d97706; border: 1px solid rgba(255, 193, 7, 0.3); }
    .icon-box {
        width: 34px;
        height: 34px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        background: rgba(13, 110, 253, 0.1);
        color: #0d6efd;
    }
    .commission-hero {
        border: 0;
        border-radius: 18px;
        background: linear-gradient(135deg, #0d6efd 0%, #4338ca 55%, #7c3aed 100%);
        color: #fff;
    }
    .commission-stat {
        border: 1px solid rgba(148,163,184,.16);
        border-radius: 14px;
        background: var(--bs-body-bg);
        transition: transform .16s ease, box-shadow .16s ease;
    }
    .commission-stat:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 24px rgba(15,23,42,.10);
    }
    .commission-table tbody tr {
        transition: background-color .14s ease;
    }
    .commission-table tbody tr:hover {
        background: rgba(13,110,253,.055);
    }
    .commission-type-mark {
        width: 42px;
        height: 42px;
        border-radius: 12px;
        background: rgba(13,110,253,.10);
        color: #0d6efd;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    .commission-filter {
        min-width: 230px;
        border-radius: 12px;
    }
</style>

<div class="commission-rates-shell mx-auto p-3">
    <!-- Success Message -->
    <?php if (!empty($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show mb-4 rounded-3 shadow-sm" role="alert">
            <i class="fa-solid fa-circle-check me-2"></i> <?= esc($success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Error Messages -->
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-4 rounded-3 shadow-sm" role="alert">
            <i class="fa-solid fa-circle-exclamation me-2"></i>
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $error): ?>
                    <li><?= esc($error) ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($view_mode && $rate): ?>
        <?php
            $isFlat = strcasecmp((string)($rate->charge_type ?? 'percentage'), 'flat') === 0;
            $chargeLabel = $isFlat
                ? 'GHS ' . number_format((float)$rate->commission_rate, 2)
                : number_format((float)$rate->commission_rate, 2) . '%';
        ?>
        <div class="card commission-hero mb-4">
            <div class="card-body p-4 p-lg-5">
                <div class="d-flex justify-content-between align-items-start gap-3">
                    <div>
                        <div class="text-white-50 small text-uppercase fw-bold mb-2">Commission charge</div>
                        <h3 class="mb-2"><?= esc($rate->commission_type) ?></h3>
                        <div class="fs-2 fw-bold"><?= esc($chargeLabel) ?></div>
                    </div>
                    <span class="status-badge <?= !empty($rate->status) ? 'status-active' : 'status-inactive' ?>">
                        <?= !empty($rate->status) ? 'Active' : 'Inactive' ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-lg-3">
                <div class="commission-stat p-3 h-100">
                    <small class="text-muted d-block">Charge type</small>
                    <strong><?= $isFlat ? 'Flat amount' : 'Percentage' ?></strong>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="commission-stat p-3 h-100">
                    <small class="text-muted d-block">Starting amount</small>
                    <strong>GHS <?= number_format((float)($rate->starting_amount ?? 0), 2) ?></strong>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="commission-stat p-3 h-100">
                    <small class="text-muted d-block">Maximum charge</small>
                    <strong><?= !empty($rate->is_capped) ? 'GHS ' . number_format((float)$rate->cap_value, 2) : 'No cap' ?></strong>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="commission-stat p-3 h-100">
                    <small class="text-muted d-block">Expected collection</small>
                    <strong><?= number_format((float)($rate->expected_value ?? 0), 2) ?>%</strong>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center">
            <a href="<?= ROOT . '/' . $admin_route . '/' . $plugin_route . '/commission-rates' ?>" class="btn btn-outline-secondary rounded-pill px-4">
                <i class="fa-solid fa-chevron-left me-1"></i> Back to list
            </a>
            <?php if (user_can('edit_settings')): ?>
                <a href="<?= ROOT . '/' . $admin_route . '/' . $plugin_route . '/commission-rates/' . (int)$rate->id ?>" class="btn btn-primary rounded-pill px-4">
                    <i class="fa-solid fa-pen-to-square me-1"></i> Edit commission
                </a>
            <?php endif; ?>
        </div>

    <?php elseif ($view_mode && !$rate): ?>
        <div class="alert alert-warning">Commission rate not found.</div>

    <?php endif; ?>

    <!-- Add/Edit Form -->
    <?php if (!$view_mode && URL(2) === 'commission-rates' && (URL(3) === 'add' || (is_numeric(URL(3)) && $rate))): ?>
        
        <div class="card commission-card mb-4">
            <div class="card-body p-4">
                <h4 class="mb-4 d-flex align-items-center gap-2">
                    <i class="fa-solid fa-percent text-primary"></i>
                    <span><?= (URL(3) === 'add') ? 'Add New Commission Rate' : 'Edit Commission Rate' ?></span>
                </h4>

                <form method="POST" action="<?= ROOT . '/' . $admin_route . '/' . $plugin_route . '/' . $charge_route . '/' . (URL(3) === 'add' ? 'add' : $rate->id) ?>" class="needs-validation">

                    <div class="row g-4">
                        <!-- Commission Type -->
                        <div class="col-md-6">
                            <label for="commission_type" class="form-label fw-bold">Commission Type <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="commission_type" name="commission_type" 
                                value="<?= esc($rate->commission_type ?? old_value('commission_type', '', 'post')) ?>"
                                placeholder="e.g., MTN EVD, Telecel Cashout" required
                                list="commission_types">
                            <datalist id="commission_types">
                                <?php foreach ($predefined_types as $type): ?>
                                    <option value="<?= esc($type) ?>">
                                <?php endforeach; ?>
                            </datalist>
                            <small class="text-muted d-block mt-1">
                                <i class="fa-solid fa-info-circle me-1"></i> Predefined: <?= implode(', ', array_slice($predefined_types, 0, 3)) ?>...
                            </small>
                        </div>

                        <!-- Commission Rate -->
                        <div class="col-md-6">
                            <label for="charge_type" class="form-label fw-bold">Charge Type</label>
                            <select class="form-select" id="charge_type" name="charge_type">
                                <option value="percentage" <?= (strtolower($rate->charge_type ?? 'percentage') === 'percentage') ? 'selected' : '' ?>>Percentage of amount</option>
                                <option value="flat" <?= (strtolower($rate->charge_type ?? '') === 'flat') ? 'selected' : '' ?>>Flat amount</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="commission_rate" class="form-label fw-bold">Commission Rate <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="commission_rate" name="commission_rate" 
                                    value="<?= esc($rate->commission_rate ?? old_value('commission_rate', '0', 'post')) ?>"
                                    min="0" max="100" step="0.01" required>
                                <span class="input-group-text charge-rate-suffix"><?= (strtolower($rate->charge_type ?? 'percentage') === 'flat') ? 'GHS' : '%' ?></span>
                            </div>
                            <small class="text-muted d-block mt-1">
                                Percentage or flat commission amount
                            </small>
                        </div>

                        <div class="col-md-6">
                            <label for="starting_amount" class="form-label fw-bold">Starting Amount (GHS)</label>
                            <div class="input-group">
                                <span class="input-group-text">GHS</span>
                                <input type="number" class="form-control" id="starting_amount" name="starting_amount"
                                    value="<?= esc($rate->starting_amount ?? old_value('starting_amount', '0', 'post')) ?>"
                                    min="0" step="0.01">
                            </div>
                            <small class="text-muted d-block mt-1">
                                Charge only applies from this amount (0 applies to all amounts)
                            </small>
                        </div>

                        <!-- Is Capped Switch -->
                        <div class="col-md-6 d-flex align-items-center">
                            <div class="form-check form-switch pt-2">
                                <input class="form-check-input" type="checkbox" id="is_capped" name="is_capped" 
                                    <?= (isset($rate->is_capped) && $rate->is_capped) ? 'checked' : '' ?>>
                                <label class="form-check-label fw-bold ms-2" for="is_capped">
                                    Enable Cap on Commission
                                </label>
                                <small class="text-muted d-block mt-1">
                                    Check this if you want to set a maximum commission value
                                </small>
                            </div>
                        </div>

                        <!-- Cap Value -->
                        <div class="col-md-6" id="cap_value_wrapper">
                            <label for="cap_value" class="form-label fw-bold">Cap Value (GHS)</label>
                            <div class="input-group">
                                <span class="input-group-text">GHS</span>
                                <input type="number" class="form-control" id="cap_value" name="cap_value" 
                                    value="<?= esc($rate->cap_value ?? old_value('cap_value', '0', 'post')) ?>"
                                    min="0" step="0.01">
                            </div>
                            <small class="text-muted d-block mt-1">
                                Maximum commission amount if cap is enabled
                            </small>
                        </div>

                        <!-- Expected Value -->
                        <div class="col-md-6">
                            <label for="expected_value" class="form-label fw-bold">Expected Value (% of Commission) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="expected_value" name="expected_value" 
                                    value="<?= esc($rate->expected_value ?? old_value('expected_value', '0', 'post')) ?>"
                                    min="0" max="100" step="0.01" required>
                                <span class="input-group-text">%</span>
                            </div>
                            <small class="text-muted d-block mt-1">
                                Percentage of commission expected to be collected
                            </small>
                        </div>

                        <!-- Status Switch -->
                        <div class="col-md-6 d-flex align-items-center">
                            <div class="form-check form-switch pt-2">
                                <input class="form-check-input" type="checkbox" id="status" name="status" 
                                    <?= (empty($rate) || (isset($rate->status) && $rate->status)) ? 'checked' : '' ?>>
                                <label class="form-check-label fw-bold ms-2" for="status">
                                    Active Status
                                </label>
                                <small class="text-muted d-block mt-1">
                                    Uncheck to disable this commission rate
                                </small>
                            </div>
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="d-flex gap-2 justify-content-between mt-5 pt-4 border-top">
                        <a href="<?= ROOT . '/' . $admin_route . '/' . $plugin_route . '/' . $charge_route ?>" class="btn btn-outline-secondary px-4 rounded-pill">
                            <i class="fa-solid fa-chevron-left me-1"></i> Back
                        </a>
                        <button type="submit" class="btn btn-primary px-4 rounded-pill">
                            <i class="fa-solid fa-save me-1"></i> 
                            <?= (URL(3) === 'add') ? 'Add Commission Rate' : 'Update Commission Rate' ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>

    <?php elseif (!$view_mode): ?>

        <!-- Commission Rates List -->
        <div class="card commission-card mb-4">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h4 class="mb-1 d-flex align-items-center gap-2">
                            <i class="fa-solid fa-percent text-primary"></i> Commission Charges
                        </h4>
                        <p class="text-muted small mb-0">Manage transaction-specific percentage or flat charges, starting amounts, and caps</p>
                    </div>
                    <?php if (user_can('edit_settings')): ?>
                        <a href="<?= ROOT . '/' . $admin_route . '/' . $plugin_route . '/' . $charge_route . '/add' ?>" class="btn btn-success rounded-pill px-3 shadow-sm">
                            <i class="fa-solid fa-plus me-1"></i> Add Charge
                        </a>
                    <?php endif; ?>
                </div>

                <?php
                    $allRates = function_exists('get_all_commission_rates') ? get_all_commission_rates(false) : [];
                    $activeCount = is_array($allRates) ? count(array_filter($allRates, fn($item) => !empty($item->status))) : 0;
                    $cappedCount = is_array($allRates) ? count(array_filter($allRates, fn($item) => !empty($item->is_capped))) : 0;
                ?>
                <div class="row g-3 mb-4">
                    <div class="col-sm-6 col-lg-4">
                        <div class="commission-stat p-3">
                            <small class="text-muted d-block">Total charges</small>
                            <strong class="fs-4"><?= count($allRates) ?></strong>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-4">
                        <div class="commission-stat p-3">
                            <small class="text-muted d-block">Active</small>
                            <strong class="fs-4 text-success"><?= $activeCount ?></strong>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-4">
                        <div class="commission-stat p-3">
                            <small class="text-muted d-block">Capped charges</small>
                            <strong class="fs-4 text-primary"><?= $cappedCount ?></strong>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-end mb-3">
                    <div class="input-group commission-filter">
                        <span class="input-group-text bg-transparent"><i class="fa-solid fa-magnifying-glass"></i></span>
                        <input type="search" id="commissionSearch" class="form-control" placeholder="Search charges">
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 commission-table" id="commissionTable">
                        <thead class="bg-light text-uppercase fs-7 text-muted">
                            <tr>
                                <th class="py-3 ps-3">Commission Type</th>
                                <th class="text-center py-3">Rate / Capped</th>
                                <th class="text-end py-3">Starting / Cap Amount</th>
                                <th class="text-center py-3">Expected / Status</th>
                                <th class="text-center py-3 pe-3 table-actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                                $rates = function_exists('get_all_commission_rates') 
                                    ? get_all_commission_rates(false) 
                                    : [];

                                if (!empty($rates) && is_array($rates)):
                            ?>
                                <?php foreach ($rates as $r): ?>
                                    <tr>
                                        <td class="fw-bold ps-3 commission-row-name">
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="commission-type-mark">
                                                    <i class="fa-solid fa-tags"></i>
                                                </div>
                                                <span><?= esc($r->commission_type ?? '') ?></span>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge badge-subtle-primary px-3 py-2 rounded-pill fw-bold">
                                                <?= number_format((float)($r->commission_rate ?? 0), 2) ?><?= (strtolower($r->charge_type ?? 'percentage') === 'flat') ? ' GHS' : '%' ?>
                                            </span>
                                            <div class="small mt-1">
                                                <?php if (!empty($r->is_capped)): ?>
                                                    <span class="text-success fw-semibold"><i class="fa-solid fa-lock me-1"></i>Capped</span>
                                                <?php else: ?>
                                                    <span class="text-muted">Not capped</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="text-end fw-semibold">
                                            <div>
                                                <small class="text-muted d-block">Starting</small>
                                                GHS <?= number_format((float)($r->starting_amount ?? 0), 2) ?>
                                            </div>
                                            <?php if (!empty($r->is_capped)): ?>
                                                <div class="mt-1">
                                                    <small class="text-muted d-block">Cap</small>
                                                    GHS <?= number_format((float)($r->cap_value ?? 0), 2) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge badge-subtle-warning px-3 py-2 rounded-pill fw-bold">
                                                <?= number_format((float)($r->expected_value ?? 0), 2) ?>%
                                            </span>
                                            <div class="small mt-1">
                                                <span class="status-badge <?= !empty($r->status) ? 'status-active' : 'status-inactive' ?>">
                                                    <i class="fa-solid fa-circle fs-9 me-1"></i>
                                                    <?= !empty($r->status) ? 'Active' : 'Inactive' ?>
                                                </span>
                                            </div>
                                        </td>
                                        <td class="text-center pe-3 table-actions">
                                            <a href="<?= ROOT . '/' . $admin_route . '/' . $plugin_route . '/commission-rates/view/' . ($r->id ?? '') ?>"
                                               title="View Charge"
                                               class="btn btn-outline-primary btn-sm rounded-circle p-2">
                                                <i class="fa-solid fa-eye"></i>
                                            </a>
                                            <?php if (user_can('edit_settings')): ?>
                                                <a href="<?= ROOT . '/' . $admin_route . '/' . $plugin_route . '/' . $charge_route . '/' . ($r->id ?? '') ?>" 
                                                   title="Edit Rate" 
                                                   class="btn btn-outline-warning btn-sm rounded-circle p-2">
                                                    <i class="fa-solid fa-pen-to-square"></i>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center py-5">
                                        <div class="empty-state py-3">
                                            <i class="fa-solid fa-folder-open text-muted fs-1 mb-3"></i>
                                            <h5 class="text-muted fw-bold">No Commission Rates Found</h5>
                                            <p class="text-muted small mb-0">Click "Add Commission Rate" above to record your first rate structure.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Back Button -->
        <div class="mb-4">
            <a href="<?= ROOT . '/' . $admin_route . '/' . $plugin_route ?>" class="btn btn-outline-secondary rounded-pill px-4">
                <i class="fa-solid fa-chevron-left me-1"></i> Back to Settings
            </a>
        </div>

    <?php endif; ?>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const isCapped = document.getElementById('is_capped');
        const capWrapper = document.getElementById('cap_value_wrapper');
        const capValue = document.getElementById('cap_value');
        
        if (isCapped && capWrapper) {
            const toggleCap = () => {
                capWrapper.style.display = isCapped.checked ? 'block' : 'none';
            };

            toggleCap();
            
            isCapped.addEventListener('change', function() {
                toggleCap();
                if (this.checked && capValue) {
                    capValue.focus();
                }
            });
        }

        const chargeType = document.getElementById('charge_type');
        const chargeSuffix = document.querySelector('.charge-rate-suffix');
        if (chargeType && chargeSuffix) {
            chargeType.addEventListener('change', function() {
                chargeSuffix.textContent = this.value === 'flat' ? 'GHS' : '%';
            });
        }

        const commissionSearch = document.getElementById('commissionSearch');
        const commissionTable = document.getElementById('commissionTable');
        if (commissionSearch && commissionTable) {
            commissionSearch.addEventListener('input', function() {
                const query = this.value.trim().toLowerCase();
                commissionTable.querySelectorAll('tbody tr').forEach(function(row) {
                    row.style.display = row.textContent.toLowerCase().includes(query) ? '' : 'none';
                });
            });
        }
    });
</script>