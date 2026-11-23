<?php $isAdmin = (($currentUser['role'] ?? '') === 'admin'); ?>
<div class="row-between">
    <p class="lede">Drafts stay in this workspace until you validate and sign them with NRS.</p>
    <a class="btn primary" href="<?= e(robes_url('/invoices/new')) ?>">New invoice</a>
</div>
<form class="filters" method="get" action="<?= e(robes_url('/invoices')) ?>">
    <input name="q" value="<?= e($q) ?>" placeholder="Search number, buyer, IRN">
    <input type="hidden" name="bucket" value="<?= e($bucket) ?>">
    <button class="btn" type="submit">Search</button>
    <a href="<?= e(robes_url('/invoices')) ?>">Clear</a>
</form>
<div class="filters links">
    <a class="<?= $bucket === '' && $status === '' ? 'on' : '' ?>" href="<?= e(robes_url('/invoices')) ?>">All <?= (int) ($counts['total'] ?? 0) ?></a>
    <a class="<?= $bucket === 'draft' ? 'on' : '' ?>" href="<?= e(robes_url('/invoices?bucket=draft')) ?>">Draft / ready</a>
    <a class="<?= $bucket === 'nrs' ? 'on' : '' ?>" href="<?= e(robes_url('/invoices?bucket=nrs')) ?>">With NRS</a>
    <a class="<?= $bucket === 'confirmed' ? 'on' : '' ?>" href="<?= e(robes_url('/invoices?bucket=confirmed')) ?>">Confirmed</a>
    <a class="<?= $bucket === 'failed' ? 'on' : '' ?>" href="<?= e(robes_url('/invoices?bucket=failed')) ?>">Failed</a>
</div>
<div class="card">
    <?php if (!$rows): ?>
    <p class="muted">No invoices in this view.</p>
    <?php else: ?>
    <table class="grid">
        <thead>
            <tr><th>No.</th><th>Buyer</th><th>Reference</th><th>Date</th><th class="num">Total</th><th>Status</th><th>IRN</th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
        <tr>
            <td><a href="<?= e(robes_url('/invoices/' . (int) $row['id'])) ?>"><?= e($row['invoice_number']) ?></a></td>
            <td><?= e($row['buyer_name']) ?></td>
            <td><?= e($row['source_reference']) ?></td>
            <td><?= e(robes_date((string) $row['issue_date'])) ?></td>
            <td class="num"><?= e(robes_money($row['total_amount'])) ?></td>
            <td><span class="pill <?= e(robes_pill((string) $row['status'])) ?>"><?= e(nrs_status_label((string) $row['status'])) ?></span></td>
            <td class="mono"><?= e((string) ($row['irn'] ?? '')) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<details class="card">
    <summary>Import a CSV</summary>
    <p class="muted">Use this when the company has invoices in a spreadsheet. One row becomes one invoice. <a href="<?= e(robes_url('/samples/invoices-template.csv')) ?>">Download the column template</a>.</p>
    <form method="post" action="<?= e(robes_url('/invoices/import')) ?>" enctype="multipart/form-data" class="row-actions">
        <?= robes_csrf_field() ?>
        <input type="file" name="csv" accept=".csv,text/csv" required>
        <button class="btn" type="submit">Import</button>
    </form>
</details>
