<?php

function robes_extra_errors(array $payload, array $settings): array
{
    $errors = array();
    $defaultIsic = trim((string) ($settings['default_isic'] ?? ''));
    $defaultCat = trim((string) ($settings['default_service_category'] ?? ''));
    $lines = isset($payload['line_items']) && is_array($payload['line_items']) ? $payload['line_items'] : array();
    $missingIsic = false;
    $missingCat = false;
    foreach ($lines as $li) {
        if (!is_array($li)) {
            continue;
        }
        if (trim((string) ($li['isic_code'] ?? '')) === '' && $defaultIsic === '') {
            $missingIsic = true;
        }
        if (trim((string) ($li['service_category'] ?? '')) === '' && $defaultCat === '') {
            $missingCat = true;
        }
    }
    if ($missingIsic) {
        $errors[] = 'Set an ISIC code in Settings, or on each line.';
    }
    if ($missingCat) {
        $errors[] = 'Set a service category in Settings, or on each line.';
    }
    return $errors;
}

function robes_apply_seller(array $payload, array $settings): array
{
    $payload['seller'] = array(
        'name' => trim((string) ($settings['seller_name'] ?? '')),
        'tin' => trim((string) ($settings['seller_tin'] ?? '')),
        'email' => trim((string) ($settings['seller_email'] ?? '')),
        'street' => trim((string) ($settings['seller_street'] ?? '')),
        'city' => trim((string) ($settings['seller_city'] ?? '')),
        'postal' => trim((string) ($settings['seller_postal'] ?? '')),
        'state' => trim((string) ($settings['seller_state'] ?? '')),
        'lga' => trim((string) ($settings['seller_lga'] ?? '')),
        'address_line' => '',
        'business_description' => trim((string) ($settings['seller_desc'] ?? '')),
    );
    $payload['service_id'] = trim((string) ($settings['service_id'] ?? ''));
    $payload['irn'] = nrs_build_irn(
        (string) ($payload['invoice_number'] ?? ''),
        (string) $payload['service_id'],
        (string) ($payload['issue_date'] ?? '')
    );
    return $payload;
}

