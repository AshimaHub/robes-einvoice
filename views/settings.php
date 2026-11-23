<form id="settingsForm" method="post" enctype="multipart/form-data" class="stack" data-env="<?= e((string) ($settings['environment'] ?? 'sandbox')) ?>">
    <?= robes_csrf_field() ?>
    <section class="card" id="company">
        <h2>Company</h2>
        <p class="muted">This name, TIN, and address are printed on every invoice and receipt.</p>
        <div class="party" id="sellerParty">
            <label>Legal name<input name="seller_name" data-party="name" value="<?= e((string) $settings['seller_name']) ?>"></label>
            <div class="grid-2">
                <label>TIN<input name="seller_tin" data-party="tin" value="<?= e((string) $settings['seller_tin']) ?>"></label>
                <label>Email<input type="email" name="seller_email" data-party="email" value="<?= e((string) $settings['seller_email']) ?>"></label>
            </div>
            <button class="btn" type="button" data-lookup="#sellerParty">Look up TIN</button>
            <p class="lookup-note muted"></p>
            <div class="grid-2">
                <label>Phone<input name="seller_phone" value="<?= e((string) $settings['seller_phone']) ?>"><span class="muted">Stored here. Not sent on the invoice.</span></label>
                <label>RC number<input name="company_rc" value="<?= e((string) $settings['company_rc']) ?>"></label>
            </div>
            <label>Business description<input name="seller_desc" value="<?= e((string) $settings['seller_desc']) ?>"></label>
            <label>Street<input name="seller_street" data-party="street" value="<?= e((string) $settings['seller_street']) ?>"></label>
            <div class="grid-2">
                <label>City<input name="seller_city" data-party="city" value="<?= e((string) $settings['seller_city']) ?>"></label>
                <label>Postal<input name="seller_postal" data-party="postal" value="<?= e((string) $settings['seller_postal']) ?>"></label>
            </div>
            <div class="grid-2">
                <label>State code<input name="seller_state" data-party="state" list="ng-states" placeholder="NG-KN" value="<?= e((string) $settings['seller_state']) ?>"></label>
                <label>LGA code<input name="seller_lga" data-party="lga" placeholder="NG-KN-TAR" value="<?= e((string) $settings['seller_lga']) ?>"></label>
            </div>
        </div>
    </section>

    <section class="card" id="brand">
        <h2>Appearance</h2>
        <p class="muted">The name, colours, and logo your team sees after they sign in.</p>
        <div class="grid-2">
            <label>App name<input name="brand_name" value="<?= e((string) $settings['brand_name']) ?>"></label>
            <label>Tagline<input name="brand_tagline" value="<?= e((string) $settings['brand_tagline']) ?>"></label>
        </div>
        <div class="grid-2">
            <label>Colour<input type="color" name="brand_color" value="<?= e((string) $settings['brand_color']) ?>"></label>
            <label>Accent<input type="color" name="brand_accent" value="<?= e((string) $settings['brand_accent']) ?>"></label>
        </div>
        <label>Invoice prefix<input name="invoice_prefix" maxlength="8" value="<?= e((string) $settings['invoice_prefix']) ?>"></label>
        <label>Logo<input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/gif">
            <?php if ($brand['logo'] !== ''): ?><img class="logo-preview" src="<?= e($brand['logo']) ?>" alt="Current logo"><?php endif; ?>
        </label>
    </section>

    <section class="card" id="defaults">
        <h2>Invoice defaults</h2>
        <div class="grid-2">
            <label>VAT %<input name="vat_rate" value="<?= e((string) $settings['vat_rate']) ?>"></label>
            <label>Kind
                <select name="invoice_kind">
                    <?php foreach (array('B2B', 'B2C', 'B2G', 'G2B') as $opt): ?>
                    <option value="<?= e($opt) ?>" <?= ($settings['invoice_kind'] ?? '') === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <div class="grid-2">
            <label>ISIC code<input name="default_isic" value="<?= e((string) $settings['default_isic']) ?>" placeholder="4210"></label>
            <label>Service category<input name="default_service_category" value="<?= e((string) $settings['default_service_category']) ?>"></label>
        </div>
        <label>Invoice type code<input name="invoice_type_code" value="<?= e((string) $settings['invoice_type_code']) ?>"></label>
        <p class="muted">ISIC and service category follow this company’s trade. NRS rejects a blank code. 381 is the usual invoice type code.</p>
    </section>

    <section class="card" id="nrs">
        <h2>NRS connection</h2>
        <label class="check"><input type="checkbox" name="enabled" value="1" <?= ($settings['enabled'] ?? '') === '1' ? 'checked' : '' ?>> Allow validate, sign, confirm, and receipt calls</label>
        <p class="muted">The environment you chose is the one invoices use. Keep the other profile for when you move between practice and live. A host copied from NRS My Account can be just <span class="mono">https://einvoice1.nrs.gov.ng</span> — Robes adds <span class="mono">/api/v1</span>.</p>
        <?php
        $envOrder = array(($settings['environment'] ?? '') === 'production' ? 'production' : 'sandbox');
        $envOrder[] = $envOrder[0] === 'sandbox' ? 'production' : 'sandbox';
        $envPrefixes = array('sandbox' => 'sbx', 'production' => 'prd');
        foreach ($envOrder as $env):
            $prefix = $envPrefixes[$env];
            $profile = $profiles[$env];
            $connected = trim((string) ($profile['api_key'] ?? '')) !== '' && trim((string) ($profile['business_id'] ?? '')) !== '' && trim((string) ($profile['service_id'] ?? '')) !== '';
            $qrReady = trim((string) ($profile['crypto_public_key'] ?? '')) !== '' && trim((string) ($profile['crypto_certificate'] ?? '')) !== '';
        ?>
        <fieldset class="profile">
            <legend><?= $env === 'production' ? 'Production' : 'Sandbox' ?></legend>
            <label class="check"><input type="radio" name="environment" value="<?= e($env) ?>" <?= ($settings['environment'] ?? '') === $env ? 'checked' : '' ?>> Use this mode</label>
            <p class="muted"><?= $connected ? 'Connection details are saved.' : 'Connection details are incomplete.' ?> <?= $qrReady ? 'QR keys are saved.' : 'QR keys are missing.' ?></p>
            <label>NRS address<input name="<?= e($prefix) ?>_base_url" value="<?= e((string) ($profile['base_url'] ?? '')) ?>"></label>
            <label>API key<input id="<?= e($prefix) ?>_api_key" type="password" name="<?= e($prefix) ?>_api_key" autocomplete="off" placeholder="<?= e(trim((string) ($profile['api_key'] ?? '')) !== '' ? 'Saved ' . nrs_mask_credential((string) $profile['api_key']) : 'Paste the API key') ?>"></label>
            <label>API secret<input id="<?= e($prefix) ?>_api_secret" type="password" name="<?= e($prefix) ?>_api_secret" autocomplete="off" placeholder="<?= e(trim((string) ($profile['api_secret'] ?? '')) !== '' ? 'Saved ' . nrs_mask_credential((string) $profile['api_secret']) : 'Paste the API secret') ?>"></label>
            <div class="row-actions">
                <button class="btn tiny" type="button" data-toggle-secret="<?= e($prefix) ?>_api_key">Show key</button>
                <button class="btn tiny" type="button" data-toggle-secret="<?= e($prefix) ?>_api_secret">Show secret</button>
            </div>
            <div class="grid-2">
                <label>Business ID<input name="<?= e($prefix) ?>_business_id" value="<?= e((string) ($profile['business_id'] ?? '')) ?>"></label>
                <label>Service ID<input name="<?= e($prefix) ?>_service_id" value="<?= e((string) ($profile['service_id'] ?? '')) ?>" placeholder="8 characters"></label>
            </div>
            <label>Paste crypto_keys.txt<textarea name="<?= e($prefix) ?>_crypto_paste" rows="4" placeholder="From NRS: Approved Businesses, your business, Get Cryptographic Keys"></textarea></label>
            <label class="check"><input type="checkbox" name="<?= e($prefix) ?>_clear_crypto" value="1"> Remove saved QR keys for this mode</label>
        </fieldset>
        <?php endforeach; ?>
        <div class="row-actions">
            <button class="btn primary" type="submit" name="intent" value="save">Save settings</button>
            <button class="btn" type="submit" name="intent" value="test">Save and test connection</button>
        </div>
    </section>
</form>
<details class="card">
    <summary>Technical status</summary>
    <ul class="runtime">
        <li>PHP <?= e($runtime['php']) ?></li>
        <li>cURL <?= $runtime['curl'] ? 'ready' : 'missing' ?></li>
        <li>OpenSSL <?= $runtime['openssl'] ? 'ready' : 'missing' ?></li>
        <li>GD <?= $runtime['gd'] ? 'ready' : 'missing, QR will use SVG' ?></li>
        <li>SQLite <?= $runtime['sqlite'] ? 'ready' : 'missing' ?></li>
    </ul>
</details>
