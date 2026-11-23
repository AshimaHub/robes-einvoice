<?php
$buyer = isset($payload['buyer']) && is_array($payload['buyer']) ? $payload['buyer'] : array();
$amounts = isset($payload['amounts']) && is_array($payload['amounts']) ? $payload['amounts'] : array();
$lines = isset($payload['line_items']) && is_array($payload['line_items']) && $payload['line_items'] ? $payload['line_items'] : array(array());
$kind = (string) ($payload['invoice_kind'] ?? ($settings['invoice_kind'] ?? 'B2B'));
$pay = (string) ($payload['payment_status'] ?? 'UNPAID');
$vat = (string) ($amounts['vat_rate'] ?? ($settings['vat_rate'] ?? '7.5'));
$inclusive = !empty($amounts['vat_inclusive_source']);
$customerId = (int) ($payload['robes']['customer_id'] ?? 0);
$reference = (string) ($payload['robes']['reference'] ?? ($doc['source_reference'] ?? ''));
$issue = (string) ($payload['issue_date'] ?? ($doc['issue_date'] ?? date('Y-m-d')));
$number = (string) ($payload['invoice_number'] ?? ($doc['invoice_number'] ?? ''));
$note = (string) ($payload['description'] ?? '');
$action = $doc ? robes_url('/invoices/' . (int) $doc['id'] . '/edit') : robes_url('/invoices/new');
$isAdmin = (($currentUser['role'] ?? '') === 'admin');
?>
<p class="lede">The supplier is <?= e($settings['seller_name'] !== '' ? $settings['seller_name'] : 'the company in Settings') ?>.
<?php if ($isAdmin): ?><a href="<?= e(robes_url('/settings#company')) ?>">Edit company profile</a><?php endif; ?></p>
<form id="invoiceForm" method="post" action="<?= e($action) ?>" class="stack"
      data-service-id="<?= e((string) ($settings['service_id'] ?? '')) ?>"
      data-default-isic="<?= e((string) ($settings['default_isic'] ?? '')) ?>"
      data-default-cat="<?= e((string) ($settings['default_service_category'] ?? '')) ?>">
    <?= robes_csrf_field() ?>
    <input type="hidden" name="customer_id" id="customerId" value="<?= $customerId ?>">
    <div class="split">
        <section class="card party" id="buyerParty">
            <h2>Buyer</h2>
            <label>Saved customer
                <select id="customerPick">
                    <option value="">Type the buyer, or pick one</option>
                    <?php foreach ($customers as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $customerId ? 'selected' : '' ?> data-customer="<?= e(json_encode($c, JSON_UNESCAPED_UNICODE)) ?>"><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Name<input name="buyer_name" data-party="name" required value="<?= e((string) ($buyer['name'] ?? '')) ?>"></label>
            <div class="grid-2">
                <label>TIN<input name="buyer_tin" data-party="tin" value="<?= e((string) ($buyer['tin'] ?? '')) ?>"></label>
                <label>Email<input type="email" name="buyer_email" data-party="email" value="<?= e((string) ($buyer['email'] ?? '')) ?>"></label>
            </div>
            <button class="btn" type="button" data-lookup="#buyerParty">Look up TIN</button>
            <p class="lookup-note muted"></p>
            <label>Street<input name="buyer_street" data-party="street" value="<?= e((string) ($buyer['street'] ?? '')) ?>"></label>
            <div class="grid-2">
                <label>City<input name="buyer_city" data-party="city" value="<?= e((string) ($buyer['city'] ?? '')) ?>"></label>
                <label>Postal<input name="buyer_postal" data-party="postal" value="<?= e((string) ($buyer['postal'] ?? '')) ?>"></label>
            </div>
            <div class="grid-2">
                <label>State code<input name="buyer_state" data-party="state" list="ng-states" placeholder="NG-LA" value="<?= e((string) ($buyer['state'] ?? '')) ?>"></label>
                <label>LGA code<input name="buyer_lga" data-party="lga" placeholder="NG-LA-IKE" value="<?= e((string) ($buyer['lga'] ?? '')) ?>"></label>
            </div>
            <label class="check"><input type="checkbox" name="save_customer" value="1" checked> Save this buyer in Customers</label>
        </section>
        <section class="card">
            <h2>Invoice</h2>
            <label>Invoice number<input name="invoice_number" value="<?= e($number) ?>" placeholder="Letters and numbers. Leave blank to generate one."></label>
            <p class="muted">IRN preview <span class="mono" id="irnPreview"></span></p>
            <div class="grid-2">
                <label>Issue date<input type="date" name="issue_date" required value="<?= e($issue) ?>"></label>
                <label>Your reference<input name="reference" value="<?= e($reference) ?>"></label>
            </div>
            <div class="grid-2">
                <label>Kind
                    <select name="invoice_kind">
                        <?php foreach (array('B2B', 'B2C', 'B2G', 'G2B') as $opt): ?>
                        <option value="<?= e($opt) ?>" <?= $kind === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Payment
                    <select name="payment_status">
                        <?php foreach (array('UNPAID' => 'Unpaid', 'PAID' => 'Paid', 'PARTIALLY_PAID' => 'Partially paid') as $val => $label): ?>
                        <option value="<?= e($val) ?>" <?= $pay === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div class="grid-2">
                <label>VAT %<input id="vatRate" name="vat_rate" inputmode="decimal" value="<?= e($vat) ?>"></label>
                <label class="check tall"><input type="checkbox" id="vatInclusive" name="vat_inclusive" value="1" <?= $inclusive ? 'checked' : '' ?>> Prices already include VAT</label>
            </div>
            <label>Note<input name="description" value="<?= e($note) ?>"></label>
        </section>
    </div>
    <section class="card">
        <div class="row-between">
            <h2>Lines</h2>
            <button class="btn" type="button" id="addLine">Add line</button>
        </div>
        <div id="lines">
            <?php foreach ($lines as $li):
                $typed = $li['input_unit_price'] ?? ($li['unit_price'] ?? '');
                $isic = (string) ($li['isic_code'] ?? ($settings['default_isic'] ?? ''));
                $cat = (string) ($li['service_category'] ?? ($settings['default_service_category'] ?? ''));
            ?>
            <div class="line">
                <label>Description<input class="desc" name="line_desc[]" value="<?= e((string) ($li['description'] ?? '')) ?>"></label>
                <label>Qty<input class="qty" name="line_qty[]" inputmode="decimal" value="<?= e((string) ($li['quantity'] ?? '1')) ?>"></label>
                <label>Price<input class="price" name="line_price[]" inputmode="decimal" value="<?= e((string) $typed) ?>"></label>
                <label>Amount<span class="line-total num">₦0.00</span></label>
                <button class="btn tiny" type="button" data-remove-line>Remove</button>
                <label>ISIC<input name="line_isic[]" value="<?= e($isic) ?>"></label>
                <label class="grow">Service category<input name="line_cat[]" value="<?= e($cat) ?>"></label>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="totals">
            <div><span>Subtotal</span><strong id="netTotal">₦0.00</strong></div>
            <div><span>VAT</span><strong id="vatTotal">₦0.00</strong></div>
            <div class="due"><span>Payable</span><strong id="grandTotal">₦0.00</strong></div>
        </div>
    </section>
    <div class="row-actions">
        <button class="btn primary" type="submit">Save invoice</button>
        <a class="btn" href="<?= e($doc ? robes_url('/invoices/' . (int) $doc['id']) : robes_url('/invoices')) ?>">Cancel</a>
    </div>
</form>
<template id="lineTemplate">
    <div class="line">
        <label>Description<input class="desc" name="line_desc[]" value=""></label>
        <label>Qty<input class="qty" name="line_qty[]" inputmode="decimal" value="1"></label>
        <label>Price<input class="price" name="line_price[]" inputmode="decimal" value=""></label>
        <label>Amount<span class="line-total num">₦0.00</span></label>
        <button class="btn tiny" type="button" data-remove-line>Remove</button>
        <label>ISIC<input name="line_isic[]" value=""></label>
        <label class="grow">Service category<input name="line_cat[]" value=""></label>
    </div>
</template>