function robes_build_payload(array $post, array $settings): array
{
    $errors = array();
    $prefix = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) ($settings['invoice_prefix'] ?? 'RB')));
    if ($prefix === '') {
        $prefix = 'RB';
    }
    $number = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) ($post['invoice_number'] ?? '')));
    if ($number === '') {
        $number = $prefix . date('YmdHis');
    }
    if (strlen($number) > 40) {
        $number = substr($number, 0, 40);
    }
    $issue = robes_parse_date((string) ($post['issue_date'] ?? ''));
    if ($issue === '') {
        $errors[] = 'Issue date is required.';
        $issue = date('Y-m-d');
    }
    $kind = strtoupper(trim((string) ($post['invoice_kind'] ?? '')));
    if ($kind === '') {
        $kind = strtoupper(trim((string) ($settings['invoice_kind'] ?? 'B2B')));
    }
    if (!in_array($kind, array('B2B', 'B2C', 'B2G', 'G2B'), true)) {
        $kind = 'B2B';
    }
    $payment = nrs_normalize_payment_status((string) ($post['payment_status'] ?? 'UNPAID'), 'UNPAID');
    $vatRaw = $post['vat_rate'] ?? null;
    $vatRate = is_numeric($vatRaw) ? (float) $vatRaw : (float) ($settings['vat_rate'] ?? 7.5);
    if ($vatRate < 0) {
        $vatRate = 0;
    }
    if ($vatRate > 100) {
        $vatRate = 100;
    }
    $inclusive = !empty($post['vat_inclusive']);
    $descs = isset($post['line_desc']) && is_array($post['line_desc']) ? $post['line_desc'] : array();
    $qtys = isset($post['line_qty']) && is_array($post['line_qty']) ? $post['line_qty'] : array();
    $prices = isset($post['line_price']) && is_array($post['line_price']) ? $post['line_price'] : array();
    $isics = isset($post['line_isic']) && is_array($post['line_isic']) ? $post['line_isic'] : array();
    $cats = isset($post['line_cat']) && is_array($post['line_cat']) ? $post['line_cat'] : array();
    $rawLines = array();
    $count = max(count($descs), count($prices));
    for ($i = 0; $i < $count && $i < 50; $i++) {
        $desc = trim((string) ($descs[$i] ?? ''));
        $qty = (float) ($qtys[$i] ?? 0);
        $price = (float) str_replace(array(',', ' '), '', (string) ($prices[$i] ?? '0'));
        if ($desc === '' && abs($price) < 0.00001) {
            continue;
        }
        if ($qty <= 0) {
            $qty = 1;
        }
        $rawLines[] = array(
            'description' => $desc !== '' ? $desc : ('Line ' . ($i + 1)),
            'quantity' => $qty,
            'input_unit_price' => round($price, 2),
            'isic_code' => trim((string) ($isics[$i] ?? '')),
            'service_category' => trim((string) ($cats[$i] ?? '')),
        );
    }
    if (!$rawLines) {
        $errors[] = 'Add at least one line with a description and price.';
        $rawLines[] = array(
            'description' => 'Line 1',
            'quantity' => 1,
            'input_unit_price' => 0,
            'isic_code' => '',
            'service_category' => '',
        );
    }
    $grosses = array();
    $grossSum = 0.0;
    foreach ($rawLines as $ln) {
        $gross = round($ln['quantity'] * $ln['input_unit_price'], 2);
        $grosses[] = $gross;
        $grossSum = round($grossSum + $gross, 2);
    }
    $lines = array();
    if ($inclusive && $vatRate > 0 && $grossSum > 0) {
        $netTarget = round($grossSum / (1 + ($vatRate / 100)), 2);
        $allocated = 0.0;
        $last = count($rawLines) - 1;
        foreach ($rawLines as $i => $ln) {
            if ($i === $last) {
                $lineNet = round($netTarget - $allocated, 2);
            } else {
                $lineNet = round($netTarget * ($grosses[$i] / $grossSum), 2);
                $allocated = round($allocated + $lineNet, 2);
            }
            $unit = $ln['quantity'] > 0 ? round($lineNet / $ln['quantity'], 2) : $lineNet;
            $lines[] = array(
                'description' => $ln['description'],
                'quantity' => $ln['quantity'],
                'unit_price' => $unit,
                'line_total' => $lineNet,
                'tax_rate' => $vatRate,
                'tax_amount' => round($grosses[$i] - $lineNet, 2),
                'isic_code' => $ln['isic_code'],
                'service_category' => $ln['service_category'],
                'input_unit_price' => $ln['input_unit_price'],
            );
        }
    } else {
        foreach ($rawLines as $i => $ln) {
            $lines[] = array(
                'description' => $ln['description'],
                'quantity' => $ln['quantity'],
                'unit_price' => $ln['input_unit_price'],
                'line_total' => $grosses[$i],
                'tax_rate' => $vatRate,
                'tax_amount' => 0,
                'isic_code' => $ln['isic_code'],
                'service_category' => $ln['service_category'],
                'input_unit_price' => $ln['input_unit_price'],
            );
        }
    }
    $netSum = 0.0;
    foreach ($lines as $ln) {
        $netSum = round($netSum + (float) $ln['line_total'], 2);
    }
    if ($inclusive && $vatRate > 0) {
        $total = $grossSum;
        $vat = round($total - $netSum, 2);
    } else {
        $vat = round($netSum * ($vatRate / 100), 2);
        $total = round($netSum + $vat, 2);
    }
    $note = trim((string) ($post['description'] ?? ''));
    if ($note === '') {
        $note = (string) $lines[0]['description'];
    }
    if (strlen($note) > 500) {
        $note = substr($note, 0, 500);
    }
    $reference = trim((string) ($post['reference'] ?? ''));
    if (strlen($reference) > 80) {
        $reference = substr($reference, 0, 80);
    }
    $payload = array(
        'invoice_number' => $number,
        'issue_date' => $issue,
        'currency' => 'NGN',
        'service_id' => trim((string) ($settings['service_id'] ?? '')),
        'description' => $note,
        'buyer' => array(
            'name' => trim((string) ($post['buyer_name'] ?? '')),
            'tin' => trim((string) ($post['buyer_tin'] ?? '')),
            'email' => trim((string) ($post['buyer_email'] ?? '')),
            'street' => trim((string) ($post['buyer_street'] ?? '')),
            'city' => trim((string) ($post['buyer_city'] ?? '')),
            'postal' => trim((string) ($post['buyer_postal'] ?? '')),
            'state' => trim((string) ($post['buyer_state'] ?? '')),
            'lga' => trim((string) ($post['buyer_lga'] ?? '')),
            'address_line' => '',
        ),
        'line_items' => $lines,
        'invoice_kind' => $kind,
        'payment_status' => $payment,
        'amounts' => array(
            'subtotal' => $netSum,
            'vat_amount' => $vat,
            'total' => $total,
            'vat_rate' => $vatRate,
            'vat_inclusive_source' => $inclusive ? 1 : 0,
        ),
        'robes' => array(
            'customer_id' => (int) ($post['customer_id'] ?? 0),
            'reference' => $reference,
        ),
    );
    $payload = robes_apply_seller($payload, $settings);
    return array('payload' => $payload, 'errors' => $errors, 'reference' => $reference);
}

