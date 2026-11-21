<?php
if (!user_can('is_admin')): ?>
	<div class="alert alert-danger">Only an administrator can review cashdrawer edit requests.</div>
<?php else: ?>
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
	<div class="table-responsive">
		<table class="table table-striped align-middle">
			<thead>
				<tr>
					<th>Request</th>
					<th>Drawer</th>
					<th>Requested by</th>
					<th>Proposed opening</th>
					<th>Submitted</th>
					<th>Status / review</th>
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
						$requester = trim((string)($approval->requester_name ?? ''));
					?>
					<tr>
						<td>#<?= (int)($approval->id ?? 0) ?></td>
						<td>#<?= (int)($approval->drawer_id ?? 0) ?></td>
						<td><?= esc($requester !== '' ? $requester : ('User #' . (int)($approval->requested_by ?? 0))) ?></td>
						<td>
							<?= esc((string)($editPost['currency'] ?? 'GHS')) ?> <?= number_format((float)($editPost['amount'] ?? 0), 2) ?>
							<details class="mt-1">
								<summary class="small">Proposed values</summary>
								<pre class="small text-wrap mb-0"><?= esc(json_encode($editPost, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}') ?></pre>
							</details>
						</td>
						<td><?= esc((string)($approval->date_created ?? '')) ?></td>
						<td>
							<?php if (($approval->status ?? '') === 'processing'): ?>
								<span class="badge bg-warning text-dark">Applying</span>
							<?php else: ?>
							<form method="post" action="<?=ROOT?>/<?=$admin_route?>/<?=$plugin_route?>/cashdrawer_edit_approvals" class="d-flex flex-wrap gap-2">
								<?=csrf()?>
								<input type="hidden" name="request_id" value="<?= (int)($approval->id ?? 0) ?>">
								<button class="btn btn-sm btn-success" name="decision" value="approve" type="submit"
									onclick="return confirm('Apply this cashdrawer edit?');">Approve</button>
								<button class="btn btn-sm btn-outline-danger" name="decision" value="reject" type="submit"
									onclick="return confirm('Reject this cashdrawer edit?');">Reject</button>
							</form>
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
<?php endif; ?>
