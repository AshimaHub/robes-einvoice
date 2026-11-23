<div class="row-between">
    <p class="lede">Buyers you invoice more than once.</p>
    <a class="btn primary" href="<?= e(robes_url('/customers/new')) ?>">New customer</a>
</div>
<div class="card">
    <?php if (!$rows): ?>
    <p class="muted">No customers yet. They are also saved when you tick “Save this buyer” on an invoice.</p>
    <?php else: ?>
    <table class="grid">
        <thead><tr><th>Name</th><th>TIN</th><th>Email</th><th>City</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
        <tr>
            <td><a href="<?= e(robes_url('/customers/' . (int) $row['id'])) ?>"><?= e($row['name']) ?></a></td>
            <td><?= e($row['tin']) ?></td>
            <td><?= e($row['email']) ?></td>
            <td><?= e($row['city']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