function robes_touch(int $id, array $fields, int $userId): void
{
    $allowed = array(
        'status' => true, 'irn' => true, 'payload_json' => true, 'last_error' => true,
        'last_response_json' => true, 'invoice_number' => true, 'service_id' => true,
        'seller_name' => true, 'seller_tin' => true, 'provider_ref' => true,
        'submitted_at' => true, 'validated_at' => true,
    );
    $sets = array();
    $vals = array();
    foreach ($fields as $key => $value) {
        if (!isset($allowed[$key])) {
            continue;
        }
        $sets[] = $key . '=?';
        $vals[] = $value;
    }
    $sets[] = 'updated_at=?';
    $vals[] = nrs_now();
    $sets[] = 'updated_by=?';
    $vals[] = $userId;
    $vals[] = $id;
    robe_pdo()->prepare('UPDATE nrs_einvoice SET ' . implode(', ', $sets) . ' WHERE id=?')->execute($vals);
}

function robes_persist_invoice(int $id, array $payload, string $reference, string $status, string $error, int $userId): int
{
    $now = nrs_now();
    $amounts = isset($payload['amounts']) && is_array($payload['amounts']) ? $payload['amounts'] : array();
    $pdo = robe_pdo();
    if ($id < 1) {
        $sourceId = nrs_alloc_manual_source_id(robe_conn());
        if (!isset($payload['robes']) || !is_array($payload['robes'])) {
            $payload['robes'] = array();
        }
        $payload['robes']['source_id'] = $sourceId;
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = '{}';
        }
        $pdo->prepare(
            'INSERT INTO nrs_einvoice (
                source_type, source_id, source_reference, invoice_number, issue_date, currency,
                amount, vat_amount, total_amount, description, buyer_name, buyer_tin, seller_name, seller_tin,
                service_id, irn, status, payload_json, last_error, created_at, created_by, updated_at, updated_by
            ) VALUES (\'manual\', ?, ?, ?, ?, \'NGN\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(array(
            $sourceId,
            $reference,
            (string) $payload['invoice_number'],
            (string) $payload['issue_date'],
            number_format((float) ($amounts['subtotal'] ?? 0), 2, '.', ''),
            number_format((float) ($amounts['vat_amount'] ?? 0), 2, '.', ''),
            number_format((float) ($amounts['total'] ?? 0), 2, '.', ''),
            (string) $payload['description'],
            (string) ($payload['buyer']['name'] ?? ''),
            (string) ($payload['buyer']['tin'] ?? ''),
            (string) ($payload['seller']['name'] ?? ''),
            (string) ($payload['seller']['tin'] ?? ''),
            (string) ($payload['service_id'] ?? ''),
            (string) ($payload['irn'] ?? ''),
            $status,
            $json,
            substr($error, 0, 500),
            $now,
            $userId,
            $now,
            $userId,
        ));
        return (int) $pdo->lastInsertId();
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $json = '{}';
    }
    $pdo->prepare(
        'UPDATE nrs_einvoice SET
            invoice_number=?, issue_date=?, amount=?, vat_amount=?, total_amount=?, description=?,
            buyer_name=?, buyer_tin=?, seller_name=?, seller_tin=?, service_id=?, irn=?, status=?,
            payload_json=?, last_error=?, source_reference=?, updated_at=?, updated_by=?
         WHERE id=?'
    )->execute(array(
        (string) $payload['invoice_number'],
        (string) $payload['issue_date'],
        number_format((float) ($amounts['subtotal'] ?? 0), 2, '.', ''),
        number_format((float) ($amounts['vat_amount'] ?? 0), 2, '.', ''),
        number_format((float) ($amounts['total'] ?? 0), 2, '.', ''),
        (string) $payload['description'],
        (string) ($payload['buyer']['name'] ?? ''),
        (string) ($payload['buyer']['tin'] ?? ''),
        (string) ($payload['seller']['name'] ?? ''),
        (string) ($payload['seller']['tin'] ?? ''),
        (string) ($payload['service_id'] ?? ''),
        (string) ($payload['irn'] ?? ''),
        $status,
        $json,
        substr($error, 0, 500),
        $reference,
        $now,
        $userId,
        $id,
    ));
    return $id;
}

