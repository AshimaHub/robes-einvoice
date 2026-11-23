<?php
$status = strtoupper((string) ($doc['status'] ?? ''));
$editable = robes_editable($doc);
$irn = trim((string) ($doc['irn'] ?? ''));
$canSend = $editable && !$blockers;
$canConfirm = $irn !== '' && in_array($status, array('SIGNED', 'SUBMITTED', 'TRANSMITTED', 'QUEUED', 'VALIDATED'), true) && !$blockers;
$canFile = $irn !== '' && in_array($status, array('SIGNED', 'SUBMITTED', 'TRANSMITTED', 'QUEUED', 'VALIDATED', 'CONFIRMED'), true) && !$blockers;
$isAdmin = (($currentUser['role'] ?? '') === 'admin');
$lines = isset($payload['line_items']) && is_array($payload['line_items']) ? $payload['line_items'] : array();
$amounts = isset($payload['amounts']) && is_array($payload['amounts']) ? $payload['amounts'] : array();
$dup = nrs_error_looks_like_duplicate_irn((string) ($doc['last_error'] ?? ''));
$raw = (string) ($doc['last_response_json'] ?? '');
if (strlen($raw) > 8000) {
    $raw = substr($raw, 0, 8000) . "\n…";
}
?>
<div class="row-between">
    <div>
        <span class="pill <?= e(robes_pill($status)) ?>"><?= e(nrs_status_label($status)) ?></span>
        <p class="mono irn-line"><?= e($irn !== '' ? $irn : 'IRN appears after the invoice number and service ID are set.') ?></p>
        <p class="muted"><?= e(robes_date((string) $doc['issue_date'])) ?><?php if ($creator !== ''): ?> · created by <?= e($creator) ?><?php endif; ?></p>
    </div>
    <p class="grand"><?= e(robes_money($doc['total_amount'])) ?></p>
</div>
<?php if (!empty($doc['last_error'])): ?>
<div class="flash danger"><?= e((string) $doc['last_error']) ?></div>
<?php endif; ?>
<?php if ($dup && $editable): ?>
<p class="muted">NRS has already seen this IRN. Issue a new invoice number, then validate again.</p>
<?php endif; ?>
<?php if ($blockers): ?>
<div class="flash warn"><?= e(implode(' ', $blockers)) ?></div>
<?php endif; ?>
<div class="row-actions wrap">
    <?php if ($editable): ?><a class="btn" href="<?= e(robes_url('/invoices/' . (int) $doc['id'] . '/edit')) ?>">Edit</a><?php endif; ?>
    <form method="post" target="_blank"><?= robes_csrf_field() ?><input type="hidden" name="action" value="preview"><button class="btn" type="submit">Preview</button></form>
    <?php if ($editable): ?>
    <form method="post" data-once data-confirm="Issue a new invoice number? The previous IRN should not be reused after NRS has seen it."><?= robes_csrf_field() ?><input type="hidden" name="action" value="retry_irn"><button class="btn" type="submit">New invoice number</button></form>
    <form method="post" data-once><?= robes_csrf_field() ?><input type="hidden" name="action" value="validate"><button class="btn" type="submit" <?= $canSend ? '' : 'disabled' ?>>Validate with NRS</button></form>
    <form method="post" data-once data-confirm="Sign and submit this invoice to NRS?"><?= robes_csrf_field() ?><input type="hidden" name="action" value="submit"><button class="btn primary" type="submit" <?= $canSend ? '' : 'disabled' ?>>Sign and submit</button></form>
    <?php endif; ?>
    <?php if ($canConfirm): ?>
    <form method="post" data-once><?= robes_csrf_field() ?><input type="hidden" name="action" value="confirm"><button class="btn" type="submit">Confirm</button></form>
    <?php endif; ?>
    <?php if ($canFile): ?>
    <form method="post" target="_blank" data-once><?= robes_csrf_field() ?><input type="hidden" name="action" value="receipt"><button class="btn" type="submit">Receipt</button></form>
    <form method="post" data-once><?= robes_csrf_field() ?><input type="hidden" name="action" value="download"><button class="btn" type="submit">Download signed file</button></form>
    <?php endif; ?>
