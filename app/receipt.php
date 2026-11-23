<?php

function nrs_render_receipt_html(array $doc, array $invoice, array $settings, int $docId, $db = null): string
{
    $preview = !empty($invoice['_preview']);
    $irn = (string) ($invoice['irn'] ?? ($doc['irn'] ?? ''));
    $seller = isset($invoice['accounting_supplier_party']) && is_array($invoice['accounting_supplier_party'])
        ? $invoice['accounting_supplier_party'] : array();
    if ($seller === array() && isset($invoice['seller']) && is_array($invoice['seller'])) {
        $seller = $invoice['seller'];
        if (!isset($seller['party_name']) && isset($seller['name'])) {
            $seller['party_name'] = $seller['name'];
        }
    }
    $buyer = isset($invoice['accounting_customer_party']) && is_array($invoice['accounting_customer_party'])
        ? $invoice['accounting_customer_party'] : array();
    if ($buyer === array() && isset($invoice['buyer']) && is_array($invoice['buyer'])) {
        $buyer = $invoice['buyer'];
        if (!isset($buyer['party_name']) && isset($buyer['name'])) {
            $buyer['party_name'] = $buyer['name'];
        }
    }
    $money = isset($invoice['legal_monetary_total']) && is_array($invoice['legal_monetary_total'])
        ? $invoice['legal_monetary_total'] : array();
    $lines = isset($invoice['invoice_line']) && is_array($invoice['invoice_line']) ? $invoice['invoice_line'] : array();
    if ($lines === array() && isset($invoice['line_items']) && is_array($invoice['line_items'])) {
        $lines = $invoice['line_items'];
    }

    $payloadDoc = array();
    if (!empty($doc['payload_json'])) {
        $decoded = json_decode((string) $doc['payload_json'], true);
        if (is_array($decoded)) {
            $payloadDoc = $decoded;
        }
    }

    $invoiceNumber = trim((string) ($invoice['invoice_number'] ?? ($doc['invoice_number'] ?? '')));
    if ($invoiceNumber === '' && $irn !== '') {
        $invoiceNumber = explode('-', $irn)[0];
    }
    $issueDate = (string) ($invoice['issue_date'] ?? ($doc['issue_date'] ?? ''));
    $issueTime = (string) ($invoice['issue_time'] ?? '');
    $kind = strtoupper(trim((string) ($invoice['invoice_kind'] ?? ($payloadDoc['invoice_kind'] ?? 'B2B'))));
    $payment = (string) ($invoice['payment_status'] ?? ($payloadDoc['payment_status'] ?? ''));
    $currency = strtoupper(trim((string) ($invoice['document_currency_code'] ?? 'NGN')));
    if ($currency === '') {
        $currency = 'NGN';
    }
    $symbol = $currency === 'NGN' ? '₦' : ($currency . ' ');

    $taxEx = (float) ($money['tax_exclusive_amount'] ?? ($doc['amount'] ?? 0));
    $taxIn = (float) ($money['tax_inclusive_amount'] ?? ($doc['total_amount'] ?? 0));
    $payable = (float) ($money['payable_amount'] ?? $taxIn);
    $vatAmount = (float) ($doc['vat_amount'] ?? 0);
    $vatRate = (float) ($payloadDoc['amounts']['vat_rate'] ?? ($settings['vat_rate'] ?? 7.5));
    $taxTotals = isset($invoice['tax_total']) && is_array($invoice['tax_total']) ? $invoice['tax_total'] : array();
    if ($taxTotals) {
        $firstTax = isset($taxTotals[0]) && is_array($taxTotals[0]) ? $taxTotals[0] : $taxTotals;
        if (isset($firstTax['tax_amount'])) {
            $vatAmount = (float) $firstTax['tax_amount'];
        }
        $sub = isset($firstTax['tax_subtotal'][0]) && is_array($firstTax['tax_subtotal'][0]) ? $firstTax['tax_subtotal'][0] : array();
        if (isset($sub['tax_amount'])) {
            $vatAmount = (float) $sub['tax_amount'];
        }
        if (isset($sub['tax_category']['percent'])) {
            $vatRate = (float) $sub['tax_category']['percent'];
        }
        if (isset($sub['taxable_amount']) && $taxEx <= 0) {
            $taxEx = (float) $sub['taxable_amount'];
        }
    }
    if ($vatAmount <= 0 && $taxIn > $taxEx) {
        $vatAmount = round($taxIn - $taxEx, 2);
    }

    $sellerName = (string) ($seller['party_name'] ?? ($seller['name'] ?? ($doc['seller_name'] ?? ($settings['seller_name'] ?? ''))));
    $sellerTin = (string) ($seller['tin'] ?? ($doc['seller_tin'] ?? ($settings['seller_tin'] ?? '')));
    $sellerEmail = (string) ($seller['email'] ?? ($settings['seller_email'] ?? ''));
    $sellerAddr = nrs_receipt_format_address($seller, $settings, 'seller');
    $buyerName = (string) ($buyer['party_name'] ?? ($buyer['name'] ?? ($doc['buyer_name'] ?? '')));
    $buyerTin = (string) ($buyer['tin'] ?? ($doc['buyer_tin'] ?? ''));
    $buyerEmail = (string) ($buyer['email'] ?? '');
    $buyerAddr = nrs_receipt_format_address($buyer, $settings, 'buyer');
    if (($buyerAddr === '—' || $buyerAddr === '') && isset($payloadDoc['buyer']) && is_array($payloadDoc['buyer'])) {
        $buyerAddr = nrs_receipt_format_address($payloadDoc['buyer'], $settings, 'buyer');
    }
    if (($buyerAddr === '—' || $buyerAddr === '') && $buyerTin !== '' && $db) {
        $cached = nrs_tin_party_get($db, $buyerTin);
        if ($cached) {
            $buyerAddr = trim((string) ($cached['address_line'] ?? ''));
            if ($buyerAddr === '') {
                $buyerAddr = trim(implode(', ', array_filter(array(
                    (string) ($cached['street'] ?? ''),
                    (string) ($cached['city'] ?? ''),
                    (string) ($cached['state'] ?? ''),
                ))));
            }
        }
    }
    if ($sellerAddr === '—' || $sellerAddr === '') {
        $sellerAddr = trim(implode(', ', array_filter(array(
            (string) ($settings['seller_street'] ?? ''),
            (string) ($settings['seller_city'] ?? ''),
            (string) ($settings['seller_state'] ?? ''),
        ))));
    }
    if (strcasecmp($sellerAddr, 'NG') === 0 || $sellerAddr === '—') {
        $sellerAddr = '';
    }
    if (strcasecmp($buyerAddr, 'NG') === 0 || $buyerAddr === '—') {
        $buyerAddr = '';
    }

    $note = trim((string) ($invoice['note'] ?? ($doc['description'] ?? '')));
    $rc = trim((string) ($settings['company_rc'] ?? ''));
    $brand = trim((string) ($settings['brand_name'] ?? ''));
    if ($brand === '') {
        $brand = 'Robes eInvoice';
    }
    $color = (string) ($settings['brand_color'] ?? '#0f6e56');
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
        $color = '#0f6e56';
    }
    $accent = (string) ($settings['brand_accent'] ?? '#c4a15a');
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $accent)) {
        $accent = '#c4a15a';
    }
    $status = strtoupper((string) ($doc['status'] ?? ''));
    if ($preview) {
        $pill = 'Preview';
    } elseif (in_array($status, array('CONFIRMED', 'SIGNED', 'VALIDATED'), true)) {
        $pill = 'Valid';
    } else {
        $pill = nrs_status_label($status);
    }

    $qrHtml = '';
    if ($irn !== '') {
        $stamp = nrs_build_qr_stamp($irn, $settings);
        if (!empty($stamp['ok'])) {
            $image = nrs_qr_image_data_uri((string) $stamp['payload']);
            if (!empty($image['ok'])) {
                $qrHtml = '<img class="qr" alt="NRS authenticity QR" src="' . e($image['uri']) . '">';
            } else {
                $qrHtml = '<p class="qr-miss">' . e((string) ($image['error'] ?? 'QR image failed.')) . '</p>';
            }
        } else {
            $qrHtml = '<p class="qr-miss">' . e((string) ($stamp['error'] ?? 'QR keys are not ready.')) . '</p>';
        }
    }

    $logoHtml = '';
    $logoFile = robes_logo_file();
    if ($logoFile !== '') {
        $bin = file_get_contents($logoFile);
        if (is_string($bin) && $bin !== '') {
            $mime = 'image/png';
            if (function_exists('mime_content_type')) {
                $detected = mime_content_type($logoFile);
                if (is_string($detected) && $detected !== '') {
                    $mime = $detected;
                }
            }
            $logoHtml = '<img class="logo" alt="" src="data:' . e($mime) . ';base64,' . base64_encode($bin) . '">';
        }
    }

    $rows = '';
    $n = 0;
    foreach ($lines as $li) {
        if (!is_array($li)) {
            continue;
        }
        $n++;
        if (isset($li['item']) && is_array($li['item'])) {
            $name = (string) ($li['item']['name'] ?? $li['item']['description'] ?? 'Line');
            $qty = (float) ($li['invoiced_quantity'] ?? 1);
            $amt = (float) ($li['line_extension_amount'] ?? 0);
            $price = (float) ($li['price']['price_amount'] ?? ($qty > 0 ? $amt / $qty : $amt));
        } else {
            $name = (string) ($li['description'] ?? 'Line');
            $qty = (float) ($li['quantity'] ?? 1);
            $amt = (float) ($li['line_total'] ?? 0);
            $price = (float) ($li['unit_price'] ?? $amt);
        }
        $qtyLabel = abs($qty - round($qty)) < 0.001 ? (string) (int) round($qty) : number_format($qty, 2);
        $rows .= '<tr><td>' . $n . '</td><td>' . e($name) . '</td><td class="num">' . e($qtyLabel)
            . '</td><td class="num">' . e($symbol . number_format($price, 2))
            . '</td><td class="num">' . e($symbol . number_format($amt, 2)) . '</td></tr>';
    }
    if ($rows === '') {
        $rows = '<tr><td colspan="5">No lines on this invoice.</td></tr>';
    }

    $back = robes_url('/invoices/' . $docId);
    $banner = $preview
        ? '<div class="banner">Draft preview. This is not a signed NRS receipt.</div>'
        : '';
    $when = e(robes_date($issueDate));
    if ($issueTime !== '') {
        $when .= ' ' . e($issueTime);
    }

    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . e($invoiceNumber !== '' ? $invoiceNumber : 'Invoice') . '</title><style>
        :root { --brand:' . e($color) . '; --gold:' . e($accent) . '; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #efeae1; color: #14221c; font: 14px/1.45 "Segoe UI", sans-serif; }
        .toolbar { display: flex; gap: 8px; justify-content: flex-end; max-width: 860px; margin: 16px auto 0; padding: 0 16px; }
        .toolbar button, .toolbar a { font: inherit; background: var(--brand); color: #fff; text-decoration: none; border: 0; border-radius: 999px; padding: 8px 14px; }
        .toolbar a.ghost { background: transparent; color: #14221c; border: 1px solid #cfc6b8; }
        .sheet { max-width: 860px; margin: 16px auto 32px; background: #fff; padding: 32px 36px 28px; box-shadow: 0 12px 40px rgba(20,34,28,.08); }
        .banner { background: #f4e4c4; color: #5c4314; padding: 8px 12px; margin: -32px -36px 20px; font-weight: 600; }
        .head { display: flex; justify-content: space-between; gap: 24px; border-top: 4px solid var(--gold); padding-top: 16px; }
        .logo { max-height: 54px; max-width: 160px; object-fit: contain; margin-bottom: 8px; }
        h1 { font: 600 28px/1.1 Georgia, "Palatino Linotype", serif; margin: 0 0 4px; }
        .rc { color: #5e6b62; margin: 0; }
        .meta { text-align: right; min-width: 240px; }
        .pill { display: inline-block; border: 1px solid var(--brand); color: var(--brand); border-radius: 999px; padding: 2px 10px; font-size: 12px; letter-spacing: .06em; text-transform: uppercase; }
        .irn { font-family: Consolas, "Courier New", monospace; font-size: 13px; margin: 8px 0 0; word-break: break-all; }
        h2 { font: 600 13px/1 Georgia, serif; letter-spacing: .08em; text-transform: uppercase; color: var(--brand); margin: 22px 0 6px; }
        .who { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th { text-align: left; font-size: 11px; letter-spacing: .06em; text-transform: uppercase; color: #5e6b62; border-bottom: 1px solid #e4dccf; padding: 6px 4px; }
        td { border-bottom: 1px solid #f0ebe3; padding: 8px 4px; vertical-align: top; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .totals { width: 280px; margin-left: auto; margin-top: 12px; }
        .totals div { display: flex; justify-content: space-between; padding: 4px 0; }
        .totals .due { font: 600 20px/1.2 Georgia, serif; border-top: 2px solid var(--brand); margin-top: 4px; padding-top: 8px; }
        .foot { display: flex; justify-content: space-between; gap: 18px; align-items: flex-end; margin-top: 22px; }
        .qr { width: 150px; height: 150px; }
        .qr-miss { max-width: 220px; color: #8c3a32; font-size: 12px; }
        .fine { color: #5e6b62; font-size: 12px; }
        a { color: var(--brand); }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { box-shadow: none; margin: 0; max-width: none; }
        }
        @page { size: A4; margin: 14mm; }
        </style></head><body>'
        . '<div class="toolbar"><button type="button" onclick="window.print()">Print / Save PDF</button>'
        . '<a class="ghost" href="' . e($back) . '">Back to invoice</a></div>'
        . '<article class="sheet">' . $banner
        . '<header class="head"><div>' . $logoHtml . '<h1>' . e($sellerName) . '</h1>'
        . ($sellerAddr !== '' ? '<p class="rc">' . e($sellerAddr) . '</p>' : '')
        . ($rc !== '' ? '<p class="rc">RC ' . e($rc) . '</p>' : '')
        . '<p class="rc">TIN ' . e($sellerTin) . ($sellerEmail !== '' ? ' · ' . e($sellerEmail) : '') . '</p></div>'
        . '<div class="meta"><span class="pill">' . e($pill) . '</span>'
        . '<p class="irn">No. ' . e($invoiceNumber) . '</p>'
        . '<p class="irn">' . e($irn) . '</p>'
        . '<p>' . $when . '</p>'
        . '<p>' . e($kind) . ($payment !== '' ? ' · ' . e(str_replace('_', ' ', $payment)) : '') . '</p></div></header>'
        . '<div class="who"><div><h2>Bill to</h2><strong>' . e($buyerName) . '</strong>'
        . ($buyerAddr !== '' ? '<p class="rc">' . e($buyerAddr) . '</p>' : '')
        . '<p class="rc">TIN ' . e($buyerTin !== '' ? $buyerTin : '—') . ($buyerEmail !== '' ? ' · ' . e($buyerEmail) : '') . '</p></div>'
        . '<div><h2>Note</h2><p>' . e($note !== '' ? $note : '—') . '</p></div></div>'
        . '<table><thead><tr><th>#</th><th>Description</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Amount</th></tr></thead><tbody>'
        . $rows . '</tbody></table>'
        . '<div class="totals"><div><span>Subtotal</span><span>' . e($symbol . number_format($taxEx, 2)) . '</span></div>'
        . '<div><span>VAT ' . e(rtrim(rtrim(number_format($vatRate, 2), '0'), '.')) . '%</span><span>' . e($symbol . number_format($vatAmount, 2)) . '</span></div>'
        . '<div class="due"><span>Payable</span><span>' . e($symbol . number_format($payable, 2)) . '</span></div></div>'
        . '<footer class="foot"><div class="fine"><p>Verify at <a href="https://e-invoice.nrs.gov.ng/verify">e-invoice.nrs.gov.ng/verify</a></p>'
        . '<p>Scan with MBS 360 to verify this IRN.</p>'
        . '<p>Prepared in ' . e($brand) . '.</p></div><div>' . $qrHtml . '</div></footer></article></body></html>';
}