function robes_store_from_post(int $id, array $post, int $userId): array
{
    $existing = $id > 0 ? nrs_get_document(robe_conn(), $id) : null;
    if ($id > 0 && !$existing) {
        return array('id' => 0, 'ok' => false, 'errors' => array('Invoice not found.'), 'missing' => true);
    }
    if ($existing && !robes_editable($existing)) {
        return array('id' => $id, 'ok' => false, 'errors' => array('This invoice is locked because it has already gone to NRS.'), 'locked' => true);
    }
    $settings = robes_settings(true);
    $built = robes_build_payload($post, $settings);
    $payload = $built['payload'];
    $errors = $built['errors'];
    $local = nrs_local_validate_payload($payload, $settings);
    $errors = array_merge($errors, $local['errors'], robes_extra_errors($payload, $settings));
    $dup = robe_pdo()->prepare('SELECT id FROM nrs_einvoice WHERE irn = ? AND id <> ? LIMIT 1');
    $dup->execute(array((string) $payload['irn'], $id));
    $dupId = $dup->fetchColumn();
    if ($dupId) {
        $errors[] = 'This IRN is already used on invoice #' . $dupId . '. Change the invoice number.';
    }
    $errors = array_values(array_unique($errors));
    $status = $errors === array() ? 'READY' : 'DRAFT';
    $newId = robes_persist_invoice($id, $payload, (string) $built['reference'], $status, implode(' ', $errors), $userId);
    if (!empty($post['save_customer'])) {
        robes_save_buyer_customer((int) ($post['customer_id'] ?? 0), $payload['buyer']);
    }
    return array('id' => $newId, 'ok' => $errors === array(), 'errors' => $errors);
}

function robes_save_buyer_customer(int $customerId, array $buyer): void
{
    $name = trim((string) ($buyer['name'] ?? ''));
    if ($name === '') {
        return;
    }
    $now = nrs_now();
    $pdo = robe_pdo();
    $values = array(
        $name,
        trim((string) ($buyer['tin'] ?? '')),
        trim((string) ($buyer['email'] ?? '')),
        trim((string) ($buyer['street'] ?? '')),
        trim((string) ($buyer['city'] ?? '')),
        trim((string) ($buyer['postal'] ?? '')),
        trim((string) ($buyer['state'] ?? '')),
        trim((string) ($buyer['lga'] ?? '')),
    );
    if ($customerId > 0) {
        $values[] = $now;
        $values[] = $customerId;
        $pdo->prepare('UPDATE customers SET name=?, tin=?, email=?, street=?, city=?, postal=?, state=?, lga=?, updated_at=? WHERE id=?')->execute($values);
        return;
    }
    $pdo->prepare('INSERT INTO customers (name, tin, email, phone, street, city, postal, state, lga, notes, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute(array(
        $values[0], $values[1], $values[2], '', $values[3], $values[4], $values[5], $values[6], $values[7], '', $now, $now,
    ));
}

