<?php
$envLabel = $choice === 'production' ? 'Production' : 'Sandbox';
$prefix = $choice === 'production' ? 'prd' : 'sbx';
$defaultUrl = $choice === '' ? '' : nrs_default_base_url_for_env('nrs_direct', $choice);
$baseUrl = trim((string) ($profile['base_url'] ?? ''));
if ($baseUrl === '') {
    $baseUrl = $defaultUrl;
}
$company = (string) ($settings['seller_name'] ?? '');
?>
<ol class="steps">
    <li class="done">Account</li>
    <li class="<?= $step === 'choose' ? 'now' : 'done' ?>">Environment</li>
    <li class="<?= $step === 'connect' ? 'now' : '' ?>">Connect</li>
</ol>
<?php if ($step === 'choose'): ?>
<h2>Where should invoices be sent?</h2>
<p class="lede">Pick the NRS environment for <?= e($company !== '' ? $company : 'this company') ?>. You can add the other one later in Settings.</p>
<div class="choice-grid">
    <form method="post">
        <?= robes_csrf_field() ?>
        <input type="hidden" name="action" value="choose">
        <input type="hidden" name="environment" value="sandbox">
        <button class="choice" type="submit">
            <span class="choice-kicker">Practice</span>
            <strong>Sandbox</strong>
            <span>Use the NRS test service while you confirm keys, sample invoices, and the receipt. Nothing signed here is a live tax invoice.</span>
        </button>
    </form>
    <form method="post" data-confirm="Production signs real invoices on the live NRS service. Continue?">
        <?= robes_csrf_field() ?>
        <input type="hidden" name="action" value="choose">
        <input type="hidden" name="environment" value="production">
        <button class="choice live" type="submit">
            <span class="choice-kicker">Live</span>
            <strong>Production</strong>
            <span>Send invoices to the live NRS service. Signed invoices are real and can be verified by your customers.</span>
        </button>
    </form>
</div>
<?php else: ?>
<div class="row-between">
    <div>
        <h2>Set up <?= e($envLabel) ?></h2>
        <p class="lede">These details belong to <?= e($company !== '' ? $company : 'your company') ?>. Colours and the app name can be changed any time after you sign in.</p>
    </div>
    <form method="post">
        <?= robes_csrf_field() ?>
        <input type="hidden" name="action" value="reset">
        <button class="btn" type="submit">Choose again</button>
    </form>
</div>
<form method="post" class="stack">
    <?= robes_csrf_field() ?>
    <input type="hidden" name="action" value="finish">
    <section class="card">
        <h2>Company</h2>
        <label>Legal name<input name="seller_name" required value="<?= e($company) ?>"></label>
        <div class="grid-2">
            <label>TIN<input name="seller_tin" value="<?= e((string) ($settings['seller_tin'] ?? '')) ?>"></label>
            <label>Email<input type="email" name="seller_email" value="<?= e((string) ($settings['seller_email'] ?? '')) ?>"></label>
        </div>
        <div class="grid-2">
            <label>Phone<input name="seller_phone" value="<?= e((string) ($settings['seller_phone'] ?? '')) ?>"></label>
            <label>RC number<input name="company_rc" value="<?= e((string) ($settings['company_rc'] ?? '')) ?>"></label>
        </div>
        <label>Street<input name="seller_street" value="<?= e((string) ($settings['seller_street'] ?? '')) ?>"></label>
        <div class="grid-2">
            <label>City<input name="seller_city" value="<?= e((string) ($settings['seller_city'] ?? '')) ?>"></label>
            <label>State code<input name="seller_state" list="ng-states" placeholder="NG-LA" value="<?= e((string) ($settings['seller_state'] ?? '')) ?>"></label>
        </div>
        <label>LGA code<input name="seller_lga" placeholder="NG-LA-IKE" value="<?= e((string) ($settings['seller_lga'] ?? '')) ?>"></label>
    </section>
    <section class="card">
        <h2><?= e($envLabel) ?> connection</h2>
        <p class="muted">Paste the keys NRS gave this company. Leave a key blank to keep one that is already saved. A host copied from NRS My Account can be the site address alone. Robes adds <span class="mono">/api/v1</span>.</p>
        <label>NRS address<input name="<?= e($prefix) ?>_base_url" value="<?= e($baseUrl) ?>"></label>
        <div class="grid-2">
            <label>API key<input type="password" name="<?= e($prefix) ?>_api_key" autocomplete="off" placeholder="<?= e(trim((string) ($profile['api_key'] ?? '')) !== '' ? 'Saved ' . nrs_mask_credential((string) $profile['api_key']) : '') ?>"></label>
            <label>API secret<input type="password" name="<?= e($prefix) ?>_api_secret" autocomplete="off" placeholder="<?= e(trim((string) ($profile['api_secret'] ?? '')) !== '' ? 'Saved ' . nrs_mask_credential((string) $profile['api_secret']) : '') ?>"></label>
        </div>
        <div class="grid-2">
            <label>Business ID<input name="<?= e($prefix) ?>_business_id" value="<?= e((string) ($profile['business_id'] ?? '')) ?>"></label>
            <label>Service ID<input name="<?= e($prefix) ?>_service_id" value="<?= e((string) ($profile['service_id'] ?? '')) ?>" placeholder="8 characters"></label>
        </div>
        <label>Cryptographic keys<textarea name="<?= e($prefix) ?>_crypto_paste" rows="4" placeholder="Paste crypto_keys.txt so receipts can show the NRS QR"></textarea></label>
    </section>
    <section class="card">
        <h2>Make it yours</h2>
        <p class="muted">This is what your team sees after they sign in. A logo can be added in Settings.</p>
        <div class="grid-2">
            <label>App name<input name="brand_name" value="<?= e((string) ($settings['brand_name'] ?? 'Robes eInvoice')) ?>"></label>
            <label>Invoice prefix<input name="invoice_prefix" maxlength="8" value="<?= e((string) ($settings['invoice_prefix'] ?? 'RB')) ?>"></label>
        </div>
        <div class="grid-2">
            <label>Colour<input type="color" name="brand_color" value="<?= e((string) ($settings['brand_color'] ?? '#1d4ed8')) ?>"></label>
            <label>Accent<input type="color" name="brand_accent" value="<?= e((string) ($settings['brand_accent'] ?? '#0f766e')) ?>"></label>
        </div>
        <div class="grid-2">
            <label>VAT %<input name="vat_rate" value="<?= e((string) ($settings['vat_rate'] ?? '7.5')) ?>"></label>
            <label>ISIC code<input name="default_isic" value="<?= e((string) ($settings['default_isic'] ?? '')) ?>"></label>
        </div>
        <label>Service category<input name="default_service_category" value="<?= e((string) ($settings['default_service_category'] ?? '')) ?>"></label>
    </section>
    <div class="row-actions">
        <button class="btn primary" type="submit">Save and open the app</button>
    </div>
</form>
<form method="post">
    <?= robes_csrf_field() ?>
    <input type="hidden" name="action" value="skip">
    <button class="btn linkish" type="submit">Skip the keys for now</button>
</form>
<datalist id="ng-states">
    <?php foreach (robes_state_codes() as $code => $label): ?>
    <option value="<?= e($code) ?>"><?= e($label) ?></option>
    <?php endforeach; ?>
</datalist>
<?php endif; ?>
