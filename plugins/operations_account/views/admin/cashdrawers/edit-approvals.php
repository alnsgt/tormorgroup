<?php
if (!user_can('is_admin')): ?>
	<div class="alert alert-danger">Only an administrator can review cashdrawer edit requests.</div>
<?php else: ?>
	<style>
		.cashdrawer-review-modal .modal-content {
			overflow: hidden;
			border: 0;
			border-radius: 1rem;
			box-shadow: 0 1.5rem 4rem rgba(15, 23, 42, .2);
		}
		.cashdrawer-review-modal .modal-header {
			padding: 1.25rem 1.5rem;
			border: 0;
			background: linear-gradient(115deg, #172554, #1d4ed8);
			color: #fff;
		}
		.cashdrawer-review-modal .modal-header .small {
			color: rgba(255, 255, 255, .72) !important;
		}
		.cashdrawer-review-modal .btn-close {
			filter: invert(1) grayscale(100%) brightness(200%);
		}
		.cashdrawer-review-title-icon {
			display: grid;
			width: 2.75rem;
			height: 2.75rem;
			place-items: center;
			border: 1px solid rgba(255, 255, 255, .25);
			border-radius: .85rem;
			background: rgba(255, 255, 255, .14);
			font-size: 1.15rem;
		}
		.cashdrawer-review-summary {
			display: flex;
			align-items: flex-start;
			gap: .85rem;
			padding: 1rem;
			border: 1px solid var(--bs-border-color);
			border-radius: .85rem;
			background: var(--bs-secondary-bg);
		}
		.cashdrawer-review-summary-icon {
			display: grid;
			width: 2.25rem;
			height: 2.25rem;
			flex: 0 0 2.25rem;
			place-items: center;
			border-radius: .7rem;
			background: var(--bs-warning-bg-subtle);
			color: var(--bs-warning-text-emphasis);
		}
		.cashdrawer-review-change {
			height: 100%;
			padding: 1rem;
			border: 1px solid var(--bs-border-color);
			border-left: 4px solid var(--bs-primary);
			border-radius: .85rem;
			background: var(--bs-body-bg);
			box-shadow: 0 .35rem 1rem rgba(15, 23, 42, .07);
		}
		.cashdrawer-review-change-label {
			display: block;
			margin-bottom: .75rem;
			color: var(--bs-body-color);
			font-size: .95rem;
			font-weight: 700;
		}
		.cashdrawer-review-value {
			background: var(--bs-tertiary-bg) !important;
			color: var(--bs-body-color) !important;
			font-weight: 600;
		}
		.cashdrawer-review-value--requested {
			border-color: var(--bs-primary) !important;
			background: var(--bs-primary-bg-subtle) !important;
			color: var(--bs-primary-text-emphasis) !important;
		}
		.cashdrawer-review-proof {
			display: flex;
			height: 100%;
			min-height: 10rem;
			flex-direction: column;
			overflow: hidden;
			border: 1px solid var(--bs-border-color);
			border-radius: .75rem;
			background: var(--bs-secondary-bg);
			text-decoration: none;
		}
		.cashdrawer-review-proof img {
			display: block;
			width: 100%;
			min-height: 9rem;
			max-height: 13rem;
			flex: 1 1 auto;
			object-fit: contain;
			background: var(--bs-tertiary-bg);
		}
		.cashdrawer-review-proof-caption {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: .5rem;
			padding: .55rem .75rem;
			color: var(--bs-secondary-color);
			font-size: .78rem;
			font-weight: 600;
		}
		.cashdrawer-review-modal .modal-footer {
			border-top: 1px solid var(--bs-border-color);
			background: var(--bs-tertiary-bg);
		}
		.cashdrawer-review-modal .btn-success {
			box-shadow: 0 .35rem .8rem rgba(25, 135, 84, .2);
			font-weight: 600;
		}
	</style>
	<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
		<div>
			<h2 class="h4 mb-1">Cashdrawer edit approvals</h2>
			<p class="text-muted mb-0">Operations manager edits remain unapplied until an administrator approves them.</p>
		</div>
		<a class="btn btn-outline-secondary" href="<?=ROOT?>/<?=$admin_route?>/<?=$plugin_route?>">
			<i class="fa fa-arrow-left me-1" aria-hidden="true"></i> Back to cashdrawers
		</a>
	</div>
	<?php if (!empty($cashdrawerEditApprovalError)): ?>
		<div class="alert alert-danger"><?= esc($cashdrawerEditApprovalError) ?></div>
	<?php endif; ?>
	<?php $cashdrawerApprovalModals = []; ?>
	<div class="table-responsive">
		<table class="table table-striped align-middle">
			<thead>
				<tr>
					<th>Request</th>
					<th>Drawer</th>
					<th>Requested by</th>
					<th>Proposed edit</th>
					<th>Submitted</th>
					<th>Action</th>
				</tr>
			</thead>
			<tbody>
			<?php if (!empty($cashdrawerEditApprovalError)): ?>
				<tr><td colspan="6" class="text-center text-danger py-4">Pending requests could not be loaded.</td></tr>
			<?php elseif (!empty($cashdrawerEditRequests)): ?>
				<?php foreach ($cashdrawerEditRequests as $approval): ?>
					<?php
						$requestData = json_decode((string)($approval->request_data ?? ''), true);
						$editPost = is_array($requestData) ? ($requestData['post'] ?? []) : [];
						$proofData = json_decode((string)($approval->proof_data ?? ''), true);
						$proofData = is_array($proofData) ? $proofData : [];
						$requester = trim((string)($approval->requester_name ?? ''));
					$current = is_array($approval->current_values ?? null) ? $approval->current_values : [];
					$currentGhsDenos = is_array($current['ghs_denominations'] ?? null) ? $current['ghs_denominations'] : [];
					$currentCfaDenos = is_array($current['cfa_denominations'] ?? null) ? $current['cfa_denominations'] : [];
					$currentEcash = is_array($current['ecash'] ?? null) ? $current['ecash'] : [];
					$proposedDenosRaw = $editPost['denos'] ?? '';
					$proposedDenos = is_array($proposedDenosRaw)
						? $proposedDenosRaw
						: (is_string($proposedDenosRaw) ? json_decode($proposedDenosRaw, true) : null);
					$proposedGhsDenos = [];
					$proposedCfaDenos = [];
					foreach (is_array($proposedDenos) ? $proposedDenos : [] as $denominationRow) {
						if (!is_array($denominationRow)) {
							continue;
						}
						$currency = strtoupper(trim((string)($denominationRow['currency'] ?? 'GHS')));
						$denomination = number_format((float)($denominationRow['denomination'] ?? 0), 2, '.', '');
						$count = max(0, (int)($denominationRow['count'] ?? 0));
						if ((float)$denomination <= 0) {
							continue;
						}
						if ($currency === 'CFA') {
							$proposedCfaDenos[$denomination] = ($proposedCfaDenos[$denomination] ?? 0) + $count;
						} elseif ($currency === 'GHS') {
							$proposedGhsDenos[$denomination] = ($proposedGhsDenos[$denomination] ?? 0) + $count;
						}
					}
					$proposedEcash = [];
					foreach ($editPost as $field => $value) {
						if (strpos((string)$field, 'ecash_') !== 0 || strpos((string)$field, 'ecash_proof_') === 0) {
							continue;
						}
						$rawChannel = strtoupper(substr((string)$field, 6));
						$key = trim((string)preg_replace('/[^A-Z0-9]+/', '_', $rawChannel), '_');
						if ($key !== '') {
							$proposedEcash['ECASH_' . $key] = (float)$value;
						}
					}
					$normalizedCurrentEcash = [];
					foreach ($currentEcash as $channel => $amount) {
						$key = strtoupper(trim((string)$channel));
						if ($key !== '' && strpos($key, 'ECASH_') !== 0) {
							$key = 'ECASH_' . $key;
						}
						if ($key !== '') {
							$normalizedCurrentEcash[$key] = (float)$amount;
						}
					}
					$projectRoot = dirname(__DIR__, 5);
					$cashdrawerProofDirectory = realpath($projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'cashdrawers');
					$proofImageUrl = static function($path) use ($projectRoot, $cashdrawerProofDirectory): string {
						$relativePath = str_replace('\\', '/', trim((string)$path));
						if (
							$cashdrawerProofDirectory === false
							|| strpos($relativePath, 'uploads/cashdrawers/') !== 0
							|| in_array('..', explode('/', $relativePath), true)
						) {
							return '';
						}
						$absolutePath = realpath($projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
						if (
							$absolutePath === false
							|| !is_file($absolutePath)
							|| strpos($absolutePath, $cashdrawerProofDirectory . DIRECTORY_SEPARATOR) !== 0
							|| !in_array(strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif'], true)
						) {
							return '';
						}
						return rtrim(ROOT, '/') . '/' . $relativePath;
					};
					$changes = [];
					$addChange = static function(string $label, string $before, string $after, string $proofImage = '') use (&$changes): void {
						if ($before !== $after) {
							$changes[] = [
								'label' => $label,
								'before' => $before,
								'after' => $after,
								'proof_image' => $proofImage,
							];
						}
					};
					if (!empty($current['found'])) {
						$denominationChanges = [
							['GHS', $currentGhsDenos, $proposedGhsDenos],
							['CFA', $currentCfaDenos, $proposedCfaDenos],
						];
						foreach ($denominationChanges as [$currency, $beforeMap, $afterMap]) {
							$denominations = array_unique(array_merge(array_keys($beforeMap), array_keys($afterMap)));
							rsort($denominations, SORT_NUMERIC);
							foreach ($denominations as $denomination) {
								$beforeCount = (int)($beforeMap[$denomination] ?? 0);
								$afterCount = (int)($afterMap[$denomination] ?? 0);
								if ($beforeCount !== $afterCount) {
									$addChange($currency . ' ' . number_format((float)$denomination, 2) . ' denomination count', number_format($beforeCount), number_format($afterCount));
								}
							}
						}
						foreach ($proposedEcash as $channel => $amount) {
							$oldAmount = (float)($normalizedCurrentEcash[$channel] ?? 0);
							$addChange(
								ucwords(strtolower(str_replace('_', ' ', substr($channel, 6))) ) . ' opening balance',
								number_format($oldAmount, 2),
								number_format($amount, 2),
								$proofImageUrl($proofData[$channel] ?? '')
							);
						}
						foreach (['buying_rate' => 'CFA buying rate', 'selling_rate' => 'CFA selling rate'] as $rateKey => $rateLabel) {
							if (isset($editPost[$rateKey])) {
								$oldRate = (float)($current['rates'][$rateKey] ?? 0);
								$addChange($rateLabel, number_format($oldRate, 2), number_format((float)$editPost[$rateKey], 2));
							}
						}
					}
					?>
					<?php if (($approval->status ?? '') !== 'processing'): ?>
						<?php ob_start(); ?>
							<div class="modal fade cashdrawer-review-modal" id="cashdrawerApprovalModal<?= (int)($approval->id ?? 0) ?>" tabindex="-1" aria-labelledby="cashdrawerApprovalTitle<?= (int)($approval->id ?? 0) ?>" aria-hidden="true">
								<div class="modal-dialog modal-lg modal-dialog-scrollable">
									<div class="modal-content">
										<div class="modal-header">
											<div class="d-flex align-items-center gap-3">
												<span class="cashdrawer-review-title-icon"><i class="fa-solid fa-clipboard-check" aria-hidden="true"></i></span>
												<div>
													<h2 class="modal-title fs-5 mb-1" id="cashdrawerApprovalTitle<?= (int)($approval->id ?? 0) ?>">Review cashdrawer edit</h2>
													<div class="small">Request #<?= (int)($approval->id ?? 0) ?> <span class="mx-1">·</span> Drawer #<?= (int)($approval->drawer_id ?? 0) ?></div>
												</div>
											</div>
											<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
										</div>
										<div class="modal-body">
											<div class="cashdrawer-review-summary mb-4">
												<span class="cashdrawer-review-summary-icon"><i class="fa-regular fa-clock" aria-hidden="true"></i></span>
												<div>
													<div class="fw-semibold">
														<?= esc($requester !== '' ? $requester : ('User #' . (int)($approval->requested_by ?? 0))) ?>
														submitted this edit on <?= esc((string)($approval->date_created ?? '')) ?>.
													</div>
													<div class="small text-muted mt-1">Changes remain inactive until approved.</div>
													<?php if (!empty($approval->assigned_admin_name)): ?>
														<div class="small mt-1">Assigned approver: <strong><?= esc((string)$approval->assigned_admin_name) ?></strong></div>
													<?php endif; ?>
												</div>
											</div>
											<?php if (empty($current['found'])): ?>
												<div class="alert alert-danger mb-0">This cashdrawer no longer exists. The request cannot be approved.</div>
											<?php elseif (empty($changes)): ?>
												<div class="alert alert-info mb-0">No editable inputs differ from the current cashdrawer.</div>
											<?php else: ?>
												<p class="small text-muted mb-2">Only changed inputs are shown.</p>
												<div class="row g-3">
													<?php foreach ($changes as $change): ?>
														<div class="col-12">
															<div class="cashdrawer-review-change">
																<span class="cashdrawer-review-change-label"><?= esc($change['label']) ?></span>
																<div class="row g-3 align-items-stretch">
																	<div class="<?= $change['proof_image'] !== '' ? 'col-lg-8' : 'col-12' ?>">
																		<div class="row g-2">
																			<div class="col-sm-6">
																				<label class="form-label small text-muted mb-1">Current value</label>
																				<input class="form-control form-control-sm cashdrawer-review-value" value="<?= esc($change['before']) ?>" readonly>
																			</div>
																			<div class="col-sm-6">
																				<label class="form-label small text-muted mb-1">Requested value</label>
																				<input class="form-control form-control-sm cashdrawer-review-value cashdrawer-review-value--requested" value="<?= esc($change['after']) ?>" readonly>
																			</div>
																		</div>
																	</div>
																	<?php if ($change['proof_image'] !== ''): ?>
																		<div class="col-lg-4">
																			<a href="<?= esc($change['proof_image']) ?>" target="_blank" rel="noopener noreferrer" class="cashdrawer-review-proof">
																				<img src="<?= esc($change['proof_image']) ?>" alt="<?= esc($change['label']) ?> proof">
																				<span class="cashdrawer-review-proof-caption">
																					<span><i class="fa-regular fa-image me-1" aria-hidden="true"></i>Channel proof</span>
																					<i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
																				</span>
																			</a>
																		</div>
																	<?php endif; ?>
																</div>
															</div>
														</div>
													<?php endforeach; ?>
												</div>
											<?php endif; ?>
										</div>
										<div class="modal-footer">
											<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close review</button>
											<?php if (!empty($current['found'])): ?>
												<form method="post" action="<?=ROOT?>/<?=$admin_route?>/<?=$plugin_route?>/cashdrawer_edit_approvals" class="d-flex gap-2">
													<?=csrf()?>
													<input type="hidden" name="request_id" value="<?= (int)($approval->id ?? 0) ?>">
													<input type="hidden" name="decision" value="reject">
													<button class="btn btn-outline-danger" type="submit">Reject</button>
												</form>
												<form method="post" action="<?=ROOT?>/<?=$admin_route?>/<?=$plugin_route?>/cashdrawer_edit_approvals">
													<?=csrf()?>
													<input type="hidden" name="request_id" value="<?= (int)($approval->id ?? 0) ?>">
													<input type="hidden" name="decision" value="approve">
													<button class="btn btn-success" type="submit">
														<i class="fa-solid fa-check me-1" aria-hidden="true"></i>Approve changes
													</button>
												</form>
											<?php endif; ?>
										</div>
									</div>
								</div>
							</div>
						<?php $cashdrawerApprovalModals[] = ob_get_clean(); ?>
					<?php endif; ?>
					<tr>
						<td>#<?= (int)($approval->id ?? 0) ?></td>
						<td>#<?= (int)($approval->drawer_id ?? 0) ?></td>
						<td>
							<?= esc($requester !== '' ? $requester : ('User #' . (int)($approval->requested_by ?? 0))) ?>
							<?php if (!empty($approval->assigned_admin_name)): ?>
								<div class="small text-muted">Assigned to <?= esc((string)$approval->assigned_admin_name) ?></div>
							<?php endif; ?>
						</td>
						<td>
						<span class="fw-semibold"><?= esc((string)($editPost['currency'] ?? 'GHS')) ?> <?= number_format((float)($editPost['amount'] ?? 0), 2) ?></span>
						<div class="small text-muted"><?= count($changes) ?> changed item<?= count($changes) === 1 ? '' : 's' ?></div>
						</td>
						<td><?= esc((string)($approval->date_created ?? '')) ?></td>
						<td>
							<?php if (($approval->status ?? '') === 'processing'): ?>
								<span class="badge bg-warning text-dark">Applying</span>
							<?php else: ?>
							<button class="btn btn-sm btn-warning" type="button" data-bs-toggle="modal" data-bs-target="#cashdrawerApprovalModal<?= (int)($approval->id ?? 0) ?>">
								<i class="fa-solid fa-eye me-1" aria-hidden="true"></i>Review &amp; approve
							</button>
							<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
			<?php else: ?>
				<tr><td colspan="6" class="text-center text-muted py-4">There are no pending cashdrawer edits.</td></tr>
			<?php endif; ?>
			</tbody>
		</table>
	</div>
	<?php foreach ($cashdrawerApprovalModals as $cashdrawerApprovalModal): ?>
		<?= $cashdrawerApprovalModal ?>
	<?php endforeach; ?>
	<?php $requestedApprovalId = max(0, (int)($_GET['request_id'] ?? 0)); ?>
	<?php if ($requestedApprovalId > 0): ?>
		<script>
			document.addEventListener('DOMContentLoaded', function () {
				var approvalModal = document.getElementById('cashdrawerApprovalModal<?= $requestedApprovalId ?>');
				if (approvalModal && window.bootstrap && window.bootstrap.Modal) {
					window.bootstrap.Modal.getOrCreateInstance(approvalModal).show();
				}
			});
		</script>
	<?php endif; ?>
<?php endif; ?>
