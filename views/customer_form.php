<?php $id = (int) ($customer['id'] ?? 0); ?>
<form method="post" class="card stack party" id="buyerParty" action="<?= e($id ? robes_url('/customers/' . $id) : robes_url('/customers/new')) ?>">
    <?= robes_csrf_field() ?>
    <label>Name<input name="name" data-party="name" required value="<?= e((string) ($customer['name'] ?? '')) ?>"></label>
    <div class="grid-2">
        <label>TIN<input name="tin" data-party="tin" value="<?= e((string) ($customer['tin'] ?? '')) ?>"></label>
        <label>Email<input type="email" name="email" data-party="email" value="<?= e((string) ($customer['email'] ?? '')) ?>"></label>
    </div>
    <button class="btn" type="button" data-lookup="#buyerParty">Look up TIN</button>
    <p class="lookup-note muted"></p>
    <label>Phone<input name="phone" value="<?= e((string) ($customer['phone'] ?? '')) ?>"><span class="muted">Kept here only. Phone is not sent on the NRS invoice.</span></label>
    <label>Street<input name="street" data-party="street" value="<?= e((string) ($customer['street'] ?? '')) ?>"></label>
    <div class="grid-2">
        <label>City<input name="city" data-party="city" value="<?= e((string) ($customer['city'] ?? '')) ?>"></label>
        <label>Postal<input name="postal" data-party="postal" value="<?= e((string) ($customer['postal'] ?? '')) ?>"></label>
    </div>
    <div class="grid-2">
        <label>State code<input name="state" data-party="state" list="ng-states" placeholder="NG-LA" value="<?= e((string) ($customer['state'] ?? '')) ?>"></label>
        <label>LGA code<input name="lga" data-party="lga" placeholder="NG-LA-IKE" value="<?= e((string) ($customer['lga'] ?? '')) ?>"></label>
    </div>
    <label>Notes<textarea name="notes" rows="3"><?= e((string) ($customer['notes'] ?? '')) ?></textarea></label>
    <div class="row-actions">
        <button class="btn primary" type="submit">Save customer</button>
        <a class="btn" href="<?= e(robes_url('/customers')) ?>">Back</a>
    </div>
</form>
<?php if ($id): ?>
<form method="post" data-confirm="Remove this customer?">
    <?= robes_csrf_field() ?>
    <input type="hidden" name="action" value="delete">
    <button class="btn danger" type="submit">Remove customer</button>
</form>
<?php endif; ?>
