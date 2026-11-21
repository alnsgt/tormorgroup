<?php
$statement = is_array($channelStatement ?? null) ? $channelStatement : [];
$allEntries = $statement['entries'] ?? [];
$currency = ($statement['channel_type'] ?? '') === 'cash' && ($statement['channel_key'] ?? '') === 'CASH-CFA' ? 'CFA' : 'GHS';
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$formatMoney = static fn($value): string => number_format((float)$value, 2);
$normalizeDateFilter = static function ($value): string {
	$value = trim((string)$value);
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
		return '';
	}
	[$year, $month, $day] = array_map('intval', explode('-', $value));
	return checkdate($month, $day, $year) ? $value : '';
};
$rawDateFrom = $_GET['start_date'] ?? null;
$rawDateTo = $_GET['end_date'] ?? null;
$dateFromInput = is_string($rawDateFrom) ? trim($rawDateFrom) : '';
$dateToInput = is_string($rawDateTo) ? trim($rawDateTo) : '';
$dateFrom = $normalizeDateFilter($dateFromInput);
$dateTo = $normalizeDateFilter($dateToInput);
$invalidDateFilter = ($rawDateFrom !== null && !is_string($rawDateFrom))
	|| ($rawDateTo !== null && !is_string($rawDateTo))
	|| ($dateFromInput !== '' && $dateFrom === '')
	|| ($dateToInput !== '' && $dateTo === '');
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
	$dateFrom = '';
	$dateTo = '';
	$invalidDateFilter = true;
}
$hasDateFilter = $dateFrom !== '' || $dateTo !== '';
$entries = array_values(array_filter($allEntries, static function ($entry) use ($dateFrom, $dateTo): bool {
	if ($dateFrom === '' && $dateTo === '') {
		return true;
	}
	$entryDate = substr(trim((string)($entry['date'] ?? '')), 0, 10);
	if ($entryDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate)) {
		return false;
	}
	return ($dateFrom === '' || $entryDate >= $dateFrom)
		&& ($dateTo === '' || $entryDate <= $dateTo);
}));
$openingTotal = 0.0;
$creditTotal = 0.0;
$debitTotal = 0.0;
foreach ($entries as $entry) {
	$openingTotal += (float)($entry['opening'] ?? 0);
	$creditTotal += (float)($entry['credit'] ?? 0);
	$debitTotal += (float)($entry['debit'] ?? 0);
}
$closingTotal = $entries ? (float)($entries[count($entries) - 1]['closing'] ?? 0) : 0.0;
$formatDate = static function ($value): string {
	$value = trim((string)$value);
	if ($value === '') {
		return 'Date unavailable';
	}
	try {
		return (new \DateTimeImmutable($value))->format('Y-m-d H:i:s');
	} catch (\Throwable $e) {
		return $value;
	}
};
$isPdfExport = !empty($channelStatementPdf);
$statementTitle = ($statement['is_shared'] ?? false)
	? $statement['channel_label'] . ' Shared Account Statement'
	: $statement['channel_label'] . ' Channel Statement';
