<?php

while (ob_get_level() > 0) {
	ob_end_clean();
}

$channelStatementPdf = true;
$admin_route = 'admin';
ob_start();
require plugin_path('views/admin/cashdrawers/channel_statement.php');
$html = ob_get_clean();
$html = preg_replace('~<style\b[^>]*>.*?</style>~is', '', $html);
if ($html === null) {
	throw new \RuntimeException('Unable to prepare the channel statement for PDF export.');
}
$pdfStyles = '
	@page { size: A4 landscape; margin: 10mm; }
	body { margin: 0; color: #1d2b40; font-family: "DejaVu Sans", sans-serif; font-size: 8pt; }
	.channel-statement { width: 100%; color: #1d2b40; }
	.channel-statement-hero { padding: 14px; background: #16344a; color: #fff; }
	.channel-statement-eyebrow { margin-bottom: 4px; color: #b7d8d0; font-size: 7pt; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; }
	.channel-statement-title { margin: 0; color: #fff; font-size: 19pt; }
	.channel-statement-description { margin-top: 5px; color: #e0eceb; font-size: 8pt; }
	.channel-statement-actions { display: none; }
	.channel-statement-summary { width: 100%; margin: 10px 0; border-collapse: separate; border-spacing: 5px 0; table-layout: fixed; }
	.channel-statement-summary td { width: 20%; padding: 0; vertical-align: top; }
	.channel-statement-summary-card { min-height: 48px; padding: 8px; border: 1px solid #d7e0e9; border-top: 3px solid #16834a; background: #f3f6fa; }
	.channel-statement-summary-label { display: block; margin-bottom: 4px; color: #5b6b7c; font-size: 6.5pt; font-weight: bold; text-transform: uppercase; }
	.channel-statement-summary-value { color: #1d2b40; font-size: 8pt; font-weight: bold; }
	.channel-statement-ledger-heading { margin: 10px 0 5px; }
	.channel-statement-ledger-heading h3 { margin: 0; color: #1d2b40; font-size: 10pt; }
	.channel-statement-count { color: #5b6b7c; font-size: 7pt; }
	.channel-statement-table-wrap { width: 100%; }
	.channel-statement-table { width: 100%; border-collapse: collapse; table-layout: fixed; color: #1d2b40; font-size: 6.5pt; }
	.channel-statement-table thead { display: table-header-group; }
	.channel-statement-table th { padding: 6px 4px; border: 1px solid #ccd6e0; background: #e9eff5; color: #46596c; font-size: 6pt; text-align: left; text-transform: uppercase; }
	.channel-statement-table td { padding: 5px 4px; border: 1px solid #d7e0e9; color: #1d2b40; vertical-align: top; overflow-wrap: anywhere; }
	.channel-statement-table tbody tr:nth-child(even) { background: #f7f9fb; }
	.channel-statement-table tbody tr { page-break-inside: avoid; }
	.channel-statement-table .statement-number { text-align: right; }
	.channel-statement-table .statement-credit { color: #087443; font-weight: bold; }
	.channel-statement-table .statement-debit { color: #b8323d; font-weight: bold; }
	.channel-statement-table .statement-date, .channel-statement-table .statement-reference { white-space: normal; }
	.channel-statement-table .statement-date strong, .channel-statement-table .statement-date .statement-drawer,
	.channel-statement-table .statement-reference .statement-movement, .channel-statement-table .statement-reference .statement-status,
	.channel-statement-table .statement-reference .statement-actor { display: block; }
	.channel-statement-table .statement-reference .statement-status { margin-top: 3px; }
	.channel-statement-table .statement-drawer, .channel-statement-table .statement-actor { color: #5b6b7c; }
	.channel-statement-table .statement-status { color: #1d2b40; font-weight: bold; }
	.channel-statement-footer { margin-top: 8px; color: #5b6b7c; font-size: 6.5pt; }
';
$html = '<!doctype html><html><head><meta charset="UTF-8"><style>' . $pdfStyles . '</style></head><body>' . $html . '</body></html>';

try {
	$autoload = ROOTPATH . 'vendor/autoload.php';
	if (!is_file($autoload)) {
		throw new \RuntimeException('PDF library autoloader is unavailable.');
	}
	require_once $autoload;
	if (!class_exists(\Dompdf\Dompdf::class)) {
		throw new \RuntimeException('PDF generator is unavailable.');
	}

	$options = new \Dompdf\Options();
	$options->setDefaultFont('DejaVu Sans');
	$options->setIsRemoteEnabled(false);
	$pdf = new \Dompdf\Dompdf($options);
	$pdf->loadHtml($html, 'UTF-8');
	$pdf->setPaper('A4', 'landscape');
	$pdf->render();
	$pdf->stream(
		'cashdrawer-' . strtolower(preg_replace('/[^A-Z0-9-]+/i', '-', $channelStatement['channel_key'])) . '-statement.pdf',
		['Attachment' => true]
	);
} catch (\Throwable $e) {
	error_log('[CashdrawerChannelStatement] PDF export failed: ' . $e->getMessage());
	while (ob_get_level() > 0) {
		ob_end_clean();
	}
	http_response_code(500);
	header('Content-Type: text/plain; charset=utf-8');
	echo 'Unable to generate the channel statement PDF.';
}