</div>
<div class="split">
    <section class="card">
        <h2>Buyer</h2>
        <p><strong><?= e((string) $doc['buyer_name']) ?></strong></p>
        <p class="muted">TIN <?= e((string) ($doc['buyer_tin'] !== '' ? $doc['buyer_tin'] : '—')) ?></p>
        <p class="muted"><?= e((string) ($payload['buyer']['email'] ?? '')) ?></p>
        <p><?= e(trim(implode(', ', array_filter(array(
            (string) ($payload['buyer']['street'] ?? ''),
            (string) ($payload['buyer']['city'] ?? ''),
            (string) ($payload['buyer']['state'] ?? ''),
            (string) ($payload['buyer']['lga'] ?? ''),
        ))))) ?></p>
    </section>
    <section class="card">
        <h2>Supplier</h2>
        <p><strong><?= e((string) $doc['seller_name']) ?></strong></p>
        <p class="muted">TIN <?= e((string) $doc['seller_tin']) ?></p>
        <p class="muted"><?= e((string) ($payload['invoice_kind'] ?? '')) ?> · <?= e(str_replace('_', ' ', (string) ($payload['payment_status'] ?? ''))) ?></p>
        <?php if (!empty($doc['source_reference'])): ?><p class="muted">Reference <?= e((string) $doc['source_reference']) ?></p><?php endif; ?>
        <p><?= e((string) ($doc['description'] ?? '')) ?></p>
    </section>
</div>
<section class="card">
    <table class="grid">
        <thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $li): ?>
        <tr>
            <td><?= e((string) ($li['description'] ?? '')) ?></td>
            <td class="num"><?= e((string) ($li['quantity'] ?? '')) ?></td>
            <td class="num"><?= e(robes_money($li['input_unit_price'] ?? ($li['unit_price'] ?? 0))) ?></td>
            <td class="num"><?= e(robes_money($li['line_total'] ?? 0)) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="totals">
        <div><span>Subtotal</span><strong><?= e(robes_money($amounts['subtotal'] ?? $doc['amount'])) ?></strong></div>
        <div><span>VAT</span><strong><?= e(robes_money($amounts['vat_amount'] ?? $doc['vat_amount'])) ?></strong></div>
        <div class="due"><span>Payable</span><strong><?= e(robes_money($amounts['total'] ?? $doc['total_amount'])) ?></strong></div>
    </div>
</section>
<?php if ($attempts): ?>
<section class="card">
    <h2>NRS attempts</h2>
    <table class="grid">
        <thead><tr><th>When</th><th>Action</th><th>HTTP</th><th>Result</th><th>Detail</th></tr></thead>
        <tbody>
        <?php foreach ($attempts as $attempt): ?>
        <tr>
            <td><?= e((string) $attempt['created_at']) ?></td>
            <td><?= e((string) $attempt['action']) ?></td>
            <td><?= (int) $attempt['http_status'] ?></td>
            <td><?= (int) $attempt['success'] === 1 ? 'OK' : 'Failed' ?></td>
            <td><?= e((string) $attempt['error_message']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>
<?php if ($raw !== ''): ?>
<details class="card"><summary>Last NRS response</summary><pre class="raw"><?= e($raw) ?></pre></details>
<?php endif; ?>
<?php if ($isAdmin): ?>
<form method="post" data-confirm="Delete this invoice from Robes? This does not cancel it on NRS.">
    <?= robes_csrf_field() ?>
    <input type="hidden" name="action" value="delete">
    <button class="btn danger" type="submit">Delete from this workspace</button>
</form>
<?php endif; ?>
