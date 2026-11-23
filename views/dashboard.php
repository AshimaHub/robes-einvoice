<?php
$ready = (int) ($counts['draft'] ?? 0);
$atNrs = (int) ($counts['validated'] ?? 0) + (int) ($counts['submitted'] ?? 0);
$confirmed = (int) ($counts['confirmed'] ?? 0);
$failed = (int) ($counts['failed'] ?? 0);
$open = array();
foreach ($checklist as $item) {
    if (empty($item['ok'])) {
        $open[] = $item;
    }
}
$isAdmin = (($currentUser['role'] ?? '') === 'admin');
$first = trim((string) ($currentUser['name'] ?? ''));
$first = $first === '' ? 'there' : preg_replace('/\s+.*/', '', $first);
$live = ($brand['env'] ?? '') === 'production';
?>
<section class="hero">
    <div>
        <p class="eyebrow"><?= $live ? 'Production' : 'Sandbox' ?></p>
        <h2>Hello, <?= e((string) $first) ?></h2>
        <p>Create an invoice, send it to NRS, and keep the signed receipt for your customer.</p>
    </div>
    <a class="btn primary" href="<?= e(robes_url('/invoices/new')) ?>">New invoice</a>
</section>
<div class="stats">
    <a class="stat" href="<?= e(robes_url('/invoices?bucket=draft')) ?>"><span>Drafts</span><strong><?= $ready ?></strong></a>
    <a class="stat" href="<?= e(robes_url('/invoices?bucket=nrs')) ?>"><span>With NRS</span><strong><?= $atNrs ?></strong></a>
    <a class="stat" href="<?= e(robes_url('/invoices?bucket=confirmed')) ?>"><span>Confirmed</span><strong><?= $confirmed ?></strong></a>
    <a class="stat warn" href="<?= e(robes_url('/invoices?bucket=failed')) ?>"><span>Need attention</span><strong><?= $failed ?></strong></a>
</div>
<?php if ($open && $isAdmin): ?>
<section class="card notice">
    <div>
        <h2>Finish connecting NRS</h2>
        <p class="muted">You can draft invoices now. Sending them waits on the items below.</p>
    </div>
    <ul class="checks">
        <?php foreach ($open as $item): ?>
        <li><a href="<?= e(robes_url($item['href'])) ?>"><?= e($item['label']) ?></a></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php elseif ($open): ?>
<section class="card notice">
    <h2>NRS is not ready to send yet</h2>
    <p class="muted">An administrator still needs to finish the company connection. You can prepare invoices in the meantime.</p>
</section>
<?php endif; ?>
<section class="card">
    <div class="row-between">
        <h2>Recent invoices</h2>
        <a href="<?= e(robes_url('/invoices')) ?>">View all</a>
    </div>
    <?php if (!$recent): ?>
    <div class="empty">
        <strong>No invoices yet</strong>
        <p>Your first invoice can be a draft. Send it to NRS when the connection is ready.</p>
        <a class="btn primary" href="<?= e(robes_url('/invoices/new')) ?>">Create an invoice</a>
    </div>
    <?php else: ?>
    <table class="grid">
        <thead><tr><th>No.</th><th>Buyer</th><th>Date</th><th class="num">Total</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $row): ?>
        <tr>
            <td><a href="<?= e(robes_url('/invoices/' . (int) $row['id'])) ?>"><?= e($row['invoice_number']) ?></a></td>
            <td><?= e($row['buyer_name']) ?></td>
            <td><?= e(robes_date((string) $row['issue_date'])) ?></td>
            <td class="num"><?= e(robes_money($row['total_amount'])) ?></td>
            <td><span class="pill <?= e(robes_pill((string) $row['status'])) ?>"><?= e(nrs_status_label((string) $row['status'])) ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>