function robes_push(array $doc, string $action, int $userId): array
{
    $settings = robes_settings(true);
    $payload = json_decode((string) ($doc['payload_json'] ?? ''), true);
    if (!is_array($payload)) {
        $payload = array();
    }
    $payload = robes_apply_seller($payload, $settings);
    $payload = nrs_enrich_payload_parties(robe_conn(), $payload, $settings);
    $local = nrs_local_validate_payload($payload, $settings);
    $errors = array_merge($local['errors'], robes_extra_errors($payload, $settings));
    if ($errors) {
        return array('ok' => false, 'message' => 'Fix this invoice before calling NRS: ' . implode(' ', $errors));
    }
    $docId = (int) $doc['id'];
    $gateway = nrs_build_gateway_document($payload, $doc, $settings);
    if (!empty($gateway['irn'])) {
        $payload['irn'] = (string) $gateway['irn'];
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    robes_touch($docId, array(
        'status' => $action === 'validate' ? 'VALIDATING' : 'SUBMITTING',
        'irn' => (string) ($payload['irn'] ?? ''),
        'payload_json' => $json === false ? '{}' : $json,
        'service_id' => (string) ($payload['service_id'] ?? ''),
        'seller_name' => (string) ($payload['seller']['name'] ?? ''),
        'seller_tin' => (string) ($payload['seller']['tin'] ?? ''),
    ), $userId);
    $path = $action === 'validate' ? '/invoice/validate' : '/invoice/sign';
    $response = nrs_request($settings, 'POST', $path, $gateway);
    nrs_log_attempt(
        robe_conn(),
        $docId,
        $action,
        (int) $response['http_status'],
        $gateway,
        $response['json'] !== null ? $response['json'] : $response['body'],
        (bool) $response['ok'],
        $response['ok'] ? '' : nrs_response_error_message($response),
        $userId
    );
    $doc['irn'] = (string) ($payload['irn'] ?? '');
    $fallback = $action === 'validate' ? 'VALIDATED' : 'SUBMITTED';
    return nrs_apply_gateway_result(robe_conn(), $docId, $doc, $response, $action, $userId, $fallback);
}

function robes_confirm(array $doc, int $userId): array
{
    $settings = robes_settings(true);
    $irnRaw = trim((string) ($doc['irn'] ?? ''));
    if ($irnRaw === '') {
        return array('ok' => false, 'message' => 'This invoice has no IRN yet.');
    }
    $response = nrs_request($settings, 'GET', '/invoice/confirm/' . rawurlencode($irnRaw), null);
    nrs_log_attempt(
        robe_conn(),
        (int) $doc['id'],
        'confirm',
        (int) $response['http_status'],
        array('irn' => $irnRaw),
        $response['json'] !== null ? $response['json'] : $response['body'],
        (bool) $response['ok'],
        $response['ok'] ? '' : nrs_response_error_message($response),
        $userId
    );
    if ($response['ok']) {
        robes_touch((int) $doc['id'], array(
            'status' => 'CONFIRMED',
            'last_response_json' => (string) ($response['body'] ?? ''),
            'last_error' => '',
        ), $userId);
        return array('ok' => true, 'message' => 'Confirm succeeded. The status is now Confirmed. Open the receipt for the signed invoice.');
    }
    $err = nrs_response_error_message($response);
    if (stripos($err, 'issue from nrs') === false) {
        $err = 'Issue from NRS — ' . $err;
    }
    robes_touch((int) $doc['id'], array(
        'last_response_json' => (string) ($response['body'] ?? ''),
        'last_error' => substr($err, 0, 500),
    ), $userId);
    return array('ok' => false, 'message' => 'Confirm failed because of an NRS problem: ' . $err);
}

function robes_fetch_signed(array $doc, string $action, int $userId): array
{
    $empty = array('ok' => false, 'message' => '', 'invoice' => null, 'plain' => '', 'pdf' => null, 'redirect' => '');
    $settings = robes_settings(true);
    $irnRaw = trim((string) ($doc['irn'] ?? ''));
    if ($irnRaw === '') {
        $empty['message'] = 'This invoice has no IRN yet.';
        return $empty;
    }
    $response = nrs_request($settings, 'GET', '/invoice/download/' . rawurlencode($irnRaw), null);
    nrs_log_attempt(
        robe_conn(),
        (int) $doc['id'],
        $action,
        (int) $response['http_status'],
        array('irn' => $irnRaw),
        $response['json'] !== null ? $response['json'] : array('bytes' => strlen((string) ($response['body'] ?? ''))),
        (bool) $response['ok'],
        $response['ok'] ? '' : nrs_response_error_message($response),
        $userId
    );
    $invoice = null;
    $plain = '';
    $err = '';
    if ($response['ok'] && is_array($response['json'])) {
        $data = nrs_response_data($response);
        if (isset($data['iv_hex']) || (isset($data['data']) && is_string($data['data']) && isset($data['pub']))) {
            $dec = nrs_decrypt_signed_download($response['json'], (string) ($settings['api_key'] ?? ''));
            if ($dec['ok']) {
                $plain = (string) $dec['plaintext'];
                $invoice = $dec['invoice'];
            } else {
                $err = (string) $dec['error'];
            }
        } elseif (isset($data['irn']) || isset($data['invoice_line']) || isset($data['business_id'])) {
            $invoice = $data;
            $plain = (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } elseif (!empty($data['download_url']) && is_string($data['download_url']) && preg_match('#^https://#i', $data['download_url'])) {
            $empty['ok'] = true;
            $empty['redirect'] = $data['download_url'];
            return $empty;
        } else {
            $err = 'NRS responded but did not return a signed invoice.';
        }
    } elseif ($response['ok'] && strncmp((string) ($response['body'] ?? ''), '%PDF', 4) === 0) {
        $empty['ok'] = true;
        $empty['pdf'] = (string) $response['body'];
        return $empty;
    } else {
        $err = $response['ok'] ? 'NRS returned an empty file.' : nrs_response_error_message($response);
    }
    if ($invoice !== null || $plain !== '') {
        $store = $plain !== '' ? $plain : (string) json_encode($invoice);
        robes_touch((int) $doc['id'], array('last_response_json' => $store, 'last_error' => ''), $userId);
        $empty['ok'] = true;
        $empty['invoice'] = is_array($invoice) ? $invoice : null;
        $empty['plain'] = $plain;
        return $empty;
    }
    robes_touch((int) $doc['id'], array(
        'last_response_json' => (string) ($response['body'] ?? ''),
        'last_error' => substr($err, 0, 500),
    ), $userId);
    $empty['message'] = ($action === 'receipt' ? 'Receipt' : 'Download') . ' failed because of an NRS problem: ' . $err;
    return $empty;
}

function robes_preview_invoice(array $doc): void
{
    $settings = robes_settings(true);
    $payload = json_decode((string) ($doc['payload_json'] ?? ''), true);
    if (!is_array($payload)) {
        $payload = array();
    }
    $payload = robes_apply_seller($payload, $settings);
    $gateway = nrs_build_gateway_document($payload, $doc, $settings);
    $gateway['_preview'] = true;
    header('Content-Type: text/html; charset=utf-8');
    echo nrs_render_receipt_html($doc, $gateway, $settings, (int) $doc['id'], robe_conn());
    exit;
}

function robes_retry_irn(array $doc, int $userId): array
{
    if (!robes_editable($doc)) {
        return array('ok' => false, 'message' => 'This invoice can no longer change its number.');
    }
    $payload = json_decode((string) ($doc['payload_json'] ?? ''), true);
    if (!is_array($payload)) {
        $payload = array();
    }
    $next = nrs_next_retry_invoice_number((string) ($doc['invoice_number'] ?? ''));
    $payload['invoice_number'] = $next;
    $settings = robes_settings(true);
    $payload = robes_apply_seller($payload, $settings);
    $local = nrs_local_validate_payload($payload, $settings);
    $errors = array_merge($local['errors'], robes_extra_errors($payload, $settings));
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    robes_touch((int) $doc['id'], array(
        'invoice_number' => $next,
        'irn' => (string) $payload['irn'],
        'payload_json' => $json === false ? '{}' : $json,
        'status' => $errors ? 'DRAFT' : 'READY',
        'last_error' => substr(implode(' ', $errors), 0, 500),
        'service_id' => (string) ($payload['service_id'] ?? ''),
    ), $userId);
    return array('ok' => true, 'message' => 'Invoice number is now ' . $next . '. Validate this new IRN before you sign.');
}

function robes_import_csv(string $path, int $userId): string
{
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return 'Could not read that file.';
    }
    $header = fgetcsv($fh);
    if (!is_array($header)) {
        fclose($fh);
        return 'The CSV is empty.';
    }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) ($header[0] ?? ''));
    $map = array();
    foreach ($header as $i => $name) {
        $map[strtolower(trim((string) $name))] = $i;
    }
    if (!isset($map['buyer_name']) || !isset($map['unit_price'])) {
        fclose($fh);
        return 'The CSV needs buyer_name and unit_price columns.';
    }
    $ready = 0;
    $draft = 0;
    $skipped = 0;
    $rowNum = 1;
    while (($row = fgetcsv($fh)) !== false) {
        $rowNum++;
        if ($rowNum > 501) {
            break;
        }
        $filled = array_filter($row, function ($value) {
            return trim((string) $value) !== '';
        });
        if ($filled === array()) {
            continue;
        }
        $get = function (string $key) use ($map, $row): string {
            if (!isset($map[$key])) {
                return '';
            }
            return trim((string) ($row[$map[$key]] ?? ''));
        };
        $isic = $get('isic');
        if ($isic === '') {
            $isic = $get('isic_code');
        }
        $desc = $get('description');
        $post = array(
            'invoice_number' => $get('invoice_number'),
            'issue_date' => $get('issue_date') !== '' ? $get('issue_date') : date('Y-m-d'),
            'reference' => $get('reference'),
            'buyer_name' => $get('buyer_name'),
            'buyer_tin' => $get('buyer_tin'),
            'buyer_email' => $get('buyer_email'),
            'buyer_street' => $get('buyer_street'),
            'buyer_city' => $get('buyer_city'),
            'buyer_state' => $get('buyer_state'),
            'buyer_lga' => $get('buyer_lga'),
            'buyer_postal' => $get('buyer_postal'),
            'description' => $desc,
            'payment_status' => $get('payment_status') !== '' ? $get('payment_status') : 'UNPAID',
            'invoice_kind' => $get('invoice_kind'),
            'line_desc' => array($desc !== '' ? $desc : 'Imported line'),
            'line_qty' => array($get('quantity') !== '' ? $get('quantity') : '1'),
            'line_price' => array($get('unit_price')),
            'line_isic' => array($isic),
            'line_cat' => array($get('service_category')),
            'save_customer' => '1',
        );
        if ($get('vat_rate') !== '') {
            $post['vat_rate'] = $get('vat_rate');
        }
        if ($post['buyer_name'] === '' || (float) $get('unit_price') <= 0) {
            $skipped++;
            continue;
        }
        $result = robes_store_from_post(0, $post, $userId);
        if (!empty($result['ok'])) {
            $ready++;
        } else {
            $draft++;
        }
    }
    fclose($fh);
    return 'Imported ' . $ready . ' ready invoice(s), ' . $draft . ' draft(s), skipped ' . $skipped . ' row(s).';
}