$baseRoute = ROOT . '/' . ($admin_route ?? 'admin') . '/' . ($plugin_route ?? 'operations_accounts');
$statementPath = $baseRoute . '/view_channel_statement/' . (int)($statement['drawer_id'] ?? 0);
$statementParams = [
	'channel_type' => $statement['channel_type'] ?? '',
	'channel_key' => $statement['channel_key'] ?? '',
];
$statementQueryParams = $statementParams;
if ($dateFrom !== '') {
	$statementQueryParams['start_date'] = $dateFrom;
}
if ($dateTo !== '') {
	$statementQueryParams['end_date'] = $dateTo;
}
$statementQuery = http_build_query($statementQueryParams);
?>
<style>
	.channel-statement {
		--statement-bg: var(--bs-body-bg, #fff);
		--statement-text: var(--bs-body-color, #1d2b40);
		--statement-muted: var(--bs-secondary-color, #66758a);
		--statement-border: var(--bs-border-color, #dce3ed);
		--statement-subtle: var(--bs-tertiary-bg, #f3f6fa);
		max-width: 1280px;
		margin: 0 auto;
		padding: clamp(.75rem, 2vw, 1.5rem);
		border: 1px solid var(--statement-border);
		border-radius: 1rem;
		background: var(--statement-bg);
		color: var(--statement-text);
		box-shadow: 0 12px 32px rgba(15, 23, 42, .10);
	}
	.channel-statement * { box-sizing: border-box; }
	.channel-statement-hero {
		position: relative;
		overflow: hidden;
		display: flex;
		align-items: flex-start;
		justify-content: space-between;
		gap: 1rem;
		padding: clamp(1.1rem, 2.5vw, 1.8rem);
		border-radius: .85rem;
		background: linear-gradient(120deg, #10243d, #174a58 70%, #16834a);
		color: #fff;
	}
	.channel-statement-hero:after {
		position: absolute;
		right: -2.5rem;
		bottom: -5rem;
		width: 15rem;
		height: 15rem;
		border: 1px solid rgba(255,255,255,.16);
		border-radius: 50%;
		box-shadow: 0 0 0 1.5rem rgba(255,255,255,.035), 0 0 0 3rem rgba(255,255,255,.025);
		content: "";
		pointer-events: none;
	}
	.channel-statement-heading, .channel-statement-actions { position: relative; z-index: 1; }
	.channel-statement-eyebrow {
		margin-bottom: .35rem;
		color: #b7d8d0;
		font-size: .72rem;
		font-weight: 800;
		letter-spacing: .13em;
		text-transform: uppercase;
	}
	.channel-statement-title { margin: 0; color: #fff; font-size: clamp(1.35rem, 3vw, 2rem); font-weight: 750; line-height: 1.2; }
	.channel-statement-description { margin-top: .55rem; color: #e0eceb; font-size: .9rem; }
	.channel-statement-actions { display: flex; flex-wrap: wrap; gap: .55rem; }
	.channel-statement-actions .btn { border-radius: .65rem; font-weight: 650; }
	.channel-statement-filter {
		display: flex;
		align-items: end;
		flex-wrap: wrap;
		gap: .65rem;
		margin: 1rem 0;
		padding: .9rem 1rem;
		border: 1px solid var(--statement-border);
		border-radius: .75rem;
		background: var(--statement-subtle);
	}
	.channel-statement-filter label { display: block; margin-bottom: .25rem; color: var(--statement-muted); font-size: .72rem; font-weight: 750; }
	.channel-statement-filter .form-control { min-width: 10rem; border-color: var(--statement-border); background-color: var(--statement-bg); color: var(--statement-text); }
	.channel-statement-filter .btn { min-height: 38px; border-radius: .55rem; font-weight: 650; }
	.channel-statement-filter-note { margin: 0 0 .75rem; color: var(--statement-muted); font-size: .8rem; }
	.channel-statement-summary {
		width: 100%;
		margin: 1rem 0 1.25rem;
		border-collapse: separate;
		border-spacing: .65rem 0;
		table-layout: fixed;
	}
	.channel-statement-summary td {
		width: 20%;
		padding: 0;
		border: 0;
		vertical-align: top;
	}
	.channel-statement-summary-card {
		height: 100%;
		padding: .85rem .95rem;
		border: 1px solid var(--statement-border);
		border-top: 3px solid #16834a;
		border-radius: .7rem;
		background: var(--statement-subtle);
	}
	.channel-statement-summary td:nth-child(2) .channel-statement-summary-card { border-top-color: #3184cf; }
	.channel-statement-summary td:nth-child(3) .channel-statement-summary-card { border-top-color: #16834a; }
	.channel-statement-summary td:nth-child(4) .channel-statement-summary-card { border-top-color: #d65b63; }
	.channel-statement-summary td:nth-child(5) .channel-statement-summary-card { border-top-color: #7956bd; }
	.channel-statement-summary-label { display: block; margin-bottom: .35rem; color: var(--statement-muted); font-size: .72rem; font-weight: 750; letter-spacing: .04em; text-transform: uppercase; }
	.channel-statement-summary-value { display: block; color: var(--statement-text); font-size: 1rem; font-weight: 750; overflow-wrap: anywhere; }
	.channel-statement-ledger-heading { display: flex; align-items: center; justify-content: space-between; gap: .75rem; margin: .25rem 0 .65rem; }
	.channel-statement-ledger-heading h3 { margin: 0; color: var(--statement-text); font-size: 1rem; font-weight: 750; }
	.channel-statement-count { padding: .25rem .65rem; border: 1px solid var(--statement-border); border-radius: 999px; background: var(--statement-subtle); color: var(--statement-muted); font-size: .75rem; font-weight: 700; }
	.channel-statement-table-wrap { overflow: auto; border: 1px solid var(--statement-border); border-radius: .75rem; }
	.channel-statement-table { width: 100%; margin: 0; border-collapse: collapse; color: var(--statement-text); font-size: .83rem; }
	.channel-statement-table thead th { padding: .75rem .65rem; border-bottom: 1px solid var(--statement-border); background: var(--statement-subtle); color: var(--statement-muted); font-size: .7rem; font-weight: 800; letter-spacing: .045em; text-align: left; text-transform: uppercase; white-space: nowrap; }
	.channel-statement-table tbody tr { border-bottom: 1px solid var(--statement-border); }
	.channel-statement-table tbody tr:nth-child(even) { background: color-mix(in srgb, var(--statement-subtle) 55%, transparent); }
	.channel-statement-table tbody tr:last-child { border-bottom: 0; }
	.channel-statement-table tbody tr:hover { background: color-mix(in srgb, #16834a 9%, var(--statement-bg)); }
	.channel-statement-table td { padding: .7rem .65rem; color: var(--statement-text); vertical-align: top; }
	.channel-statement-table .statement-date, .channel-statement-table .statement-reference { white-space: nowrap; }
	.channel-statement-table .statement-date { font-variant-numeric: tabular-nums; }
	.channel-statement-table .statement-date strong,
	.channel-statement-table .statement-date .statement-drawer,
	.channel-statement-table .statement-reference .statement-movement,
	.channel-statement-table .statement-reference .statement-status,
	.channel-statement-table .statement-reference .statement-actor { display: block; }
	.channel-statement-table .statement-reference .statement-status { width: fit-content; margin-top: .3rem; }
	.channel-statement-table .statement-reference .statement-actor { margin-top: .2rem; }
	.channel-statement-table .statement-reference { white-space: normal; }
	.channel-statement-table .statement-drawer { color: var(--statement-muted); font-weight: 700; white-space: nowrap; }
	.channel-statement-table .statement-status { display: inline-block; padding: .2rem .45rem; border: 1px solid var(--statement-border); border-radius: 999px; background: var(--statement-subtle); color: var(--statement-text); font-size: .72rem; font-weight: 700; }
	.channel-statement-table .statement-actor { display: block; margin-top: .25rem; color: var(--statement-muted); font-size: .75rem; }
	.channel-statement-table .statement-number { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
	.channel-statement-table .statement-credit { color: #16834a; font-weight: 750; }
	.channel-statement-table .statement-debit { color: #c33f4a; font-weight: 750; }
	.channel-statement-empty { padding: 2.5rem 1rem !important; color: var(--statement-muted) !important; text-align: center; }
	.channel-statement-empty i { display: block; margin-bottom: .6rem; color: #16834a; font-size: 1.5rem; }
	.channel-statement-footer { display: flex; justify-content: space-between; gap: 1rem; margin-top: .8rem; color: var(--statement-muted); font-size: .75rem; }
	.channel-statement--pdf { --statement-bg: #fff; --statement-text: #1d2b40; --statement-muted: #5b6b7c; --statement-border: #d7e0e9; --statement-subtle: #f3f6fa; max-width: none; padding: 0; border: 0; border-radius: 0; background: #fff; color: #1d2b40; box-shadow: none; }
	.channel-statement--pdf .channel-statement-hero { display: block; padding: 16px; border-radius: 0; background: #16344a; }
	.channel-statement--pdf .channel-statement-hero:after { display: none; }
	.channel-statement--pdf .channel-statement-title { font-size: 20pt; }
	.channel-statement--pdf .channel-statement-description { color: #e0eceb; }
	.channel-statement--pdf .channel-statement-filter,
	.channel-statement--pdf .channel-statement-filter-note { display: none; }
	.channel-statement--pdf .channel-statement-summary { border-spacing: 5px 0; }
	.channel-statement--pdf .channel-statement-summary-card { padding: 8px; border: 1px solid #d7e0e9; border-top: 3px solid #16834a; background: #f3f6fa; }
	.channel-statement--pdf .channel-statement-summary td:nth-child(2) .channel-statement-summary-card { border-top-color: #3184cf; }
	.channel-statement--pdf .channel-statement-summary td:nth-child(3) .channel-statement-summary-card { border-top-color: #16834a; }
	.channel-statement--pdf .channel-statement-summary td:nth-child(4) .channel-statement-summary-card { border-top-color: #d65b63; }
	.channel-statement--pdf .channel-statement-summary td:nth-child(5) .channel-statement-summary-card { border-top-color: #7956bd; }
	.channel-statement--pdf .channel-statement-summary-label { color: #5b6b7c; font-size: 7pt; }
	.channel-statement--pdf .channel-statement-summary-value { color: #1d2b40; font-size: 9pt; }
	.channel-statement--pdf .channel-statement-table-wrap { overflow: visible; border: 1px solid #ccd6e0; }
	.channel-statement--pdf .channel-statement-table { color: #1d2b40; font-size: 8pt; }
	.channel-statement--pdf .channel-statement-table thead { display: table-header-group; }
	.channel-statement--pdf .channel-statement-table thead th { padding: 7px 5px; border-bottom: 1px solid #ccd6e0; background: #e9eff5; color: #46596c; }
	.channel-statement--pdf .channel-statement-table tbody tr { border-bottom: 1px solid #d7e0e9; }
	.channel-statement--pdf .channel-statement-table tbody tr:nth-child(even) { background: #f7f9fb; }
	.channel-statement--pdf .channel-statement-table tbody tr { page-break-inside: avoid; }
	.channel-statement--pdf .channel-statement-table td { padding: 6px 5px; border-bottom: 1px solid #d7e0e9; color: #1d2b40; }
	.channel-statement--pdf .statement-status { background: #f3f6fa; color: #1d2b40; }
	.channel-statement--pdf .statement-actor, .channel-statement--pdf .statement-drawer { color: #5b6b7c; }
	.channel-statement--pdf .statement-credit { color: #087443; }
	.channel-statement--pdf .statement-debit { color: #b8323d; }
	.channel-statement--pdf .channel-statement-footer { color: #5b6b7c; }
	@media (max-width: 900px) {
		.channel-statement-summary { border-spacing: .35rem 0; }
		.channel-statement-summary td { padding-bottom: .5rem; }
		.channel-statement-summary-card { padding: .65rem; }
		.channel-statement-summary-value { font-size: .88rem; }
	}
	@media (max-width: 700px) {
		.channel-statement-hero { display: block; }
		.channel-statement-actions { margin-top: 1rem; }
		.channel-statement-filter { align-items: stretch; }
		.channel-statement-filter .form-control { min-width: 0; width: 100%; }
		.channel-statement-summary, .channel-statement-summary tbody, .channel-statement-summary tr { display: block; }
		.channel-statement-summary td { display: inline-block; width: 49%; padding: 0 .2rem .45rem; }
		.channel-statement-table { min-width: 940px; }
		.channel-statement-footer { display: block; }
	}
	@media print {
		.channel-statement { max-width: none; padding: 0; border: 0; box-shadow: none; }
		.channel-statement-actions { display: none; }
	}
</style>

<section class="channel-statement<?= $isPdfExport ? ' channel-statement--pdf' : '' ?>">
	<header class="channel-statement-hero">
		<div class="channel-statement-heading">
			<div class="channel-statement-eyebrow">Cashdrawer · Account statement</div>
			<h2 class="channel-statement-title"><?= $escape($statementTitle) ?></h2>
			<div class="channel-statement-description">
				<?= !empty($statement['is_shared'])
					? 'Shared account · common statement for all eligible users'
					: 'Cashdrawer owner: ' . $escape($statement['owner_name'] ?? '') . ' · Includes previous cashdrawers' ?>
			</div>
		</div>
		<?php if (!$isPdfExport): ?>
			<nav class="channel-statement-actions" aria-label="Statement actions">
				<a class="btn btn-sm btn-light" href="<?= $escape($baseRoute . '/view_cashdrawer/' . (int)($statement['drawer_id'] ?? 0)) ?>">
					<i class="fa fa-arrow-left me-1" aria-hidden="true"></i> Back to drawer
				</a>
				<a class="btn btn-sm btn-danger" href="<?= $escape($baseRoute . '/export_channel_statement_pdf/' . (int)($statement['drawer_id'] ?? 0) . '?' . $statementQuery) ?>" target="_blank" rel="noopener">
					<i class="fa fa-file-pdf me-1" aria-hidden="true"></i> Export PDF
				</a>
			</nav>
		<?php endif; ?>
	</header>

	<?php if (!$isPdfExport): ?>
		<form class="channel-statement-filter" method="get" action="<?= $escape($statementPath) ?>">
			<input type="hidden" name="channel_type" value="<?= $escape($statementParams['channel_type']) ?>">
			<input type="hidden" name="channel_key" value="<?= $escape($statementParams['channel_key']) ?>">
			<div>
				<label for="statement-start-date">From date</label>
				<input class="form-control form-control-sm" id="statement-start-date" type="date" name="start_date" value="<?= $escape($dateFrom) ?>" max="<?= $escape($dateTo ?: '') ?>">
			</div>
			<div>
				<label for="statement-end-date">To date</label>
				<input class="form-control form-control-sm" id="statement-end-date" type="date" name="end_date" value="<?= $escape($dateTo) ?>" min="<?= $escape($dateFrom ?: '') ?>">
			</div>
			<button class="btn btn-sm btn-primary" type="submit"><i class="fa fa-filter me-1" aria-hidden="true"></i> Apply dates</button>
			<?php if ($hasDateFilter): ?>
				<a class="btn btn-sm btn-outline-secondary" href="<?= $escape($statementPath . '?' . http_build_query($statementParams)) ?>">Clear dates</a>
			<?php endif; ?>
		</form>
		<?php if ($invalidDateFilter): ?>
			<div class="alert alert-warning py-2" role="alert">An invalid date filter was ignored. Choose a valid date range and apply it again.</div>
		<?php endif; ?>
		<p class="channel-statement-filter-note">
			<?= $hasDateFilter
				? 'Showing ' . number_format(count($entries)) . ' of ' . number_format(count($allEntries)) . ' movements for the selected date range. The PDF export uses this same range.'
				: 'Use the date range to narrow the movement ledger. The PDF export uses the selected range.' ?>
		</p>
	<?php endif; ?>

	<table class="channel-statement-summary" aria-label="Statement summary">
		<tbody><tr>
			<td><div class="channel-statement-summary-card"><span class="channel-statement-summary-label">Channel</span><strong class="channel-statement-summary-value"><?= $escape($statement['channel_label'] ?? '') ?></strong></div></td>
			<td><div class="channel-statement-summary-card"><span class="channel-statement-summary-label">Opening snapshots</span><strong class="channel-statement-summary-value"><?= $currency ?> <?= $formatMoney($openingTotal) ?></strong></div></td>
			<td><div class="channel-statement-summary-card"><span class="channel-statement-summary-label">Total credits</span><strong class="channel-statement-summary-value"><?= $currency ?> <?= $formatMoney($creditTotal) ?></strong></div></td>
			<td><div class="channel-statement-summary-card"><span class="channel-statement-summary-label">Total debits</span><strong class="channel-statement-summary-value"><?= $currency ?> <?= $formatMoney($debitTotal) ?></strong></div></td>
			<td><div class="channel-statement-summary-card"><span class="channel-statement-summary-label">Latest recorded balance</span><strong class="channel-statement-summary-value"><?= $currency ?> <?= $formatMoney($closingTotal) ?></strong></div></td>
		</tr></tbody>
	</table>

	<div class="channel-statement-ledger-heading">
		<h3><i class="fa fa-list-ul me-1" aria-hidden="true"></i> Movement ledger</h3>
		<span class="channel-statement-count"><?= number_format(count($entries)) ?> record<?= count($entries) === 1 ? '' : 's' ?></span>
	</div>
	<div class="channel-statement-table-wrap">
		<table class="channel-statement-table">
			<thead>
				<tr>
					<th>Date &amp; drawer</th>
					<th>Reference / movement &amp; status</th>
					<?php if (($statement['channel_type'] ?? '') === 'cash'): ?><th>Denomination × count</th><?php endif; ?>
					<th class="statement-number">Opening</th>
					<th class="statement-number">Credit / in</th>
					<th class="statement-number">Debit / out</th>
					<th class="statement-number">Closing / amount</th>
				</tr>
			</thead>
			<tbody>
				<?php if (!$entries): ?>
					<tr><td class="channel-statement-empty" colspan="<?= ($statement['channel_type'] ?? '') === 'cash' ? 7 : 6 ?>"><i class="fa fa-folder-open" aria-hidden="true"></i>No movements have been recorded for this channel.</td></tr>
				<?php else: ?>
					<?php foreach ($entries as $entry): ?>
						<?php $movement = trim((string)($entry['description'] ?? '')); ?>
						<tr>
							<td class="statement-date">
								<strong><?= $escape($formatDate($entry['date'] ?? '')) ?></strong>
								<span class="statement-drawer">Drawer #<?= (int)($entry['drawer_id'] ?? 0) ?></span>
							</td>
							<td class="statement-reference">
								<?php if (($entry['reference'] ?? '') !== ''): ?><strong>TX #<?= $escape($entry['reference']) ?></strong><?php endif; ?>
								<span class="statement-movement"><?= $escape($movement) ?></span>
								<span class="statement-status"><?= $escape($entry['status'] ?? '') ?></span>
								<?php if (!empty($entry['actor'])): ?><span class="statement-actor">Recorded by <?= $escape($entry['actor']) ?></span><?php endif; ?>
							</td>
							<?php if (($statement['channel_type'] ?? '') === 'cash'): ?>
								<td><?= ($entry['denomination'] ?? '') !== '' ? $escape(number_format((float)$entry['denomination'], $currency === 'CFA' ? 0 : 2) . ' × ' . (int)($entry['count'] ?? 0)) : '—' ?></td>
							<?php endif; ?>
							<td class="statement-number"><?= (($statement['channel_type'] ?? '') === 'ecash' || $movement === 'Opening balance snapshot' || (float)($entry['opening'] ?? 0) != 0.0) ? $currency . ' ' . $formatMoney($entry['opening']) : '—' ?></td>
							<td class="statement-number statement-credit"><?= (float)($entry['credit'] ?? 0) != 0.0 ? '+' . $currency . ' ' . $formatMoney($entry['credit']) : '—' ?></td>
							<td class="statement-number statement-debit"><?= (float)($entry['debit'] ?? 0) != 0.0 ? '−' . $currency . ' ' . $formatMoney($entry['debit']) : '—' ?></td>
							<td class="statement-number"><?= ($entry['closing'] ?? null) !== null ? $currency . ' ' . $formatMoney($entry['closing']) : '—' ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
	<footer class="channel-statement-footer">
		<span>Generated <?= $escape($formatDate(date('Y-m-d H:i:s'))) ?></span>
		<span>Amounts reflect recorded channel ledger and denomination journal entries.</span>
	</footer>
</section>
