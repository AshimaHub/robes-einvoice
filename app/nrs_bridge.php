<?php

/**
 * SQLite stand-ins for the Rocad NRS library.
 * Defined before the library loads so its mysqli copies are skipped.
 */

function robes_line_isic(array $li, string $fallback): string
{
    $value = trim((string) ($li['isic_code'] ?? ''));
    if ($value !== '') {
        return $value;
    }
    if ($fallback !== '') {
        return $fallback;
    }
    return '0000';
}

function robes_line_category(array $li, string $fallback): string
{
    $value = trim((string) ($li['service_category'] ?? ''));
    if ($value !== '') {
        return $value;
    }
    if ($fallback !== '') {
        return $fallback;
    }
    return 'General services';
}

function nrs_default_settings(): array
{
    return array(
        'enabled' => '0',
        'provider' => 'nrs_direct',
        'environment' => 'sandbox',
        'base_url' => 'https://eivc-k6z6d.ondigitalocean.app/api/v1',
        'api_key' => '',
        'api_secret' => '',
        'seller_name' => '',
        'seller_tin' => '',
        'seller_email' => '',
        'seller_phone' => '',
        'seller_street' => '',
        'seller_city' => '',
        'seller_postal' => '',
        'seller_state' => '',
        'seller_lga' => '',
        'seller_desc' => 'Goods and services',
        'business_id' => '',
        'service_id' => '',
        'invoice_kind' => 'B2B',
        'invoice_type_code' => '381',
        'default_isic' => '',
        'default_service_category' => '',
        'vat_rate' => '7.5',
        'default_buyer_tin' => '',
        'buyer_email' => '',
        'buyer_phone' => '',
        'buyer_street' => '',
        'buyer_city' => '',
        'buyer_postal' => '',
        'buyer_state' => '',
        'buyer_lga' => '',
        'buyer_desc' => 'Customer',
        'crypto_public_key' => '',
        'crypto_certificate' => '',
        'profile_sandbox' => '',
        'profile_production' => '',
        'brand_name' => 'Robes eInvoice',
        'brand_tagline' => 'E-invoicing for your company',
        'brand_color' => '#1d4ed8',
        'brand_accent' => '#0f766e',
        'invoice_prefix' => 'RB',
        'company_rc' => '',
        'onboarding_done' => '0',
        'onboarding_choice' => '',
    );
}

function nrs_query($db, string $sql)
{
    try {
        $pdo = robe_pdo();
        if (preg_match('/^\s*(SELECT|WITH|PRAGMA)\b/i', $sql)) {
            $stmt = $pdo->query($sql);
            if ($stmt === false) {
                return false;
            }
            $rows = $stmt->fetchAll();
            return new RobeResult(is_array($rows) ? $rows : array());
        }
        $result = $pdo->exec($sql);
        return $result !== false;
    } catch (Throwable $e) {
        $dir = ROBES_ROOT . DIRECTORY_SEPARATOR . 'storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents(
            $dir . DIRECTORY_SEPARATOR . 'error.log',
            date('c') . ' ' . $e->getMessage() . "\n",
            FILE_APPEND
        );
        return false;
    }
}