function robes_invoice_query(string $q, string $bucket, string $status): array
{
    $sql = 'SELECT * FROM nrs_einvoice WHERE 1=1';
    $params = array();
    $buckets = array(
        'draft' => array('DRAFT', 'READY'),
        'nrs' => array('VALIDATING', 'VALIDATED', 'SUBMITTING', 'SUBMITTED', 'QUEUED', 'SIGNED', 'TRANSMITTED'),
        'confirmed' => array('CONFIRMED'),
        'failed' => array('FAILED', 'REJECTED'),
    );
    if ($status !== '') {
        $sql .= ' AND status = ?';
        $params[] = strtoupper($status);
    } elseif (isset($buckets[$bucket])) {
        $marks = implode(',', array_fill(0, count($buckets[$bucket]), '?'));
        $sql .= ' AND status IN (' . $marks . ')';
        foreach ($buckets[$bucket] as $item) {
            $params[] = $item;
        }
    }
    if ($q !== '') {
        $escaped = str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $q);
        $like = '%' . $escaped . '%';
        $sql .= " AND (invoice_number LIKE ? ESCAPE '\\' OR buyer_name LIKE ? ESCAPE '\\' OR irn LIKE ? ESCAPE '\\' OR description LIKE ? ESCAPE '\\' OR source_reference LIKE ? ESCAPE '\\')";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    $sql .= ' ORDER BY id DESC LIMIT 200';
    $stmt = robe_pdo()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    return is_array($rows) ? $rows : array();
}
