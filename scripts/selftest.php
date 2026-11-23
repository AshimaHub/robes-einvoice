<?php
$root = dirname(__DIR__);
$db = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'robes-selftest-' . getmypid() . '.sqlite';
@unlink($db);
putenv('ROBES_DB=' . $db);

foreach (array(
    dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf',
    'C:/laragon/bin/apache/httpd-2.4.54-win64-VS16/conf/openssl.cnf',
) as $cnf) {
    if (is_file($cnf)) {
        putenv('OPENSSL_CONF=' . $cnf);
        break;
    }
}

require $root . '/app/bootstrap.php';

$fail = 0;
function check($label, $cond) {
    global $fail;
    if ($cond) {
        echo "OK  $label\n";
        return;
    }
    $fail++;
    echo "FAIL $label\n";
}

check('irn', nrs_build_irn('INV-001', 'AB12CD34', '2026-11-21') === 'INV001-AB12CD34-20261121');
check('retry number', nrs_next_retry_invoice_number('RB1') === 'RB1R2');
check('base url', nrs_normalize_base_url('https://einvoice1.nrs.gov.ng', 'nrs_direct') === 'https://einvoice1.nrs.gov.ng/api/v1');

$parsed = nrs_parse_crypto_keys_file('{"public_key":"abc","certificate":"def"}');
check('crypto parse', !empty($parsed['ok']) && $parsed['public_key'] === 'abc' && $parsed['certificate'] === 'def');

nrs_save_settings(robe_conn(), array(
    'seller_name' => 'Acme Limited',
    'seller_tin' => '12345678-0001',
    'seller_email' => 'accounts@acme.test',
    'seller_street' => '12 Market Road',
    'seller_city' => 'Kano',
    'seller_state' => 'NG-KN',
    'seller_lga' => 'NG-KN-TAR',
    'business_id' => '11111111-1111-1111-1111-111111111111',
    'service_id' => 'AB12CD34',
    'default_isic' => '4690',
    'default_service_category' => 'Wholesale trade',
    'vat_rate' => '7.5',
    'provider' => 'nrs_direct',
    'enabled' => '1',
    'api_key' => 'test-key',
    'api_secret' => 'test-secret',
), 1);
$settings = robes_settings(true);
check('settings roundtrip', $settings['seller_name'] === 'Acme Limited' && $settings['service_id'] === 'AB12CD34');

$post = array(
    'buyer_name' => 'Northwind Stores',
    'buyer_tin' => '99887766-0001',
    'buyer_email' => 'buy@northwind.test',
    'buyer_street' => '4 Creek Lane',
    'buyer_city' => 'Lagos',
    'buyer_state' => 'NG-LA',
    'buyer_lga' => 'NG-LA-IKE',
    'issue_date' => '2026-11-21',
    'invoice_number' => 'RB1001',
    'description' => 'Cement supply',
    'invoice_kind' => 'B2B',
    'payment_status' => 'UNPAID',
    'vat_rate' => '7.5',
    'line_desc' => array('Cement', 'Delivery'),
    'line_qty' => array('10', '1'),
    'line_price' => array('1000', '500'),
    'line_isic' => array('', ''),
    'line_cat' => array('', ''),
    'save_customer' => '1',
);
$saved = robes_store_from_post(0, $post, 1);
check('invoice ready', !empty($saved['ok']) && (int) $saved['id'] > 0);
if (empty($saved['ok'])) {
    echo '    ' . implode(' | ', $saved['errors']) . "\n";
}
$doc = nrs_get_document(robe_conn(), (int) $saved['id']);
$payload = json_decode((string) $doc['payload_json'], true);
check('two lines', is_array($payload) && count($payload['line_items']) === 2);
check('vat math', is_array($payload) && abs((float) $payload['amounts']['subtotal'] - 10500) < 0.001 && abs((float) $payload['amounts']['vat_amount'] - 787.5) < 0.001);

$gateway = nrs_build_gateway_document($payload, $doc, $settings);
check('b2b customer', isset($gateway['accounting_customer_party']['tin']));
check('ubl lines', isset($gateway['invoice_line']) && count($gateway['invoice_line']) === 2);
check('isic fallback', ($gateway['invoice_line'][0]['isic_code'] ?? '') === '4690');

$payload['invoice_kind'] = 'B2C';
$b2c = nrs_build_gateway_document($payload, $doc, $settings);
check('b2c omits buyer party', !isset($b2c['accounting_customer_party']));

$bad = $payload;
$bad['buyer']['email'] = '';
$local = nrs_local_validate_payload($bad, $settings);
check('email required', empty($local['ok']));

nrs_tin_party_save(robe_conn(), '12345678-0001', array(
    'name' => 'Cached Co',
    'street' => '1 Road',
    'city' => 'Kano',
    'email' => 'a@b.co',
), 'lookup');
nrs_tin_party_save(robe_conn(), '123456780001', array('name' => 'Cached Co'), 'lookup');
$party = nrs_tin_party_get(robe_conn(), '12-345-6780001');
check('tin cache keeps street', is_array($party) && $party['street'] === '1 Road' && $party['party_name'] === 'Cached Co');

$customers = (int) robe_pdo()->query('SELECT COUNT(*) FROM customers')->fetchColumn();
check('customer saved', $customers === 1);

$opensslConfig = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf';
$keyOpts = array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA);
if (is_file($opensslConfig)) {
    $keyOpts['config'] = $opensslConfig;
}
$key = openssl_pkey_new($keyOpts);
if ($key) {
    $details = openssl_pkey_get_details($key);
    $settings['crypto_public_key'] = $details['key'];
    $settings['crypto_certificate'] = base64_encode('cert');
    $stamp = nrs_build_qr_stamp('INV1-AB12CD34-20261121', $settings);
    check('qr stamp', !empty($stamp['ok']) && $stamp['payload'] !== '');
    if (!empty($stamp['ok'])) {
        $image = nrs_qr_image_data_uri($stamp['payload']);
        check('qr image', !empty($image['ok']) && strpos((string) $image['uri'], 'data:image/') === 0);
    }
} else {
    echo "SKIP qr stamp (openssl.cnf)\n";
}

$dec = nrs_decrypt_signed_download(array('iv_hex' => 'aa', 'pub' => 'x', 'data' => 'y'), 'short');
check('decrypt rejects bad payload', empty($dec['ok']));

$gateway['_preview'] = true;
$html = nrs_render_receipt_html($doc, $gateway, $settings, (int) $doc['id'], robe_conn());
check('receipt letterhead', strpos($html, 'Acme Limited') !== false && strpos($html, 'Draft preview') !== false);
check('receipt buyer', strpos($html, 'Northwind Stores') !== false);

@unlink($db);
if ($fail > 0) {
    echo "$fail failed\n";
    exit(1);
}
echo "all passed\n";