function nrs_ensure_tables($db): bool
{
    $pdo = robe_pdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS nrs_einvoice_settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        setting_key TEXT NOT NULL UNIQUE,
        setting_value TEXT,
        updated_at TEXT NOT NULL,
        updated_by INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS nrs_einvoice (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        source_type TEXT NOT NULL DEFAULT 'manual',
        source_id INTEGER NOT NULL DEFAULT 0,
        source_reference TEXT NOT NULL DEFAULT '',
        invoice_number TEXT NOT NULL,
        issue_date TEXT NOT NULL,
        currency TEXT NOT NULL DEFAULT 'NGN',
        amount REAL NOT NULL DEFAULT 0,
        vat_amount REAL NOT NULL DEFAULT 0,
        total_amount REAL NOT NULL DEFAULT 0,
        description TEXT NOT NULL DEFAULT '',
        buyer_name TEXT NOT NULL DEFAULT '',
        buyer_tin TEXT NOT NULL DEFAULT '',
        seller_name TEXT NOT NULL DEFAULT '',
        seller_tin TEXT NOT NULL DEFAULT '',
        service_id TEXT NOT NULL DEFAULT '',
        irn TEXT,
        provider_ref TEXT,
        status TEXT NOT NULL DEFAULT 'DRAFT',
        payload_json TEXT,
        last_response_json TEXT,
        last_error TEXT NOT NULL DEFAULT '',
        submitted_at TEXT,
        validated_at TEXT,
        created_at TEXT NOT NULL,
        created_by INTEGER NOT NULL DEFAULT 0,
        updated_at TEXT NOT NULL,
        updated_by INTEGER NOT NULL DEFAULT 0,
        UNIQUE (source_type, source_id)
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_nrs_einvoice_status ON nrs_einvoice(status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_nrs_einvoice_irn ON nrs_einvoice(irn)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_nrs_einvoice_reference ON nrs_einvoice(source_reference)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS nrs_einvoice_attempt (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nrs_einvoice_id INTEGER NOT NULL,
        action TEXT NOT NULL,
        http_status INTEGER NOT NULL DEFAULT 0,
        request_json TEXT,
        response_json TEXT,
        success INTEGER NOT NULL DEFAULT 0,
        error_message TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL,
        created_by INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_nrs_attempt_doc ON nrs_einvoice_attempt(nrs_einvoice_id)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS nrs_tin_party (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tin TEXT NOT NULL,
        tin_key TEXT NOT NULL UNIQUE,
        party_name TEXT NOT NULL DEFAULT '',
        street TEXT NOT NULL DEFAULT '',
        city TEXT NOT NULL DEFAULT '',
        postal TEXT NOT NULL DEFAULT '',
        state TEXT NOT NULL DEFAULT '',
        lga TEXT NOT NULL DEFAULT '',
        email TEXT NOT NULL DEFAULT '',
        phone TEXT NOT NULL DEFAULT '',
        address_line TEXT NOT NULL DEFAULT '',
        source TEXT NOT NULL DEFAULT 'lookup',
        raw_json TEXT,
        updated_at TEXT NOT NULL
    )");
    return true;
}

function nrs_save_settings($db, array $values, int $userId): bool
{
    $allowed = nrs_default_settings();
    $now = nrs_now();
    $stmt = robe_pdo()->prepare(
        'INSERT INTO nrs_einvoice_settings (setting_key, setting_value, updated_at, updated_by)
         VALUES (?, ?, ?, ?)
         ON CONFLICT(setting_key) DO UPDATE SET
            setting_value = excluded.setting_value,
            updated_at = excluded.updated_at,
            updated_by = excluded.updated_by'
    );
    foreach ($values as $key => $value) {
        if (!array_key_exists($key, $allowed)) {
            continue;
        }
        $stmt->execute(array((string) $key, (string) $value, $now, $userId));
    }
    return true;
}

function nrs_tin_party_save($db, string $tin, array $party, string $source = 'lookup'): bool
{
    $tin = trim($tin);
    $key = nrs_normalize_tin_key($tin);
    if ($key === '') {
        return false;
    }
    $name = trim((string) ($party['name'] ?? ($party['party_name'] ?? '')));
    $street = trim((string) ($party['street'] ?? ''));
    $city = trim((string) ($party['city'] ?? ''));
    $postal = trim((string) ($party['postal'] ?? ''));
    $state = trim((string) ($party['state'] ?? ''));
    $lga = trim((string) ($party['lga'] ?? ''));
    $email = trim((string) ($party['email'] ?? ''));
    $phone = trim((string) ($party['phone'] ?? ($party['telephone'] ?? '')));
    $addressLine = trim((string) ($party['address_line'] ?? ''));
    if ($addressLine === '') {
        $addressLine = trim(implode(', ', array_filter(array($street, $city, $state, $lga, $postal))));
    }
    if ($name === '' && $street === '' && $addressLine === '' && $email === '' && $phone === '') {
        return false;
    }
    $raw = isset($party['raw_json']) ? (string) $party['raw_json'] : (string) json_encode($party);
    $now = nrs_now();
    $sql = "INSERT INTO nrs_tin_party
        (tin, tin_key, party_name, street, city, postal, state, lga, email, phone, address_line, source, raw_json, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(tin_key) DO UPDATE SET
            tin = excluded.tin,
            party_name = CASE WHEN excluded.party_name = '' THEN party_name ELSE excluded.party_name END,
            street = CASE WHEN excluded.street = '' THEN street ELSE excluded.street END,
            city = CASE WHEN excluded.city = '' THEN city ELSE excluded.city END,
            postal = CASE WHEN excluded.postal = '' THEN postal ELSE excluded.postal END,
            state = CASE WHEN excluded.state = '' THEN state ELSE excluded.state END,
            lga = CASE WHEN excluded.lga = '' THEN lga ELSE excluded.lga END,
            email = CASE WHEN excluded.email = '' THEN email ELSE excluded.email END,
            phone = CASE WHEN excluded.phone = '' THEN phone ELSE excluded.phone END,
            address_line = CASE WHEN excluded.address_line = '' THEN address_line ELSE excluded.address_line END,
            source = excluded.source,
            raw_json = excluded.raw_json,
            updated_at = excluded.updated_at";
    robe_pdo()->prepare($sql)->execute(array(
        $tin, $key, $name, $street, $city, $postal, $state, $lga, $email, $phone, $addressLine, $source, $raw, $now,
    ));
    return true;
}

function nrs_delete_document($db, int $docId): bool
{
    if ($docId < 1) {
        return false;
    }
    $pdo = robe_pdo();
    $pdo->prepare('DELETE FROM nrs_einvoice_attempt WHERE nrs_einvoice_id = ?')->execute(array($docId));
    $stmt = $pdo->prepare('DELETE FROM nrs_einvoice WHERE id = ?');
    $stmt->execute(array($docId));
    return $stmt->rowCount() > 0;
}
