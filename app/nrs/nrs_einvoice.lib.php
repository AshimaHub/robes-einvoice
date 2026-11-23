<?php
/**
 * NRS e-Invoice protocol for Robes eInvoice.
 * Ported from the Rocad Finance module so a company can clear invoices
 * with NRS without embedding them in another system.
 */

if (!function_exists('nrs_h')) {
    function nrs_h($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('nrs_now')) {
    function nrs_now(): string
    {
        return (new DateTime('now', new DateTimeZone('Africa/Lagos')))->format('Y-m-d H:i:s');
    }
}

if (!function_exists('nrs_query')) {
    function nrs_query($db, string $sql)
    {
        try {
            return mysqli_query($db, $sql);
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('nrs_ensure_tables')) {
    function nrs_ensure_tables($db): bool
    {
        $settings = nrs_query($db, "CREATE TABLE IF NOT EXISTS `nrs_einvoice_settings` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `setting_key` VARCHAR(80) NOT NULL,
            `setting_value` TEXT NULL,
            `updated_at` DATETIME NOT NULL,
            `updated_by` INT NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_nrs_einvoice_settings_key` (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $docs = nrs_query($db, "CREATE TABLE IF NOT EXISTS `nrs_einvoice` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `source_type` VARCHAR(40) NOT NULL DEFAULT 'invoice_payment',
            `source_id` INT NOT NULL DEFAULT 0,
            `source_reference` VARCHAR(80) NOT NULL DEFAULT '',
            `invoice_number` VARCHAR(80) NOT NULL,
            `issue_date` DATE NOT NULL,
            `currency` CHAR(3) NOT NULL DEFAULT 'NGN',
            `amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            `vat_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            `total_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            `description` VARCHAR(500) NOT NULL DEFAULT '',
            `buyer_name` VARCHAR(160) NOT NULL DEFAULT '',
            `buyer_tin` VARCHAR(40) NOT NULL DEFAULT '',
            `seller_name` VARCHAR(160) NOT NULL DEFAULT '',
            `seller_tin` VARCHAR(40) NOT NULL DEFAULT '',
            `service_id` VARCHAR(20) NOT NULL DEFAULT '',
            `irn` VARCHAR(120) NULL,
            `status` VARCHAR(40) NOT NULL DEFAULT 'DRAFT',
            `payload_json` MEDIUMTEXT NULL,
            `last_response_json` MEDIUMTEXT NULL,
            `last_error` VARCHAR(500) NOT NULL DEFAULT '',
            `submitted_at` DATETIME NULL,
            `validated_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL,
            `created_by` INT NOT NULL DEFAULT 0,
            `updated_at` DATETIME NOT NULL,
            `updated_by` INT NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_nrs_einvoice_source` (`source_type`, `source_id`),
            KEY `idx_nrs_einvoice_status` (`status`),
            KEY `idx_nrs_einvoice_irn` (`irn`),
            KEY `idx_nrs_einvoice_reference` (`source_reference`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $attempts = nrs_query($db, "CREATE TABLE IF NOT EXISTS `nrs_einvoice_attempt` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `nrs_einvoice_id` INT NOT NULL,
            `action` VARCHAR(40) NOT NULL,
            `http_status` INT NOT NULL DEFAULT 0,
            `request_json` MEDIUMTEXT NULL,
            `response_json` MEDIUMTEXT NULL,
            `success` TINYINT(1) NOT NULL DEFAULT 0,
            `error_message` VARCHAR(500) NOT NULL DEFAULT '',
            `created_at` DATETIME NOT NULL,
            `created_by` INT NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_nrs_einvoice_attempt_doc` (`nrs_einvoice_id`),
            KEY `idx_nrs_einvoice_attempt_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        if ($docs) {
            $col = nrs_query($db, "SHOW COLUMNS FROM `nrs_einvoice` LIKE 'provider_ref'");
            if ($col && robe_num($col) === 0) {
                nrs_query($db, "ALTER TABLE `nrs_einvoice` ADD COLUMN `provider_ref` VARCHAR(120) NULL AFTER `irn`");
            }
        }

        $tinParty = nrs_query($db, "CREATE TABLE IF NOT EXISTS `nrs_tin_party` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `tin` VARCHAR(40) NOT NULL,
            `tin_key` VARCHAR(40) NOT NULL,
            `party_name` VARCHAR(200) NOT NULL DEFAULT '',
            `street` VARCHAR(255) NOT NULL DEFAULT '',
            `city` VARCHAR(120) NOT NULL DEFAULT '',
            `postal` VARCHAR(40) NOT NULL DEFAULT '',
            `state` VARCHAR(40) NOT NULL DEFAULT '',
            `lga` VARCHAR(80) NOT NULL DEFAULT '',
            `email` VARCHAR(160) NOT NULL DEFAULT '',
            `phone` VARCHAR(60) NOT NULL DEFAULT '',
            `address_line` VARCHAR(500) NOT NULL DEFAULT '',
            `source` VARCHAR(40) NOT NULL DEFAULT 'lookup',
            `raw_json` MEDIUMTEXT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_nrs_tin_party_key` (`tin_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        return (bool) $settings && (bool) $docs && (bool) $attempts && (bool) $tinParty;
    }
}

if (!function_exists('nrs_default_settings')) {
    /**
     * @return array<string, string>
     */
    function nrs_default_settings(): array
    {
        return array(
            'enabled' => '0',
            'provider' => 'byteinvoice',
            'environment' => 'sandbox',
            'base_url' => 'https://rails.byteinvoice.ng/api/v1',
            'api_key' => '',
            'api_secret' => '',
            'seller_name' => 'Rocad Construction Limited',
            'seller_tin' => '',
            'seller_email' => '',
            'seller_phone' => '',
            'seller_street' => '',
            'seller_city' => '',
            'seller_postal' => '',
            'seller_state' => '',
            'seller_lga' => '',
            'seller_desc' => 'Construction and civil engineering services',
            'business_id' => '',
            'service_id' => '',
            'invoice_kind' => 'B2B',
            'invoice_type_code' => '381',
            'default_isic' => '4210',
            'default_service_category' => 'Construction of roads and railways',
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
            // JSON blobs: connection + QR keys per environment (Mode switches which is active).
            'profile_sandbox' => '',
            'profile_production' => '',
        );
    }
}

if (!function_exists('nrs_connection_profile_keys')) {
    /**
     * Settings that differ between sandbox and production.
     *
     * @return array<int, string>
     */
    function nrs_connection_profile_keys(): array
    {
        return array(
            'base_url',
            'api_key',
            'api_secret',
            'business_id',
            'service_id',
            'crypto_public_key',
            'crypto_certificate',
        );
    }
}

if (!function_exists('nrs_default_base_url_for_env')) {
    function nrs_default_base_url_for_env(string $provider, string $environment): string
    {
        if ($provider === 'nrs_direct') {
            return $environment === 'production'
                ? 'https://einvoice1.nrs.gov.ng/api/v1'
                : 'https://eivc-k6z6d.ondigitalocean.app/api/v1';
        }
        return 'https://rails.byteinvoice.ng/api/v1';
    }
}

if (!function_exists('nrs_normalize_base_url')) {
    /**
     * NRS My Account shows host only (e.g. https://einvoice1.nrs.gov.ng).
     * Rocad calls /invoice/validate relative to /api/v1 — append it when missing.
     */
    function nrs_normalize_base_url(string $base, string $provider = 'nrs_direct'): string
    {
        $base = rtrim(trim($base), '/');
        if ($base === '') {
            return $base;
        }
        if ($provider !== 'nrs_direct') {
            return $base;
        }
        if (preg_match('#/api/v1$#i', $base)) {
            return $base;
        }
        // Portal "Base URL" / "Report Base URL" hosts without the API suffix.
        if (preg_match('#^https?://[^/]+$#i', $base)) {
            return $base . '/api/v1';
        }
        return $base;
    }
}

if (!function_exists('nrs_empty_connection_profile')) {
    /**
     * @return array<string, string>
     */
    function nrs_empty_connection_profile(string $provider, string $environment): array
    {
        $out = array();
        foreach (nrs_connection_profile_keys() as $key) {
            $out[$key] = $key === 'base_url'
                ? nrs_default_base_url_for_env($provider, $environment)
                : '';
        }
        return $out;
    }
}

if (!function_exists('nrs_decode_connection_profile')) {
    /**
     * @return array<string, string>
     */
    function nrs_decode_connection_profile(string $json, string $provider, string $environment): array
    {
        $base = nrs_empty_connection_profile($provider, $environment);
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return $base;
        }
        foreach (nrs_connection_profile_keys() as $key) {
            if (array_key_exists($key, $decoded)) {
                $base[$key] = trim((string) $decoded[$key]);
            }
        }
        $base['base_url'] = nrs_normalize_base_url((string) ($base['base_url'] ?? ''), $provider);
        if (trim($base['base_url']) === '') {
            $base['base_url'] = nrs_default_base_url_for_env($provider, $environment);
        }
        return $base;
    }
}

if (!function_exists('nrs_encode_connection_profile')) {
    /**
     * @param array<string, string> $profile
     */
    function nrs_encode_connection_profile(array $profile): string
    {
        $out = array();
        foreach (nrs_connection_profile_keys() as $key) {
            $out[$key] = trim((string) ($profile[$key] ?? ''));
        }
        return (string) json_encode($out, JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('nrs_extract_connection_profile')) {
    /**
     * @param array<string, string> $settings
     * @return array<string, string>
     */
    function nrs_extract_connection_profile(array $settings): array
    {
        $out = array();
        foreach (nrs_connection_profile_keys() as $key) {
            $out[$key] = trim((string) ($settings[$key] ?? ''));
        }
        return $out;
    }
}

if (!function_exists('nrs_apply_connection_profile')) {
    /**
     * @param array<string, string> $settings
     * @param array<string, string> $profile
     * @return array<string, string>
     */
    function nrs_apply_connection_profile(array $settings, array $profile): array
    {
        foreach (nrs_connection_profile_keys() as $key) {
            $settings[$key] = trim((string) ($profile[$key] ?? ''));
        }
        return $settings;
    }
}

if (!function_exists('nrs_provider')) {
    function nrs_provider(array $settings): string
    {
        $p = strtolower(trim((string) ($settings['provider'] ?? 'byteinvoice')));
        return $p === 'nrs_direct' ? 'nrs_direct' : 'byteinvoice';
    }
}

if (!function_exists('nrs_provider_label')) {
    function nrs_provider_label(string $provider): string
    {
        return $provider === 'nrs_direct' ? 'NRS (MBS Direct)' : 'ByteInvoice (APP)';
    }
}

if (!function_exists('nrs_default_base_url')) {
    function nrs_default_base_url(string $provider): string
    {
        return nrs_default_base_url_for_env($provider, 'production');
    }
}

if (!function_exists('nrs_get_settings')) {
    /**
     * @return array<string, string>
     */
    function nrs_get_settings($db): array
    {
        $settings = nrs_default_settings();
        $res = nrs_query($db, 'SELECT setting_key, setting_value FROM nrs_einvoice_settings');
        if ($res) {
            while ($row = robe_fetch($res)) {
                $key = (string) ($row['setting_key'] ?? '');
                if ($key !== '' && array_key_exists($key, $settings)) {
                    $settings[$key] = (string) ($row['setting_value'] ?? '');
                }
            }
        }
        if (($settings['provider'] ?? '') !== 'nrs_direct') {
            $settings['provider'] = 'nrs_direct';
        }
        $provider = nrs_provider($settings);
        $environment = trim((string) ($settings['environment'] ?? 'sandbox')) === 'production' ? 'production' : 'sandbox';
        $settings['environment'] = $environment;

        $sandbox = nrs_decode_connection_profile((string) ($settings['profile_sandbox'] ?? ''), $provider, 'sandbox');
        $production = nrs_decode_connection_profile((string) ($settings['profile_production'] ?? ''), $provider, 'production');

        // One-time migrate: existing flat connection values become the sandbox profile.
        $flat = nrs_extract_connection_profile($settings);
        $sandboxEmpty = trim((string) ($settings['profile_sandbox'] ?? '')) === '';
        $productionEmpty = trim((string) ($settings['profile_production'] ?? '')) === '';
        if ($sandboxEmpty) {
            $hasFlat = false;
            foreach ($flat as $v) {
                if (trim((string) $v) !== '') {
                    $hasFlat = true;
                    break;
                }
            }
            if ($hasFlat) {
                $sandbox = array_merge($sandbox, $flat);
                if (trim($sandbox['base_url']) === '') {
                    $sandbox['base_url'] = nrs_default_base_url_for_env($provider, 'sandbox');
                }
            }
        }
        if ($productionEmpty && trim($production['base_url']) === '') {
            $production['base_url'] = nrs_default_base_url_for_env($provider, 'production');
        }

        $settings['profile_sandbox'] = nrs_encode_connection_profile($sandbox);
        $settings['profile_production'] = nrs_encode_connection_profile($production);
        $active = $environment === 'production' ? $production : $sandbox;
        $settings = nrs_apply_connection_profile($settings, $active);

        $base = trim((string) ($settings['base_url'] ?? ''));
        if ($base === '') {
            $settings['base_url'] = nrs_default_base_url_for_env($provider, $environment);
        }
        return $settings;
    }
}

if (!function_exists('nrs_settings_env_profiles')) {
    /**
     * @param array<string, string> $settings
     * @return array{sandbox:array<string,string>,production:array<string,string>}
     */
    function nrs_settings_env_profiles(array $settings): array
    {
        $provider = nrs_provider($settings);
        return array(
            'sandbox' => nrs_decode_connection_profile((string) ($settings['profile_sandbox'] ?? ''), $provider, 'sandbox'),
            'production' => nrs_decode_connection_profile((string) ($settings['profile_production'] ?? ''), $provider, 'production'),
        );
    }
}

if (!function_exists('nrs_save_settings')) {
    /**
     * @param array<string, string> $values
     */
    function nrs_save_settings($db, array $values, int $userId): bool
    {
        $allowed = nrs_default_settings();
        $now = nrs_now();
        foreach ($values as $key => $value) {
            if (!array_key_exists($key, $allowed)) {
                continue;
            }
            $keySql = robe_esc($db, (string) $key);
            $valSql = robe_esc($db, (string) $value);
            $ok = nrs_query($db, "
                INSERT INTO nrs_einvoice_settings (setting_key, setting_value, updated_at, updated_by)
                VALUES ('$keySql', '$valSql', '$now', $userId)
                ON DUPLICATE KEY UPDATE
                    setting_value = VALUES(setting_value),
                    updated_at = VALUES(updated_at),
                    updated_by = VALUES(updated_by)
            ");
            if (!$ok) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('nrs_status_label')) {
    function nrs_status_label(string $status): string
    {
        $map = array(
            'DRAFT' => 'Draft',
            'READY' => 'Ready',
            'QUEUED' => 'Queued',
            'VALIDATING' => 'Validating',
            'VALIDATED' => 'Validated',
            'SIGNED' => 'Signed',
            'TRANSMITTED' => 'Transmitted',
            'SUBMITTING' => 'Submitting',
            'SUBMITTED' => 'Submitted',
            'CONFIRMED' => 'Confirmed',
            'REJECTED' => 'Rejected',
            'FAILED' => 'Failed',
        );
        return $map[strtoupper($status)] ?? $status;
    }
}

if (!function_exists('nrs_map_provider_status')) {
    function nrs_map_provider_status(string $providerStatus): string
    {
        $s = strtoupper(trim($providerStatus));
        $map = array(
            'QUEUED' => 'QUEUED',
            'VALIDATED' => 'VALIDATED',
            'SIGNED' => 'SIGNED',
            'TRANSMITTED' => 'TRANSMITTED',
            'CONFIRMED' => 'CONFIRMED',
            'REJECTED' => 'REJECTED',
            'FAILED' => 'FAILED',
            'SUBMITTED' => 'SUBMITTED',
        );
        return $map[$s] ?? $s;
    }
}

if (!function_exists('nrs_build_irn')) {
    function nrs_build_irn(string $invoiceNumber, string $serviceId, string $issueDate): string
    {
        // InvoiceNumber segment must be alphanumeric only (NRS IRN rules).
        $inv = preg_replace('/[^A-Za-z0-9]+/', '', strtoupper(trim($invoiceNumber)));
        $sid = preg_replace('/[^A-Za-z0-9]+/', '', strtoupper(trim($serviceId)));
        $date = preg_replace('/[^0-9]/', '', $issueDate);
        if (strlen($date) >= 8) {
            $date = substr($date, 0, 8);
        } else {
            $date = date('Ymd');
        }
        if ($inv === '') {
            $inv = 'INV';
        }
        if ($sid === '') {
            $sid = 'SERVICEID';
        }
        return $inv . '-' . $sid . '-' . $date;
    }
}

if (!function_exists('nrs_next_retry_invoice_number')) {
    /**
     * Bump invoice number so NRS gets a fresh IRN after a failed/duplicate attempt.
     * Example: ROC6578269089 → ROC6578269089R2 → ROC6578269089R3
     */
    function nrs_next_retry_invoice_number(string $invoiceNumber): string
    {
        $base = preg_replace('/[^A-Za-z0-9]+/', '', strtoupper(trim($invoiceNumber)));
        if ($base === '') {
            $base = 'ROC' . date('YmdHis');
        }
        if (preg_match('/^(.*?)R(\d+)$/', $base, $m)) {
            $stem = $m[1] !== '' ? $m[1] : $base;
            $n = ((int) $m[2]) + 1;
            return $stem . 'R' . $n;
        }
        return $base . 'R2';
    }
}

if (!function_exists('nrs_error_looks_like_duplicate_irn')) {
    function nrs_error_looks_like_duplicate_irn(string $message): bool
    {
        $m = strtolower($message);
        return strpos($m, 'duplicate') !== false
            || strpos($m, 'irn validation failed') !== false;
    }
}

if (!function_exists('nrs_fetch_paid_invoice')) {
    /**
     * @return array<string, mixed>|null
     */
    function nrs_fetch_paid_invoice($db, int $invoiceId): ?array
    {
        if ($invoiceId < 1) {
            return null;
        }
        $sql = "
            SELECT
                i.id,
                i.reference,
                i.lpo,
                i.PlantNo,
                i.title,
                i.infoD,
                i.ispaid,
                i.uploaded_on,
                s.supl,
                s.pay_to,
                s.bank_name,
                s.reqfor,
                s.totalvalue,
                s.invoice AS store_invoice
            FROM invoices i
            LEFT JOIN storeloadingdetails s ON s.reference = i.reference
            WHERE i.id = $invoiceId
              AND i.ispaid = 1
              AND i.reference > 0
            LIMIT 1
        ";
        $res = nrs_query($db, $sql);
        if (!$res) {
            return null;
        }
        $row = robe_fetch($res);
        return $row ?: null;
    }
}

if (!function_exists('nrs_list_paid_invoices')) {
    /**
     * Paid ROCAD invoices not yet linked to an NRS draft/submission.
     *
     * @return array<int, array<string, mixed>>
     */
    function nrs_list_paid_invoices($db, int $limit = 100): array
    {
        $limit = max(1, min(300, $limit));
        $sql = "
            SELECT
                i.id,
                i.reference,
                i.lpo,
                i.PlantNo,
                i.title,
                i.uploaded_on,
                s.supl,
                s.reqfor,
                s.totalvalue,
                s.bank_name,
                s.pay_to
            FROM invoices i
            LEFT JOIN storeloadingdetails s ON s.reference = i.reference
            LEFT JOIN nrs_einvoice n
                ON n.source_type = 'invoice_payment' AND n.source_id = i.id
            WHERE i.ispaid = 1
              AND i.reference > 0
              AND n.id IS NULL
            ORDER BY i.id DESC
            LIMIT $limit
        ";
        $rows = array();
        $res = nrs_query($db, $sql);
        if ($res) {
            while ($row = robe_fetch($res)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }
}

if (!function_exists('nrs_map_payment_to_payload')) {
    /**
     * Build a UBL-inspired JSON payload from a ROCAD paid invoice + settings.
     * Exact NRS schema may differ by account; this is the ROCAD canonical draft.
     *
     * @param array<string, mixed> $payment
     * @param array<string, string> $settings
     * @param array<string, string> $overrides
     * @return array<string, mixed>
     */
    function nrs_map_payment_to_payload(array $payment, array $settings, array $overrides = array()): array
    {
        $amount = round((float) ($payment['totalvalue'] ?? 0), 2);
        $vatRate = isset($overrides['vat_rate']) && is_numeric($overrides['vat_rate'])
            ? (float) $overrides['vat_rate']
            : (float) ($settings['vat_rate'] ?? 7.5);
        if ($vatRate < 0) {
            $vatRate = 0;
        }
        $invoiceKind = strtoupper(trim((string) ($overrides['invoice_kind'] ?? ($settings['invoice_kind'] ?? 'B2B'))));
        if (!in_array($invoiceKind, array('B2B', 'B2C', 'B2G', 'G2B'), true)) {
            $invoiceKind = 'B2B';
        }
        $paymentStatus = nrs_normalize_payment_status((string) ($overrides['payment_status'] ?? 'PAID'), 'PAID');
        $vatInclusive = isset($overrides['vat_inclusive']) ? ((int) $overrides['vat_inclusive'] === 1) : true;
        if ($vatInclusive && $vatRate > 0) {
            $net = round($amount / (1 + ($vatRate / 100)), 2);
            $vat = round($amount - $net, 2);
            $total = $amount;
        } else {
            $net = $amount;
            $vat = round($amount * ($vatRate / 100), 2);
            $total = round($net + $vat, 2);
        }

        $issueDate = !empty($overrides['issue_date'])
            ? (string) $overrides['issue_date']
            : date('Y-m-d', strtotime((string) ($payment['uploaded_on'] ?? 'now')));
        $invoiceNumber = trim((string) ($overrides['invoice_number'] ?? ''));
        if ($invoiceNumber === '') {
            $ref = preg_replace('/[^A-Za-z0-9]+/', '', strtoupper(trim((string) ($payment['reference'] ?? ''))));
            $invoiceNumber = $ref !== '' ? ('ROC' . $ref) : ('ROC' . (int) ($payment['id'] ?? 0));
        }
        $serviceId = trim((string) ($overrides['service_id'] ?? ($settings['service_id'] ?? '')));
        $sellerName = trim((string) ($overrides['seller_name'] ?? ($settings['seller_name'] ?? '')));
        $sellerTin = trim((string) ($overrides['seller_tin'] ?? ($settings['seller_tin'] ?? '')));
        $buyerName = trim((string) ($overrides['buyer_name'] ?? ($payment['supl'] ?? '')));
        $buyerTin = trim((string) ($overrides['buyer_tin'] ?? ($settings['default_buyer_tin'] ?? '')));
        $description = trim((string) ($overrides['description'] ?? ($payment['reqfor'] ?? ($payment['title'] ?? 'Payment'))));
        if ($description === '') {
            $description = 'Service';
        }
        $irn = nrs_build_irn($invoiceNumber, $serviceId, $issueDate);

        $sellerParty = array(
            'name' => $sellerName,
            'tin' => $sellerTin,
            'street' => trim((string) ($overrides['seller_street'] ?? '')),
            'city' => trim((string) ($overrides['seller_city'] ?? '')),
            'postal' => trim((string) ($overrides['seller_postal'] ?? '')),
            'state' => trim((string) ($overrides['seller_state'] ?? '')),
            'lga' => trim((string) ($overrides['seller_lga'] ?? '')),
            'email' => trim((string) ($overrides['seller_email'] ?? '')),
            'address_line' => trim((string) ($overrides['seller_address'] ?? '')),
        );
        $buyerParty = array(
            'name' => $buyerName,
            'tin' => $buyerTin,
            'street' => trim((string) ($overrides['buyer_street'] ?? '')),
            'city' => trim((string) ($overrides['buyer_city'] ?? '')),
            'postal' => trim((string) ($overrides['buyer_postal'] ?? '')),
            'state' => trim((string) ($overrides['buyer_state'] ?? '')),
            'lga' => trim((string) ($overrides['buyer_lga'] ?? '')),
            'email' => trim((string) ($overrides['buyer_email'] ?? '')),
            'address_line' => trim((string) ($overrides['buyer_address'] ?? '')),
            'account_number' => (string) ($payment['pay_to'] ?? ''),
            'bank_name' => (string) ($payment['bank_name'] ?? ''),
        );

        return array(
            'invoice_number' => $invoiceNumber,
            'issue_date' => $issueDate,
            'currency' => 'NGN',
            'irn' => $irn,
            'service_id' => $serviceId,
            'description' => $description,
            'seller' => $sellerParty,
            'buyer' => $buyerParty,
            'line_items' => array(
                array(
                    'description' => $description,
                    'quantity' => 1,
                    'unit_price' => $net,
                    'line_total' => $net,
                    'tax_rate' => $vatRate,
                    'tax_amount' => $vat,
                ),
            ),
            'invoice_kind' => $invoiceKind,
            'payment_status' => $paymentStatus,
            'amounts' => array(
                'subtotal' => $net,
                'vat_amount' => $vat,
                'total' => $total,
                'vat_rate' => $vatRate,
                'vat_inclusive_source' => $vatInclusive,
            ),
            'rocad' => array(
                'source_type' => 'invoice_payment',
                'source_id' => (int) ($payment['id'] ?? 0),
                'source_reference' => (string) ($payment['reference'] ?? ''),
                'plant_no' => (string) ($payment['PlantNo'] ?? ''),
                'lpo' => (string) ($payment['lpo'] ?? ''),
                'title' => (string) ($payment['title'] ?? ''),
            ),
        );
    }
}

if (!function_exists('nrs_local_validate_payload')) {
    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $settings
     * @return array{ok:bool,errors:array<int,string>}
     */
    function nrs_local_validate_payload(array $payload, array $settings = array()): array
    {
        $errors = array();
        $provider = nrs_provider($settings ?: nrs_default_settings());
        if (trim((string) ($payload['invoice_number'] ?? '')) === '') {
            $errors[] = 'Invoice number is required.';
        }
        if (trim((string) ($payload['issue_date'] ?? '')) === '') {
            $errors[] = 'Issue date is required.';
        }
        if (trim((string) ($payload['seller']['tin'] ?? '')) === '') {
            $errors[] = 'Seller TIN is required (set it in Settings).';
        }
        if (trim((string) ($payload['seller']['name'] ?? '')) === '') {
            $errors[] = 'Seller name is required.';
        }
        if (trim((string) ($payload['buyer']['name'] ?? '')) === '') {
            $errors[] = 'Buyer / beneficiary name is required.';
        }
        if ($provider === 'nrs_direct') {
            if (trim((string) ($payload['service_id'] ?? '')) === '' || strtoupper((string) $payload['service_id']) === 'SERVICEID') {
                $errors[] = 'NRS Service ID is required (8-character ID from your NRS dashboard).';
            }
            if (trim((string) ($settings['business_id'] ?? '')) === '') {
                $errors[] = 'NRS Business ID is required (UUID from your NRS onboarding / dashboard).';
            }
            if (trim((string) ($settings['seller_email'] ?? '')) === '') {
                $errors[] = 'Seller email is required for NRS direct (set it in Settings).';
            }
            if (trim((string) ($settings['seller_street'] ?? '')) === '' || trim((string) ($settings['seller_city'] ?? '')) === '') {
                $errors[] = 'Seller street and city are required for NRS direct.';
            }
            if (trim((string) ($settings['seller_state'] ?? '')) === '' || trim((string) ($settings['seller_lga'] ?? '')) === '') {
                $errors[] = 'Seller state and LGA codes are required (e.g. NG-FC / NG-FC-AML).';
            }
            $kind = strtoupper(trim((string) ($payload['invoice_kind'] ?? ($settings['invoice_kind'] ?? 'B2B'))));
            if (in_array($kind, array('B2B', 'B2G', 'G2B'), true) && trim((string) ($payload['buyer']['tin'] ?? '')) === '') {
                $errors[] = 'Buyer TIN is required for ' . $kind . ' invoices (set Default buyer TIN or edit the draft).';
            }
            $buyerEmail = trim((string) ($payload['buyer']['email'] ?? ''));
            if ($buyerEmail === '' || !filter_var($buyerEmail, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Buyer email is required and must be a valid email address (NRS).';
            }
            $sellerEmail = trim((string) ($payload['seller']['email'] ?? ($settings['seller_email'] ?? '')));
            if ($sellerEmail === '' || !filter_var($sellerEmail, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Seller email is required and must be a valid email address (NRS).';
            }
        }
        $total = (float) ($payload['amounts']['total'] ?? 0);
        if ($total <= 0) {
            $errors[] = 'Total amount must be greater than zero.';
        }
        return array('ok' => $errors === array(), 'errors' => $errors);
    }
}

if (!function_exists('nrs_payment_status_options')) {
    /**
     * @return array<int, string>
     */
    function nrs_payment_status_options(): array
    {
        return array('PAID', 'UNPAID', 'PARTIALLY_PAID');
    }
}

if (!function_exists('nrs_normalize_payment_status')) {
    function nrs_normalize_payment_status(string $status, string $default = 'PAID'): string
    {
        $status = strtoupper(trim(str_replace(array(' ', '-'), '_', $status)));
        if ($status === 'PARTIAL' || $status === 'PARTIALLYPAID') {
            $status = 'PARTIALLY_PAID';
        }
        if (!in_array($status, nrs_payment_status_options(), true)) {
            $default = strtoupper(trim($default));
            return in_array($default, nrs_payment_status_options(), true) ? $default : 'PAID';
        }
        return $status;
    }
}

if (!function_exists('nrs_normalize_tin_key')) {
    function nrs_normalize_tin_key(string $tin): string
    {
        $digits = preg_replace('/\D+/', '', $tin);
        return is_string($digits) ? $digits : '';
    }
}

if (!function_exists('nrs_tin_party_get')) {
    /**
     * @return array<string, string>|null
     */
    function nrs_tin_party_get($db, string $tin): ?array
    {
        $key = nrs_normalize_tin_key($tin);
        if ($key === '') {
            return null;
        }
        $keySql = robe_esc($db, $key);
        $res = nrs_query($db, "SELECT * FROM nrs_tin_party WHERE tin_key='$keySql' LIMIT 1");
        if (!$res) {
            return null;
        }
        $row = robe_fetch($res);
        return $row ?: null;
    }
}

if (!function_exists('nrs_tin_party_save')) {
    /**
     * @param array<string, mixed> $party
     */
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
        $esc = static function ($v) use ($db) {
            return robe_esc($db, (string) $v);
        };
        $raw = isset($party['raw_json']) ? (string) $party['raw_json'] : (string) json_encode($party);
        $now = nrs_now();
        return (bool) nrs_query($db, "
            INSERT INTO nrs_tin_party
                (tin, tin_key, party_name, street, city, postal, state, lga, email, phone, address_line, source, raw_json, updated_at)
            VALUES (
                '" . $esc($tin) . "', '" . $esc($key) . "', '" . $esc($name) . "', '" . $esc($street) . "',
                '" . $esc($city) . "', '" . $esc($postal) . "', '" . $esc($state) . "', '" . $esc($lga) . "',
                '" . $esc($email) . "', '" . $esc($phone) . "', '" . $esc($addressLine) . "',
                '" . $esc($source) . "', '" . $esc($raw) . "', '$now'
            )
            ON DUPLICATE KEY UPDATE
                tin=VALUES(tin),
                party_name=IF(VALUES(party_name)='', party_name, VALUES(party_name)),
                street=IF(VALUES(street)='', street, VALUES(street)),
                city=IF(VALUES(city)='', city, VALUES(city)),
                postal=IF(VALUES(postal)='', postal, VALUES(postal)),
                state=IF(VALUES(state)='', state, VALUES(state)),
                lga=IF(VALUES(lga)='', lga, VALUES(lga)),
                email=IF(VALUES(email)='', email, VALUES(email)),
                phone=IF(VALUES(phone)='', phone, VALUES(phone)),
                address_line=IF(VALUES(address_line)='', address_line, VALUES(address_line)),
                source=VALUES(source),
                raw_json=VALUES(raw_json),
                updated_at=VALUES(updated_at)
        ");
    }
}

if (!function_exists('nrs_tin_party_from_settings')) {
    /**
     * Only when the TIN matches the configured seller/default buyer TIN.
     *
     * @param array<string, string> $settings
     * @return array<string, string>
     */
    function nrs_tin_party_from_settings(array $settings, string $role, string $tin): array
    {
        $prefix = $role === 'buyer' ? 'buyer_' : 'seller_';
        $settingsTin = $role === 'buyer'
            ? trim((string) ($settings['default_buyer_tin'] ?? ''))
            : trim((string) ($settings['seller_tin'] ?? ''));
        $tinKey = nrs_normalize_tin_key($tin);
        $settingsKey = nrs_normalize_tin_key($settingsTin);
        if ($tinKey === '' || $settingsKey === '' || $tinKey !== $settingsKey) {
            return array();
        }
        $name = $role === 'buyer' ? '' : trim((string) ($settings['seller_name'] ?? ''));
        return array_filter(array(
            'name' => $name,
            'street' => trim((string) ($settings[$prefix . 'street'] ?? '')),
            'city' => trim((string) ($settings[$prefix . 'city'] ?? '')),
            'postal' => trim((string) ($settings[$prefix . 'postal'] ?? '')),
            'state' => trim((string) ($settings[$prefix . 'state'] ?? '')),
            'lga' => trim((string) ($settings[$prefix . 'lga'] ?? '')),
            'email' => trim((string) ($settings[$prefix . 'email'] ?? '')),
            'phone' => trim((string) ($settings[$prefix . 'phone'] ?? '')),
        ), static function ($v) {
            return $v !== '';
        });
    }
}

if (!function_exists('nrs_tin_party_from_history')) {
    /**
     * @return array<string, string>
     */
    function nrs_tin_party_from_history($db, string $tin): array
    {
        $tin = trim($tin);
        if ($tin === '') {
            return array();
        }
        $tinSql = robe_esc($db, $tin);
        $key = nrs_normalize_tin_key($tin);
        $keySql = robe_esc($db, $key);
        $res = nrs_query($db, "
            SELECT buyer_name, buyer_tin, seller_name, seller_tin, payload_json
            FROM nrs_einvoice
            WHERE buyer_tin='$tinSql' OR seller_tin='$tinSql'
               OR REPLACE(REPLACE(buyer_tin,'-',''),' ','')='$keySql'
               OR REPLACE(REPLACE(seller_tin,'-',''),' ','')='$keySql'
            ORDER BY id DESC
            LIMIT 12
        ");
        if (!$res) {
            return array();
        }
        while ($row = robe_fetch($res)) {
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            if (!is_array($payload)) {
                continue;
            }
            foreach (array('buyer', 'seller') as $side) {
                $party = isset($payload[$side]) && is_array($payload[$side]) ? $payload[$side] : array();
                $partyTin = trim((string) ($party['tin'] ?? ''));
                if ($partyTin === '' || nrs_normalize_tin_key($partyTin) !== $key) {
                    continue;
                }
                $out = array_filter(array(
                    'name' => trim((string) ($party['name'] ?? ($side === 'buyer' ? ($row['buyer_name'] ?? '') : ($row['seller_name'] ?? '')))),
                    'street' => trim((string) ($party['street'] ?? '')),
                    'city' => trim((string) ($party['city'] ?? '')),
                    'postal' => trim((string) ($party['postal'] ?? '')),
                    'state' => trim((string) ($party['state'] ?? '')),
                    'lga' => trim((string) ($party['lga'] ?? '')),
                    'email' => trim((string) ($party['email'] ?? '')),
                    'phone' => trim((string) ($party['phone'] ?? ($party['telephone'] ?? ''))),
                    'address_line' => trim((string) ($party['address_line'] ?? '')),
                ), static function ($v) {
                    return $v !== '';
                });
                if ($out) {
                    return $out;
                }
            }
        }
        return array();
    }
}

if (!function_exists('nrs_parse_lookup_party')) {
    /**
     * @param mixed $data
     * @return array<string, string>
     */
    function nrs_parse_lookup_party($data): array
    {
        if (!is_array($data)) {
            return array();
        }
        // Unwrap common envelopes.
        foreach (array('data', 'result', 'payload', 'taxpayer', 'tax_payer', 'party', 'business', 'entity') as $wrap) {
            if (isset($data[$wrap]) && is_array($data[$wrap]) && !isset($data['tin']) && !isset($data['party_name'])) {
                $data = $data[$wrap];
                break;
            }
        }
        if (isset($data[0]) && is_array($data[0]) && !isset($data['tin'])) {
            $data = $data[0];
        }
        if (isset($data['businesses'][0]) && is_array($data['businesses'][0])) {
            $biz = $data['businesses'][0];
            $data = array_merge($data, $biz);
        }
        $addr = array();
        if (isset($data['postal_address']) && is_array($data['postal_address'])) {
            $addr = $data['postal_address'];
        } elseif (isset($data['address']) && is_array($data['address'])) {
            $addr = $data['address'];
        }
        $addressLine = '';
        if (isset($data['address']) && is_string($data['address'])) {
            $addressLine = trim($data['address']);
        } elseif (isset($data['address_line']) && is_string($data['address_line'])) {
            $addressLine = trim($data['address_line']);
        }
        $street = trim((string) ($addr['street_name'] ?? ($addr['street'] ?? ($data['street'] ?? ($data['street_name'] ?? '')))));
        $city = trim((string) ($addr['city_name'] ?? ($addr['city'] ?? ($data['city'] ?? ($data['city_name'] ?? '')))));
        $postal = trim((string) ($addr['postal_zone'] ?? ($addr['postal'] ?? ($data['postal'] ?? ($data['postal_zone'] ?? '')))));
        $state = trim((string) ($addr['state'] ?? ($data['state'] ?? '')));
        $lga = trim((string) ($addr['lga'] ?? ($data['lga'] ?? '')));
        if ($street === '' && $addressLine !== '') {
            $street = $addressLine;
        }
        $name = trim((string) (
            $data['party_name']
            ?? ($data['tax_payer_name'] ?? ($data['taxpayer_name'] ?? ($data['company_name'] ?? ($data['business_name'] ?? ($data['name'] ?? '')))))
        ));
        $email = trim((string) ($data['email'] ?? ($data['email_address'] ?? '')));
        $phone = trim((string) ($data['telephone'] ?? ($data['phone'] ?? ($data['phone_number'] ?? ''))));
        return array_filter(array(
            'name' => $name,
            'street' => $street,
            'city' => $city,
            'postal' => $postal,
            'state' => $state,
            'lga' => $lga,
            'email' => $email,
            'phone' => $phone,
            'address_line' => $addressLine !== '' ? $addressLine : trim(implode(', ', array_filter(array($street, $city, $state)))),
        ), static function ($v) {
            return $v !== '';
        });
    }
}

if (!function_exists('nrs_lookup_tin_party')) {
    /**
     * Resolve party details for a TIN: cache → history → settings (TIN match) → NRS API.
     *
     * @param array<string, string> $settings
     * @return array{ok:bool,party:array<string,string>,source:string,error:string}
     */
    function nrs_lookup_tin_party($db, array $settings, string $tin, string $role = 'buyer'): array
    {
        $tin = trim($tin);
        if ($tin === '' || nrs_normalize_tin_key($tin) === '') {
            return array('ok' => false, 'party' => array(), 'source' => '', 'error' => 'Enter a TIN first.');
        }

        $cached = nrs_tin_party_get($db, $tin);
        if ($cached) {
            $party = array_filter(array(
                'name' => (string) ($cached['party_name'] ?? ''),
                'street' => (string) ($cached['street'] ?? ''),
                'city' => (string) ($cached['city'] ?? ''),
                'postal' => (string) ($cached['postal'] ?? ''),
                'state' => (string) ($cached['state'] ?? ''),
                'lga' => (string) ($cached['lga'] ?? ''),
                'email' => (string) ($cached['email'] ?? ''),
                'phone' => (string) ($cached['phone'] ?? ''),
                'address_line' => (string) ($cached['address_line'] ?? ''),
            ), static function ($v) {
                return trim((string) $v) !== '';
            });
            if ($party) {
                return array('ok' => true, 'party' => $party, 'source' => (string) ($cached['source'] ?? 'cache'), 'error' => '');
            }
        }

        $fromHistory = nrs_tin_party_from_history($db, $tin);
        if ($fromHistory) {
            nrs_tin_party_save($db, $tin, $fromHistory, 'history');
            return array('ok' => true, 'party' => $fromHistory, 'source' => 'history', 'error' => '');
        }

        $fromSettings = nrs_tin_party_from_settings($settings, $role, $tin);
        if ($fromSettings) {
            nrs_tin_party_save($db, $tin, $fromSettings, 'settings');
            return array('ok' => true, 'party' => $fromSettings, 'source' => 'settings', 'error' => '');
        }

        $lookupSettings = $settings;
        $lookupSettings['enabled'] = '1';
        $tinPath = rawurlencode($tin);
        $paths = array(
            '/invoice/transmit/lookup/tin/' . $tinPath,
            '/invoice/lookup/tin/' . $tinPath,
            '/taxpayer/' . $tinPath,
            '/taxpayers/' . $tinPath,
        );
        $lastErr = 'NRS did not return party details for this TIN.';
        foreach ($paths as $path) {
            $response = nrs_request($lookupSettings, 'GET', $path, null);
            if (!$response['ok']) {
                $lastErr = nrs_response_error_message($response);
                // Missing route → try next path.
                if ((int) $response['http_status'] === 404) {
                    continue;
                }
                // Auth/config errors: stop early.
                if ((int) $response['http_status'] === 0 || (int) $response['http_status'] === 401 || (int) $response['http_status'] === 403) {
                    break;
                }
                continue;
            }
            $data = nrs_response_data($response);
            $party = nrs_parse_lookup_party($data);
            if (!$party && is_array($response['json'])) {
                $party = nrs_parse_lookup_party($response['json']);
            }
            if ($party) {
                $party['raw_json'] = (string) json_encode($response['json']);
                nrs_tin_party_save($db, $tin, $party, 'nrs');
                unset($party['raw_json']);
                return array('ok' => true, 'party' => $party, 'source' => 'nrs', 'error' => '');
            }
            $lastErr = 'NRS responded for this TIN but did not include name/address fields.';
        }

        return array('ok' => false, 'party' => array(), 'source' => '', 'error' => $lastErr);
    }
}

if (!function_exists('nrs_merge_party_details')) {
    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    function nrs_merge_party_details(array $base, array $extra): array
    {
        foreach (array('name', 'tin', 'street', 'city', 'postal', 'state', 'lga', 'email', 'address_line') as $k) {
            $incoming = trim((string) ($extra[$k] ?? ''));
            if ($incoming === '') {
                continue;
            }
            if (trim((string) ($base[$k] ?? '')) === '') {
                $base[$k] = $incoming;
            }
        }
        // Never keep phone on invoice parties.
        unset($base['phone'], $base['telephone']);
        return $base;
    }
}

if (!function_exists('nrs_enrich_payload_parties')) {
    /**
     * Attach address fields onto payload seller/buyer using overrides, cache, history, settings, NRS.
     *
     * @param array<string, mixed> $payload
     * @param array<string, string> $settings
     * @param array<string, string> $overrides
     * @return array<string, mixed>
     */
    function nrs_enrich_payload_parties($db, array $payload, array $settings, array $overrides = array()): array
    {
        foreach (array('seller', 'buyer') as $role) {
            $party = isset($payload[$role]) && is_array($payload[$role]) ? $payload[$role] : array();
            $prefix = $role . '_';
            $fromForm = array(
                'name' => trim((string) ($overrides[$prefix . 'name'] ?? ($party['name'] ?? ''))),
                'tin' => trim((string) ($overrides[$prefix . 'tin'] ?? ($party['tin'] ?? ''))),
                'street' => trim((string) ($overrides[$prefix . 'street'] ?? ($party['street'] ?? ''))),
                'city' => trim((string) ($overrides[$prefix . 'city'] ?? ($party['city'] ?? ''))),
                'postal' => trim((string) ($overrides[$prefix . 'postal'] ?? ($party['postal'] ?? ''))),
                'state' => trim((string) ($overrides[$prefix . 'state'] ?? ($party['state'] ?? ''))),
                'lga' => trim((string) ($overrides[$prefix . 'lga'] ?? ($party['lga'] ?? ''))),
                'email' => trim((string) ($overrides[$prefix . 'email'] ?? ($party['email'] ?? ''))),
                'address_line' => trim((string) ($overrides[$prefix . 'address'] ?? ($party['address_line'] ?? ''))),
            );
            $party = nrs_merge_party_details($party, $fromForm);
            $tin = trim((string) ($party['tin'] ?? ''));
            $needsAddress = trim((string) ($party['street'] ?? '')) === '' && trim((string) ($party['address_line'] ?? '')) === '';
            $needsName = trim((string) ($party['name'] ?? '')) === '';
            if ($tin !== '' && ($needsAddress || $needsName)) {
                $look = nrs_lookup_tin_party($db, $settings, $tin, $role);
                if ($look['ok']) {
                    $lookParty = $look['party'];
                    unset($lookParty['phone'], $lookParty['telephone']);
                    $party = nrs_merge_party_details($party, $lookParty);
                }
            }
            unset($party['phone'], $party['telephone']);
            // Persist whatever we know for next time.
            if ($tin !== '') {
                nrs_tin_party_save($db, $tin, $party, 'payload');
            }
            $payload[$role] = $party;
        }
        return $payload;
    }
}

if (!function_exists('nrs_party_from_settings')) {
    /**
     * Build UBL party. Uses party fields first; settings address only when TIN matches that role's configured TIN.
     *
     * @param array<string, string> $settings
     * @param array<string, mixed> $party
     * @return array<string, mixed>
     */
    function nrs_party_from_settings(array $settings, array $party, string $role): array
    {
        $prefix = $role === 'buyer' ? 'buyer_' : 'seller_';
        $name = trim((string) ($party['name'] ?? ($party['party_name'] ?? '')));
        $tin = trim((string) ($party['tin'] ?? ''));
        $settingsMatch = nrs_tin_party_from_settings($settings, $role, $tin);

        $email = trim((string) ($party['email'] ?? ''));
        if ($email === '' && isset($settingsMatch['email'])) {
            $email = $settingsMatch['email'];
        }
        // Phone is intentionally omitted from NRS invoice parties (not required; avoid wrong autofill).
        $desc = trim((string) ($party['business_description'] ?? ($settings[$prefix . 'desc'] ?? '')));
        if ($desc === '') {
            $desc = $role === 'buyer' ? 'Customer' : 'Supplier';
        }

        $street = trim((string) ($party['street'] ?? ''));
        $city = trim((string) ($party['city'] ?? ''));
        $postal = trim((string) ($party['postal'] ?? ''));
        $state = trim((string) ($party['state'] ?? ''));
        $lga = trim((string) ($party['lga'] ?? ''));
        if ($street === '' && !empty($party['address_line'])) {
            $street = trim((string) $party['address_line']);
        }
        // Settings address only for the matching configured TIN (prevents wrong buyer address).
        if ($settingsMatch) {
            if ($street === '' && !empty($settingsMatch['street'])) {
                $street = $settingsMatch['street'];
            }
            if ($city === '' && !empty($settingsMatch['city'])) {
                $city = $settingsMatch['city'];
            }
            if ($postal === '' && !empty($settingsMatch['postal'])) {
                $postal = $settingsMatch['postal'];
            }
            if ($state === '' && !empty($settingsMatch['state'])) {
                $state = $settingsMatch['state'];
            }
            if ($lga === '' && !empty($settingsMatch['lga'])) {
                $lga = $settingsMatch['lga'];
            }
            if ($name === '' && !empty($settingsMatch['name'])) {
                $name = $settingsMatch['name'];
            }
        }

        return array(
            'party_name' => $name,
            'tin' => $tin,
            'email' => $email,
            'business_description' => $desc,
            'postal_address' => array(
                'street_name' => $street !== '' ? $street : 'Address not provided',
                'city_name' => $city !== '' ? $city : 'City',
                'postal_zone' => $postal !== '' ? $postal : '000000',
                'lga' => $lga !== '' ? $lga : 'NG-FC-AML',
                'state' => $state !== '' ? $state : 'NG-FC',
                'country' => 'NG',
            ),
        );
    }
}

if (!function_exists('nrs_build_gateway_document')) {
    /**
     * Canonical invoice document sent to the active provider.
     * ByteInvoice: ROCAD draft shape. NRS direct: FIRS/UBL invoice JSON.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $doc
     * @param array<string, string> $settings
     * @return array<string, mixed>
     */
    function nrs_build_gateway_document(array $payload, array $doc = array(), array $settings = array()): array
    {
        $settings = $settings ?: nrs_default_settings();
        if (nrs_provider($settings) !== 'nrs_direct') {
            return array(
                'invoice_number' => (string) ($payload['invoice_number'] ?? ''),
                'issue_date' => (string) ($payload['issue_date'] ?? ''),
                'currency' => (string) ($payload['currency'] ?? 'NGN'),
                'irn' => (string) ($payload['irn'] ?? ($doc['irn'] ?? '')),
                'service_id' => (string) ($payload['service_id'] ?? ''),
                'description' => (string) ($payload['description'] ?? ''),
                'seller' => array(
                    'name' => (string) ($payload['seller']['name'] ?? ''),
                    'tin' => (string) ($payload['seller']['tin'] ?? ''),
                ),
                'buyer' => array(
                    'name' => (string) ($payload['buyer']['name'] ?? ''),
                    'tin' => (string) ($payload['buyer']['tin'] ?? ''),
                    'account_number' => (string) ($payload['buyer']['account_number'] ?? ''),
                    'bank_name' => (string) ($payload['buyer']['bank_name'] ?? ''),
                ),
                'line_items' => isset($payload['line_items']) && is_array($payload['line_items']) ? $payload['line_items'] : array(),
                'amounts' => isset($payload['amounts']) && is_array($payload['amounts']) ? $payload['amounts'] : array(),
                'rocad' => isset($payload['rocad']) && is_array($payload['rocad']) ? $payload['rocad'] : array(
                    'source_type' => (string) ($doc['source_type'] ?? 'invoice_payment'),
                    'source_id' => (int) ($doc['source_id'] ?? 0),
                    'source_reference' => (string) ($doc['source_reference'] ?? ''),
                ),
            );
        }

        $issueDate = (string) ($payload['issue_date'] ?? date('Y-m-d'));
        $issueTime = date('H:i:s');
        $currency = strtoupper(trim((string) ($payload['currency'] ?? 'NGN')));
        if ($currency === '') {
            $currency = 'NGN';
        }
        // Always rebuild IRN for NRS so InvoiceNumber is alphanumeric-only.
        $irn = nrs_build_irn(
            (string) ($payload['invoice_number'] ?? ($doc['invoice_number'] ?? '')),
            (string) ($payload['service_id'] ?? ($settings['service_id'] ?? '')),
            $issueDate
        );
        $amounts = isset($payload['amounts']) && is_array($payload['amounts']) ? $payload['amounts'] : array();
        $subtotal = round((float) ($amounts['subtotal'] ?? 0), 2);
        $vatAmount = round((float) ($amounts['vat_amount'] ?? 0), 2);
        $total = round((float) ($amounts['total'] ?? ($subtotal + $vatAmount)), 2);
        $vatRate = (float) ($amounts['vat_rate'] ?? ($settings['vat_rate'] ?? 7.5));
        if ($vatRate < 0) {
            $vatRate = 0;
        }
        $taxCategoryId = $vatRate > 0 ? 'STANDARD_VAT' : 'ZERO_VAT';

        $lineItems = isset($payload['line_items']) && is_array($payload['line_items']) ? $payload['line_items'] : array();
        if ($lineItems === array()) {
            $lineItems[] = array(
                'description' => (string) ($payload['description'] ?? 'Service'),
                'quantity' => 1,
                'unit_price' => $subtotal,
                'line_total' => $subtotal,
                'tax_rate' => $vatRate,
                'tax_amount' => $vatAmount,
            );
        }
        $isic = trim((string) ($settings['default_isic'] ?? ''));
        $serviceCategory = trim((string) ($settings['default_service_category'] ?? ''));

        $invoiceLines = array();
        foreach ($lineItems as $idx => $li) {
            if (!is_array($li)) {
                continue;
            }
            $qty = (float) ($li['quantity'] ?? 1);
            if ($qty <= 0) {
                $qty = 1;
            }
            $lineTotal = round((float) ($li['line_total'] ?? ($li['unit_price'] ?? 0)), 2);
            $unitPrice = round((float) ($li['unit_price'] ?? ($lineTotal / $qty)), 2);
            $name = trim((string) ($li['description'] ?? ($payload['description'] ?? 'Line item')));
            if ($name === '') {
                $name = 'Line item ' . ($idx + 1);
            }
            $invoiceLines[] = array(
                'isic_code' => robes_line_isic($li, $isic),
                'service_category' => robes_line_category($li, $serviceCategory),
                'invoiced_quantity' => $qty,
                'line_extension_amount' => $lineTotal,
                'item' => array(
                    'name' => $name,
                    'description' => $name,
                ),
                'price' => array(
                    'price_amount' => $unitPrice,
                    'base_quantity' => 1,
                    // UN/ECE Rec 20 quantity code (max 3 chars). C62 = one/each.
                    'price_unit' => 'C62',
                ),
            );
        }

        $kind = strtoupper(trim((string) ($payload['invoice_kind'] ?? ($settings['invoice_kind'] ?? 'B2B'))));
        if (!in_array($kind, array('B2B', 'B2C', 'B2G', 'G2B'), true)) {
            $kind = 'B2B';
        }
        $typeCode = trim((string) ($settings['invoice_type_code'] ?? '381'));
        if ($typeCode === '') {
            $typeCode = '381';
        }

        $ubl = array(
            'business_id' => trim((string) ($settings['business_id'] ?? '')),
            'irn' => $irn,
            'issue_date' => $issueDate,
            'issue_time' => $issueTime,
            'invoice_type_code' => $typeCode,
            'invoice_kind' => $kind,
            'payment_status' => nrs_normalize_payment_status(
                (string) ($payload['payment_status'] ?? 'PAID'),
                'PAID'
            ),
            'note' => (string) ($payload['description'] ?? ''),
            'document_currency_code' => $currency,
            'tax_currency_code' => $currency,
            'accounting_supplier_party' => nrs_party_from_settings(
                $settings,
                isset($payload['seller']) && is_array($payload['seller']) ? $payload['seller'] : array(),
                'seller'
            ),
            'tax_total' => array(
                array(
                    'tax_amount' => $vatAmount,
                    'tax_subtotal' => array(
                        array(
                            'taxable_amount' => $subtotal,
                            'tax_amount' => $vatAmount,
                            'tax_category' => array(
                                'id' => $taxCategoryId,
                                'percent' => $vatRate,
                            ),
                        ),
                    ),
                ),
            ),
            'legal_monetary_total' => array(
                'line_extension_amount' => $subtotal,
                'tax_exclusive_amount' => $subtotal,
                'tax_inclusive_amount' => $total,
                'payable_amount' => $total,
            ),
            'invoice_line' => $invoiceLines,
        );

        if ($kind !== 'B2C') {
            $ubl['accounting_customer_party'] = nrs_party_from_settings(
                $settings,
                isset($payload['buyer']) && is_array($payload['buyer']) ? $payload['buyer'] : array(),
                'buyer'
            );
        }

        return $ubl;
    }
}

if (!function_exists('nrs_response_data')) {
    /**
     * @param array{ok:bool,http_status:int,body:string,json:?array} $response
     * @return array<string, mixed>
     */
    function nrs_response_data(array $response): array
    {
        $json = is_array($response['json'] ?? null) ? $response['json'] : array();
        if (isset($json['data']) && is_array($json['data'])) {
            return $json['data'];
        }
        return $json;
    }
}

if (!function_exists('nrs_response_error_message')) {
    /**
     * @param array{ok:bool,http_status:int,body:string,json:?array} $response
     */
    function nrs_response_error_message(array $response): string
    {
        $json = is_array($response['json'] ?? null) ? $response['json'] : array();
        if (isset($json['error']) && is_array($json['error'])) {
            $details = trim((string) ($json['error']['details'] ?? ''));
            $public = trim((string) ($json['error']['public_message'] ?? ''));
            $code = trim((string) ($json['error']['code'] ?? ''));
            $msg = trim((string) ($json['error']['message'] ?? ''));
            if ($details !== '') {
                $base = $details;
                if ($public !== '' && stripos($details, $public) === false) {
                    $base .= ' — ' . $public;
                }
                return $code !== '' ? ($code . ': ' . $base) : $base;
            }
            if ($msg !== '') {
                return $code !== '' ? ($code . ': ' . $msg) : $msg;
            }
            if ($public !== '') {
                return $public;
            }
        }
        if (!empty($json['message']) && is_string($json['message'])) {
            return (string) $json['message'];
        }

        $status = (int) ($response['http_status'] ?? 0);
        $body = trim((string) ($response['body'] ?? ''));
        $looksHtml = $body !== '' && (
            stripos($body, '<html') !== false
            || stripos($body, '<!doctype') !== false
            || stripos($body, '<title>') !== false
            || stripos($body, '__uzdbm_') !== false
            || stripos($body, 'SSJSConnectorObj') !== false
        );

        // Provider/CDN outages return HTML (e.g. 502 Bad Gateway) — never dump that into the UI.
        if ($looksHtml || in_array($status, array(502, 503, 504), true)) {
            $label = $status > 0 ? ('HTTP ' . $status) : 'gateway error';
            if (stripos($body, '502') !== false || $status === 502) {
                $label = 'HTTP 502 Bad Gateway';
            } elseif (stripos($body, '503') !== false || $status === 503) {
                $label = 'HTTP 503 Service Unavailable';
            } elseif (stripos($body, '504') !== false || $status === 504) {
                $label = 'HTTP 504 Gateway Timeout';
            }
            return $label . ' from the e-invoice host. The NRS/provider API did not return JSON — try again shortly, or check Base URL / API keys in NRS Settings.';
        }

        return $body !== '' ? $body : 'Unknown provider error.';
    }
}

if (!function_exists('nrs_request')) {
    /**
     * @param array<string, string> $settings
     * @param array<int, string> $extraHeaders
     * @return array{ok:bool,http_status:int,body:string,json:?array}
     */
    function nrs_request(array $settings, string $method, string $path, $payload = null, array $extraHeaders = array()): array
    {
        $provider = nrs_provider($settings);
        $base = nrs_normalize_base_url((string) ($settings['base_url'] ?? ''), $provider);
        if ($base === '') {
            $base = nrs_default_base_url($provider);
        }
        // Strip all whitespace — pasted keys often pick up newlines/spaces and NRS returns "unable to validate api key".
        $apiKey = preg_replace('/\s+/', '', (string) ($settings['api_key'] ?? ''));
        $apiSecret = preg_replace('/\s+/', '', (string) ($settings['api_secret'] ?? ''));
        $apiKey = is_string($apiKey) ? $apiKey : '';
        $apiSecret = is_string($apiSecret) ? $apiSecret : '';

        if ($apiKey === '') {
            return array(
                'ok' => false,
                'http_status' => 0,
                'body' => 'API key is missing. Add it under Settings.',
                'json' => null,
            );
        }
        if ($provider === 'nrs_direct' && $apiSecret === '') {
            return array(
                'ok' => false,
                'http_status' => 0,
                'body' => 'NRS direct requires API key and API secret under Settings.',
                'json' => null,
            );
        }
        if (!empty($settings['enabled']) && (string) $settings['enabled'] !== '1') {
            return array(
                'ok' => false,
                'http_status' => 0,
                'body' => 'API calls are disabled in Settings.',
                'json' => null,
            );
        }
        if (!function_exists('curl_init')) {
            return array(
                'ok' => false,
                'http_status' => 0,
                'body' => 'PHP cURL is required to call the e-invoice API.',
                'json' => null,
            );
        }

        $url = $base . '/' . ltrim($path, '/');
        $ch = curl_init($url);
        $headers = array(
            'Content-Type: application/json',
            'Accept: application/json',
        );
        if ($provider === 'byteinvoice') {
            $headers[] = 'X-Api-Key: ' . $apiKey;
        } else {
            $headers[] = 'x-api-key: ' . $apiKey;
            $headers[] = 'x-api-secret: ' . $apiSecret;
            // businessId is sent in the invoice body; avoid extra auth headers that some NRS hosts reject.
        }
        foreach ($extraHeaders as $h) {
            $h = trim((string) $h);
            if ($h !== '') {
                $headers[] = $h;
            }
        }
        $opts = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HEADER => true,
        );
        if ($payload !== null) {
            $opts[CURLOPT_POSTFIELDS] = is_string($payload) ? $payload : json_encode($payload);
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headerBlob = '';
        $body = '';
        if (is_string($raw)) {
            $headerBlob = substr($raw, 0, $headerSize);
            $body = substr($raw, $headerSize);
        }
        $contentType = '';
        if ($headerBlob !== '' && preg_match('/^Content-Type:\s*([^\r\n;]+)/mi', $headerBlob, $m)) {
            $contentType = trim((string) $m[1]);
        }

        if ($errno) {
            return array(
                'ok' => false,
                'http_status' => $status,
                'body' => 'Network error talking to ' . nrs_provider_label($provider) . ': ' . $error,
                'json' => null,
                'content_type' => $contentType,
            );
        }
        $json = null;
        if (is_string($body) && $body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $json = $decoded;
            }
        }
        return array(
            'ok' => $status >= 200 && $status < 300,
            'http_status' => $status,
            'body' => is_string($body) ? $body : '',
            'json' => $json,
            'content_type' => $contentType,
            'request_url' => $url,
        );
    }
}

if (!function_exists('nrs_mask_credential')) {
    function nrs_mask_credential(string $value): string
    {
        $value = preg_replace('/\s+/', '', $value);
        $value = is_string($value) ? $value : '';
        $len = strlen($value);
        if ($len < 1) {
            return '(empty)';
        }
        if ($len <= 8) {
            return str_repeat('*', $len) . ' (' . $len . ' chars)';
        }
        return substr($value, 0, 4) . '…' . substr($value, -4) . ' (' . $len . ' chars)';
    }
}

if (!function_exists('nrs_test_api_connection')) {
    /**
     * Probe NRS auth without clearing an invoice.
     * Auth OK if HTTP is not a pure key failure (schema/validation errors still mean the key was accepted).
     *
     * @param array<string, string> $settings
     * @return array{ok:bool,message:string,http_status:int,url:string,key_mask:string,secret_mask:string}
     */
    function nrs_test_api_connection(array $settings): array
    {
        $key = preg_replace('/\s+/', '', (string) ($settings['api_key'] ?? ''));
        $secret = preg_replace('/\s+/', '', (string) ($settings['api_secret'] ?? ''));
        $key = is_string($key) ? $key : '';
        $secret = is_string($secret) ? $secret : '';
        $base = nrs_normalize_base_url((string) ($settings['base_url'] ?? ''), 'nrs_direct');
        $probe = array('invoice' => array('business_id' => (string) ($settings['business_id'] ?? '')));
        $response = nrs_request($settings, 'POST', '/invoice/validate', $probe);
        $err = strtolower(nrs_response_error_message($response));
        $url = (string) ($response['request_url'] ?? ($base . '/invoice/validate'));
        $keyBad = (strpos($err, 'unable to validate api key') !== false)
            || (strpos($err, 'invalid api key') !== false)
            || (strpos($err, 'unauthorized') !== false && (int) $response['http_status'] === 401);
        $ok = !$keyBad && ((int) $response['http_status'] > 0);
        $msg = $keyBad
            ? ('API key rejected by NRS: ' . nrs_response_error_message($response))
            : ('API key accepted by host (HTTP ' . (int) $response['http_status'] . '). Detail: ' . nrs_response_error_message($response));
        return array(
            'ok' => $ok,
            'message' => $msg,
            'http_status' => (int) $response['http_status'],
            'url' => $url,
            'key_mask' => nrs_mask_credential($key),
            'secret_mask' => nrs_mask_credential($secret),
        );
    }
}

if (!function_exists('nrs_delete_document')) {
    function nrs_delete_document($db, int $docId): bool
    {
        if ($docId < 1) {
            return false;
        }
        nrs_query($db, 'DELETE FROM nrs_einvoice_attempt WHERE nrs_einvoice_id=' . $docId);
        return (bool) nrs_query($db, 'DELETE FROM nrs_einvoice WHERE id=' . $docId . ' LIMIT 1');
    }
}

if (!function_exists('nrs_b64url_decode')) {
    /**
     * @return string|false
     */
    function nrs_b64url_decode(string $data)
    {
        $b64 = strtr(trim($data), '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        return base64_decode($b64, true);
    }
}

if (!function_exists('nrs_decrypt_signed_download')) {
    /**
     * Decrypt NRS GET /invoice/download/{IRN} payload {iv_hex,pub,data}.
     * Key = pub + first hyphen segment of x-api-key (32 bytes). AES-256-CFB.
     *
     * @param array<string, mixed> $payload
     * @return array{ok:bool,plaintext:string,invoice:?array,error:string}
     */
    function nrs_decrypt_signed_download(array $payload, string $apiKey): array
    {
        $enc = $payload;
        if (isset($payload['data']) && is_array($payload['data']) && (isset($payload['data']['iv_hex']) || isset($payload['data']['data']))) {
            $enc = $payload['data'];
        }
        $ivHex = trim((string) ($enc['iv_hex'] ?? ''));
        $pub = (string) ($enc['pub'] ?? '');
        $data = (string) ($enc['data'] ?? '');
        if ($ivHex === '' || $pub === '' || $data === '') {
            return array('ok' => false, 'plaintext' => '', 'invoice' => null, 'error' => 'Download payload is missing iv_hex/pub/data.');
        }
        $apiKey = trim($apiKey);
        $keyPart = explode('-', $apiKey, 2)[0];
        $key = $pub . $keyPart;
        if (strlen($key) < 32) {
            return array(
                'ok' => false,
                'plaintext' => '',
                'invoice' => null,
                'error' => 'Could not build 32-byte decrypt key from pub + API key. Check that the same taxpayer API key used to sign is stored in Settings.',
            );
        }
        if (strlen($key) > 32) {
            $key = substr($key, 0, 32);
        }
        $iv = @hex2bin($ivHex);
        if ($iv === false || strlen($iv) !== 16) {
            return array('ok' => false, 'plaintext' => '', 'invoice' => null, 'error' => 'Invalid iv_hex on download payload.');
        }
        $cipher = nrs_b64url_decode($data);
        if ($cipher === false) {
            $cipher = base64_decode($data, true);
        }
        if ($cipher === false || $cipher === '') {
            return array('ok' => false, 'plaintext' => '', 'invoice' => null, 'error' => 'Could not decode encrypted data field.');
        }
        if (!function_exists('openssl_decrypt')) {
            return array('ok' => false, 'plaintext' => '', 'invoice' => null, 'error' => 'OpenSSL is required to decrypt the signed invoice.');
        }
        $plain = openssl_decrypt($cipher, 'aes-256-cfb', $key, OPENSSL_RAW_DATA, $iv);
        if ($plain === false || $plain === '') {
            return array(
                'ok' => false,
                'plaintext' => '',
                'invoice' => null,
                'error' => 'Decrypt failed. Confirm Settings API key matches the taxpayer key used to sign this IRN.',
            );
        }
        $invoice = json_decode($plain, true);
        return array(
            'ok' => true,
            'plaintext' => $plain,
            'invoice' => is_array($invoice) ? $invoice : null,
            'error' => '',
        );
    }
}

if (!function_exists('nrs_alloc_manual_source_id')) {
    /**
     * Allocate a manual draft source_id that fits MySQL signed INT (max 2147483647).
     * crc32/%u can exceed that on 64-bit PHP and caused "Out of range value for column source_id".
     */
    function nrs_alloc_manual_source_id($db): int
    {
        $maxSigned = 2147483646;
        $res = nrs_query($db, "SELECT COALESCE(MAX(source_id), 0) AS m FROM nrs_einvoice WHERE source_type='manual'");
        $row = $res ? robe_fetch($res) : null;
        $next = (int) ($row['m'] ?? 0) + 1;
        if ($next > 0 && $next <= $maxSigned) {
            $check = nrs_query($db, "SELECT id FROM nrs_einvoice WHERE source_type='manual' AND source_id=$next LIMIT 1");
            if (!$check || !robe_fetch($check)) {
                return $next;
            }
        }
        for ($i = 0; $i < 12; $i++) {
            // Keep inside signed INT: 31-bit positive range only.
            $id = (int) (crc32(uniqid('manual', true) . mt_rand()) & 0x7fffffff);
            if ($id < 1) {
                $id = mt_rand(1, $maxSigned);
            }
            $exists = nrs_query($db, "SELECT id FROM nrs_einvoice WHERE source_type='manual' AND source_id=$id LIMIT 1");
            if (!$exists || !robe_fetch($exists)) {
                return $id;
            }
        }
        return mt_rand(1, $maxSigned);
    }
}

if (!function_exists('nrs_normalize_public_key_pem')) {
    function nrs_normalize_public_key_pem(string $raw): string
    {
        $raw = trim(str_replace("\r", '', $raw));
        if ($raw === '') {
            return '';
        }
        if (stripos($raw, 'BEGIN PUBLIC KEY') !== false) {
            return $raw;
        }
        $decoded = base64_decode(preg_replace('/\s+/', '', $raw), true);
        if (is_string($decoded) && stripos($decoded, 'BEGIN PUBLIC KEY') !== false) {
            return trim($decoded);
        }
        $b64 = is_string($decoded) && $decoded !== ''
            ? base64_encode($decoded)
            : preg_replace('/\s+/', '', $raw);
        return "-----BEGIN PUBLIC KEY-----\n" . trim(chunk_split((string) $b64, 64, "\n")) . "\n-----END PUBLIC KEY-----";
    }
}

if (!function_exists('nrs_crypto_find_value')) {
    /**
     * Recursively find the first non-empty string value whose key matches $patterns.
     *
     * @param array<mixed> $node
     * @param array<int, string> $patterns regexes matched against the key
     */
    function nrs_crypto_find_value($node, array $patterns, int $depth = 0): string
    {
        if (!is_array($node) || $depth > 6) {
            return '';
        }
        foreach ($node as $key => $value) {
            $keyStr = is_string($key) ? $key : (string) $key;
            if (is_string($value) || is_numeric($value)) {
                $val = trim((string) $value);
                if ($val === '') {
                    continue;
                }
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $keyStr)) {
                        return $val;
                    }
                }
            }
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                $found = nrs_crypto_find_value($value, $patterns, $depth + 1);
                if ($found !== '') {
                    return $found;
                }
            }
        }
        return '';
    }
}

if (!function_exists('nrs_parse_crypto_keys_file')) {
    /**
     * Parse NRS dashboard crypto_keys.txt into public_key + certificate.
     * Accepts JSON (any common key names), nested data, or PEM blocks.
     *
     * @return array{ok:bool,public_key:string,certificate:string,error:string}
     */
    function nrs_parse_crypto_keys_file(string $raw): array
    {
        // Strip BOM / zero-width chars that break JSON decode.
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $raw = trim((string) $raw);
        $raw = str_replace("\xC2\xA0", ' ', $raw);
        if ($raw === '') {
            return array('ok' => false, 'public_key' => '', 'certificate' => '', 'error' => 'Paste the full crypto_keys.txt contents.');
        }

        $pub = '';
        $cert = '';

        // 1) JSON object (preferred NRS format).
        $json = json_decode($raw, true);
        // Sometimes the file is a quoted JSON string.
        if (is_string($json) && $json !== '') {
            $inner = json_decode($json, true);
            if (is_array($inner)) {
                $json = $inner;
            }
        }
        if (!is_array($json) && preg_match('/\{[\s\S]*\}/', $raw, $m)) {
            $json = json_decode($m[0], true);
            if (is_string($json) && $json !== '') {
                $inner = json_decode($json, true);
                if (is_array($inner)) {
                    $json = $inner;
                }
            }
        }
        if (is_array($json)) {
            // Unwrap common wrappers.
            foreach (array('data', 'keys', 'crypto_keys', 'cryptoKeys', 'result') as $wrap) {
                if (isset($json[$wrap]) && is_array($json[$wrap])) {
                    $json = array_merge($json, $json[$wrap]);
                }
            }
            $pub = nrs_crypto_find_value($json, array(
                '/^public[_-]?key$/i',
                '/^nrs[_-]?public[_-]?key$/i',
                '/^pub(lic)?$/i',
                '/public[_-]?key/i',
            ));
            $cert = nrs_crypto_find_value($json, array(
                '/^certificate(_b64)?$/i',
                '/^cert$/i',
                '/certificate/i',
            ));
            // Prefer explicit top-level aliases if recursive match picked a wrong nested field.
            foreach (array('public_key', 'publicKey', 'PUBLIC_KEY', 'nrs_public_key', 'nrsPublicKey', 'PublicKey', 'pub') as $k) {
                if (!empty($json[$k]) && is_scalar($json[$k])) {
                    $pub = trim((string) $json[$k]);
                    break;
                }
            }
            foreach (array('certificate', 'certificate_b64', 'certificateB64', 'CERTIFICATE', 'cert', 'Cert', 'Certificate') as $k) {
                if (!empty($json[$k]) && is_scalar($json[$k])) {
                    $cert = trim((string) $json[$k]);
                    break;
                }
            }
            if ($pub !== '' && $cert !== '') {
                return array('ok' => true, 'public_key' => $pub, 'certificate' => $cert, 'error' => '');
            }
            $foundKeys = array();
            $walk = function ($node, $prefix = '') use (&$walk, &$foundKeys) {
                if (!is_array($node)) {
                    return;
                }
                foreach ($node as $k => $v) {
                    $path = $prefix === '' ? (string) $k : ($prefix . '.' . $k);
                    if (is_array($v)) {
                        $walk($v, $path);
                    } elseif (is_scalar($v) && trim((string) $v) !== '') {
                        $foundKeys[] = $path;
                    }
                }
            };
            $walk($json);
            $keyList = $foundKeys ? implode(', ', array_slice($foundKeys, 0, 20)) : '(none)';
            // Partial match: keep public_key so user can paste certificate alone next.
            if ($pub !== '' && $cert === '') {
                $looksLikeKeyRecord = isset($json['id']) || isset($json['uuid']) || isset($json['status']);
                $msg = $looksLikeKeyRecord
                    ? 'This file is incomplete for receipt QR. On NRS open Approved Businesses → your business → Get Cryptographic Keys. Paste that file here (it must include a certificate). The public key from this paste was kept.'
                    : 'Public key found, but the certificate is missing. Paste the full keys file from NRS Approved Businesses, or paste the certificate below.';
                return array(
                    'ok' => false,
                    'public_key' => $pub,
                    'certificate' => '',
                    'error' => $msg,
                );
            }
            if ($pub === '' && $cert !== '') {
                return array(
                    'ok' => false,
                    'public_key' => '',
                    'certificate' => $cert,
                    'error' => 'Found certificate but no public_key. Paste the full crypto_keys.txt that includes both fields.',
                );
            }
            return array(
                'ok' => false,
                'public_key' => '',
                'certificate' => '',
                'error' => 'Could not find public key + certificate in that JSON. Keys found: ' . $keyList . '. Paste the exact crypto_keys.txt from NRS (Get Cryptographic Keys).',
            );
        }

        // 2) PEM blocks in plain text.
        if (preg_match('/-----BEGIN PUBLIC KEY-----[\\s\\S]*?-----END PUBLIC KEY-----/i', $raw, $pm)) {
            $pub = trim($pm[0]);
        }
        if (preg_match('/-----BEGIN CERTIFICATE-----[\\s\\S]*?-----END CERTIFICATE-----/i', $raw, $cm)) {
            $cert = trim($cm[0]);
        }
        if ($pub !== '' && $cert !== '') {
            return array('ok' => true, 'public_key' => $pub, 'certificate' => $cert, 'error' => '');
        }

        // 3) Simple key=value / key: value lines.
        if (preg_match('/public[_-]?key\\s*[:=]\\s*(\\S+)/i', $raw, $pm)) {
            $pub = trim($pm[1], " \t\"'");
        }
        if (preg_match('/certificate(_b64)?\\s*[:=]\\s*(\\S+)/i', $raw, $cm)) {
            $cert = trim($cm[2], " \t\"'");
        }
        if ($pub !== '' && $cert !== '') {
            return array('ok' => true, 'public_key' => $pub, 'certificate' => $cert, 'error' => '');
        }

        return array(
            'ok' => false,
            'public_key' => '',
            'certificate' => '',
            'error' => 'Could not parse crypto_keys.txt. Expected JSON like {"public_key":"...","certificate":"..."}.',
        );
    }
}

if (!function_exists('nrs_build_qr_stamp')) {
    /**
     * Build NRS authenticity QR payload (sandbox and production).
     * Spec: encrypt JSON {irn:"{IRN}.{unix}", certificate} with taxpayer public key (RSA PKCS#1), Base64 result.
     *
     * @param array<string, string> $settings
     * @return array{ok:bool,payload:string,error:string}
     */
    function nrs_build_qr_stamp(string $irn, array $settings): array
    {
        $irn = trim($irn);
        $pub = trim((string) ($settings['crypto_public_key'] ?? ''));
        $cert = trim((string) ($settings['crypto_certificate'] ?? ''));
        if ($irn === '') {
            return array('ok' => false, 'payload' => '', 'error' => 'IRN is required for QR stamp.');
        }
        if ($pub === '' || $cert === '') {
            return array(
                'ok' => false,
                'payload' => '',
                'error' => 'Paste crypto_keys.txt in Settings (public_key + certificate) to include the NRS authenticity QR on receipts.',
            );
        }
        if (!function_exists('openssl_public_encrypt')) {
            return array('ok' => false, 'payload' => '', 'error' => 'OpenSSL is required to build the NRS QR stamp.');
        }
        $pem = nrs_normalize_public_key_pem($pub);
        $key = @openssl_pkey_get_public($pem);
        if ($key === false) {
            return array('ok' => false, 'payload' => '', 'error' => 'Public key is invalid. Paste the public_key value from crypto_keys.txt (Base64 or PEM).');
        }
        $message = array(
            'irn' => $irn . '.' . time(),
            'certificate' => $cert,
        );
        $json = (string) json_encode($message, JSON_UNESCAPED_SLASHES);
        $details = @openssl_pkey_get_details($key);
        $maxLen = 0;
        if (is_array($details) && !empty($details['bits'])) {
            // PKCS#1 v1.5 max plaintext ≈ keyBytes - 11
            $maxLen = ((int) $details['bits'] / 8) - 11;
        }
        if ($maxLen > 0 && strlen($json) > $maxLen) {
            return array(
                'ok' => false,
                'payload' => '',
                'error' => 'QR payload is too large for this RSA key (' . strlen($json) . ' bytes > ' . $maxLen . '). Use the certificate string from crypto_keys.txt, not a full PEM certificate file.',
            );
        }
        $encrypted = '';
        $ok = @openssl_public_encrypt($json, $encrypted, $pem, OPENSSL_PKCS1_PADDING);
        if (!$ok || $encrypted === '') {
            $err = function_exists('openssl_error_string') ? (string) openssl_error_string() : '';
            return array(
                'ok' => false,
                'payload' => '',
                'error' => 'Could not encrypt QR stamp.' . ($err !== '' ? (' ' . $err) : ' Check crypto keys.'),
            );
        }
        return array('ok' => true, 'payload' => base64_encode($encrypted), 'error' => '');
    }
}

if (!function_exists('nrs_qr_image_data_uri')) {
    /**
     * Render NRS authenticity payload as an embedded image (no CDN / JS required).
     *
     * @return array{ok:bool,uri:string,error:string}
     */
    function nrs_qr_image_data_uri(string $payload): array
    {
        $payload = trim($payload);
        if ($payload === '') {
            return array('ok' => false, 'uri' => '', 'error' => 'QR payload is empty.');
        }
        $lib = __DIR__ . DIRECTORY_SEPARATOR . 'nrs_qrcode_lib.php';
        if (!is_file($lib)) {
            return array('ok' => false, 'uri' => '', 'error' => 'QR library missing (nrs_qrcode_lib.php).');
        }
        if (!class_exists('QRCode', false)) {
            require_once $lib;
        }
        if (!class_exists('QRCode', false) || !defined('QR_ERROR_CORRECT_LEVEL_M') || !defined('QR_MODE_8BIT_BYTE')) {
            return array('ok' => false, 'uri' => '', 'error' => 'QR library failed to load.');
        }

        // Byte-mode max data bytes at ECC M (ISO/IEC 18004). Avoids broken getMinimumQRCode().
        $byteMaxM = array(
            1 => 14, 2 => 26, 3 => 42, 4 => 62, 5 => 84, 6 => 106, 7 => 122, 8 => 152, 9 => 180, 10 => 213,
            11 => 251, 12 => 287, 13 => 331, 14 => 362, 15 => 390, 16 => 436, 17 => 468, 18 => 531, 19 => 574, 20 => 644,
            21 => 702, 22 => 742, 23 => 792, 24 => 843, 25 => 894, 26 => 938, 27 => 982, 28 => 1026, 29 => 1080, 30 => 1132,
            31 => 1216, 32 => 1270, 33 => 1336, 34 => 1410, 35 => 1468, 36 => 1530, 37 => 1592, 38 => 1654, 39 => 1714, 40 => 1776,
        );
        $len = strlen($payload);
        $typeNumber = 0;
        foreach ($byteMaxM as $ver => $maxBytes) {
            if ($len <= $maxBytes) {
                $typeNumber = (int) $ver;
                break;
            }
        }
        if ($typeNumber < 1) {
            return array('ok' => false, 'uri' => '', 'error' => 'QR payload is too large (' . $len . ' bytes).');
        }

        $previousHandler = set_error_handler(static function ($severity, $message, $file, $line) {
            // Library uses trigger_error(..., E_USER_ERROR) which would otherwise hard-fatal (HTTP 500).
            if ($severity === E_USER_ERROR || $severity === E_RECOVERABLE_ERROR) {
                throw new ErrorException((string) $message, 0, $severity, (string) $file, (int) $line);
            }
            return false;
        });

        try {
            $qr = new QRCode();
            $qr->setErrorCorrectLevel(QR_ERROR_CORRECT_LEVEL_M);
            $qr->setTypeNumber($typeNumber);
            $qr->addData($payload, QR_MODE_8BIT_BYTE);
            $qr->make();

            $modules = (int) $qr->getModuleCount();
            if ($modules < 1) {
                return array('ok' => false, 'uri' => '', 'error' => 'QR matrix is empty.');
            }

            if (function_exists('imagecreatetruecolor') && function_exists('imagepng')) {
                $pixel = max(2, (int) floor(180 / $modules));
                $image = $qr->createImage($pixel, 2);
                if ($image !== false) {
                    ob_start();
                    imagepng($image);
                    $bin = (string) ob_get_clean();
                    imagedestroy($image);
                    if ($bin !== '') {
                        return array(
                            'ok' => true,
                            'uri' => 'data:image/png;base64,' . base64_encode($bin),
                            'error' => '',
                        );
                    }
                }
            }

            // SVG fallback (no GD required).
            $scale = 4;
            $size = $modules * $scale;
            $rects = '';
            for ($r = 0; $r < $modules; $r++) {
                for ($c = 0; $c < $modules; $c++) {
                    if ($qr->isDark($r, $c)) {
                        $rects .= '<rect x="' . ($c * $scale) . '" y="' . ($r * $scale)
                            . '" width="' . $scale . '" height="' . $scale . '"/>';
                    }
                }
            }
            $svg = '<?xml version="1.0" encoding="UTF-8"?>'
                . '<svg xmlns="http://www.w3.org/2000/svg" width="180" height="180" viewBox="0 0 '
                . $size . ' ' . $size . '" shape-rendering="crispEdges">'
                . '<rect width="100%" height="100%" fill="#fff"/>'
                . '<g fill="#000">' . $rects . '</g></svg>';

            return array(
                'ok' => true,
                'uri' => 'data:image/svg+xml;base64,' . base64_encode($svg),
                'error' => '',
            );
        } catch (Throwable $e) {
            return array('ok' => false, 'uri' => '', 'error' => 'QR image failed: ' . $e->getMessage());
        } finally {
            if ($previousHandler === null) {
                restore_error_handler();
            } else {
                set_error_handler($previousHandler);
            }
        }
    }
}

if (!function_exists('nrs_receipt_format_address')) {
    /**
     * @param array<string, mixed> $party
     * @param array<string, string> $settings
     */
    function nrs_receipt_format_address(array $party, array $settings, string $role): string
    {
        if (!empty($party['address_line']) && is_string($party['address_line'])) {
            $line = trim($party['address_line']);
            if ($line !== '') {
                return $line;
            }
        }
        $addr = isset($party['postal_address']) && is_array($party['postal_address'])
            ? $party['postal_address'] : array();
        $tin = trim((string) ($party['tin'] ?? ''));
        $settingsParty = $tin !== '' ? nrs_tin_party_from_settings($settings, $role, $tin) : array();
        $parts = array_filter(array(
            trim((string) ($addr['street_name'] ?? ($party['street'] ?? ($settingsParty['street'] ?? '')))),
            trim((string) ($addr['city_name'] ?? ($party['city'] ?? ($settingsParty['city'] ?? '')))),
            trim((string) ($addr['state'] ?? ($party['state'] ?? ($settingsParty['state'] ?? '')))),
            trim((string) ($addr['lga'] ?? ($party['lga'] ?? ($settingsParty['lga'] ?? '')))),
            trim((string) ($addr['postal_zone'] ?? ($party['postal'] ?? ($settingsParty['postal'] ?? '')))),
            trim((string) ($addr['country'] ?? 'NG')),
        ), static function ($v) {
            return $v !== ''
                && strcasecmp($v, 'Address not provided') !== 0
                && strcasecmp($v, 'City') !== 0
                && $v !== '000000'
                && $v !== 'NG-FC'
                && $v !== 'NG-FC-AML';
        });
        return $parts ? implode(', ', $parts) : '—';
    }
}

if (!function_exists('nrs_render_receipt_html')) {
    /**
     * Letterheaded printable/downloadable receipt for a cleared NRS invoice.
     * Shows mandatory NRS / VAT invoice fields (seller/buyer, IRN, lines, VAT, totals, QR).
     *
     * @param array<string, mixed> $doc
     * @param array<string, mixed> $invoice
     * @param array<string, string> $settings
     */
    function nrs_render_receipt_html(array $doc, array $invoice, array $settings, int $docId, $db = null): string
    {
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
        $lines = isset($invoice['invoice_line']) && is_array($invoice['invoice_line'])
            ? $invoice['invoice_line'] : array();
        if ($lines === array() && isset($invoice['line_items']) && is_array($invoice['line_items'])) {
            $lines = $invoice['line_items'];
        }
        $env = strtolower(trim((string) ($settings['environment'] ?? 'sandbox'))) === 'production' ? 'Production' : 'Sandbox';
        $qr = nrs_build_qr_stamp($irn, $settings);
        $logo = '/rocad_admin/images/rocad-logo.png';

        $invoiceNumber = trim((string) ($invoice['invoice_number'] ?? ($doc['invoice_number'] ?? '')));
        if ($invoiceNumber === '' && $irn !== '') {
            $invoiceNumber = explode('-', $irn)[0];
        }
        $issueDate = (string) ($invoice['issue_date'] ?? ($doc['issue_date'] ?? ''));
        $issueTime = (string) ($invoice['issue_time'] ?? '');
        $invoiceKind = strtoupper(trim((string) ($invoice['invoice_kind'] ?? ($doc['invoice_kind'] ?? ($settings['invoice_kind'] ?? 'B2B')))));
        $currency = strtoupper(trim((string) ($invoice['document_currency_code'] ?? ($invoice['currency'] ?? 'NGN'))));
        if ($currency === '') {
            $currency = 'NGN';
        }
        $serviceId = trim((string) ($invoice['service_id'] ?? ($doc['service_id'] ?? ($settings['service_id'] ?? ''))));
        $businessId = trim((string) ($invoice['business_id'] ?? ($settings['business_id'] ?? '')));
        $note = trim((string) ($invoice['note'] ?? ($doc['description'] ?? '')));

        $taxEx = (float) ($money['tax_exclusive_amount'] ?? ($doc['amount'] ?? 0));
        $taxIn = (float) ($money['tax_inclusive_amount'] ?? ($doc['total_amount'] ?? 0));
        $payable = (float) ($money['payable_amount'] ?? $taxIn);
        $vatAmount = (float) ($doc['vat_amount'] ?? 0);
        $vatRate = (float) ($settings['vat_rate'] ?? 7.5);
        $taxTotals = isset($invoice['tax_total']) && is_array($invoice['tax_total']) ? $invoice['tax_total'] : array();
        if ($taxTotals) {
            $firstTax = isset($taxTotals[0]) && is_array($taxTotals[0]) ? $taxTotals[0] : $taxTotals;
            if (isset($firstTax['tax_amount'])) {
                $vatAmount = (float) $firstTax['tax_amount'];
            }
            $sub = isset($firstTax['tax_subtotal'][0]) && is_array($firstTax['tax_subtotal'][0])
                ? $firstTax['tax_subtotal'][0] : array();
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
        if ($vatRate < 0) {
            $vatRate = 0;
        }
        $moneyLabel = $currency === 'NGN' ? '₦' : ($currency . ' ');

        $sellerName = (string) ($seller['party_name'] ?? ($seller['name'] ?? ($doc['seller_name'] ?? ($settings['seller_name'] ?? ''))));
        $sellerTin = (string) ($seller['tin'] ?? ($doc['seller_tin'] ?? ($settings['seller_tin'] ?? '')));
        $sellerEmail = (string) ($seller['email'] ?? ($settings['seller_email'] ?? ''));
        $sellerAddr = nrs_receipt_format_address($seller, $settings, 'seller');
        $buyerName = (string) ($buyer['party_name'] ?? ($buyer['name'] ?? ($doc['buyer_name'] ?? '')));
        $buyerTin = (string) ($buyer['tin'] ?? ($doc['buyer_tin'] ?? ($settings['default_buyer_tin'] ?? '')));
        $buyerEmail = (string) ($buyer['email'] ?? ($settings['buyer_email'] ?? ''));
        $buyerAddr = nrs_receipt_format_address($buyer, $settings, 'buyer');

        // Prefer TIN cache / payload party when receipt address is missing or placeholder.
        $payloadDoc = array();
        if (!empty($doc['payload_json'])) {
            $decodedPayload = json_decode((string) $doc['payload_json'], true);
            if (is_array($decodedPayload)) {
                $payloadDoc = $decodedPayload;
            }
        }
        if ($buyerAddr === '—' || $buyerAddr === '') {
            $buyerFromPayload = isset($payloadDoc['buyer']) && is_array($payloadDoc['buyer']) ? $payloadDoc['buyer'] : array();
            if ($buyerFromPayload) {
                $buyerAddr = nrs_receipt_format_address($buyerFromPayload, $settings, 'buyer');
            }
        }
        if (($buyerAddr === '—' || $buyerAddr === '') && $buyerTin !== '' && $db instanceof mysqli) {
            $cachedBuyer = nrs_tin_party_get($db, $buyerTin);
            if ($cachedBuyer) {
                $buyerAddr = trim((string) ($cachedBuyer['address_line'] ?? ''));
                if ($buyerAddr === '') {
                    $buyerAddr = trim(implode(', ', array_filter(array(
                        (string) ($cachedBuyer['street'] ?? ''),
                        (string) ($cachedBuyer['city'] ?? ''),
                        (string) ($cachedBuyer['state'] ?? ''),
                    ))));
                }
                if ($buyerAddr === '') {
                    $buyerAddr = '—';
                }
            }
        }
        if ($sellerAddr === '—' || $sellerAddr === '') {
            $sellerFromPayload = isset($payloadDoc['seller']) && is_array($payloadDoc['seller']) ? $payloadDoc['seller'] : array();
            if ($sellerFromPayload) {
                $sellerAddr = nrs_receipt_format_address($sellerFromPayload, $settings, 'seller');
            }
        }

        $safeFile = preg_replace('/[^A-Za-z0-9_-]+/', '_', $irn !== '' ? $irn : $invoiceNumber);
        if ($safeFile === '') {
            $safeFile = 'nrs_receipt_' . $docId;
        }

        $vatRateLabel = rtrim(rtrim(number_format($vatRate, 2, '.', ''), '0'), '.');
        $statusLabel = nrs_status_label((string) ($doc['status'] ?? 'CONFIRMED'));
        $isValid = in_array(strtoupper((string) ($doc['status'] ?? '')), array('CONFIRMED', 'SIGNED', 'VALIDATED', 'CLEARED'), true)
            || stripos($statusLabel, 'confirm') !== false
            || stripos($statusLabel, 'valid') !== false
            || stripos($statusLabel, 'sign') !== false;
        $statusPill = $isValid ? 'VALID' : strtoupper($statusLabel !== '' ? $statusLabel : 'ISSUED');
        $issueDisplay = trim($issueDate . ($issueTime !== '' ? ' ' . $issueTime : ''));
        if ($issueDisplay === '') {
            $issueDisplay = '—';
        } elseif (strpos($issueDisplay, 'WAT') === false) {
            $issueDisplay .= ' (WAT)';
        }
        $invoiceTypeLabel = 'Tax Invoice' . ($invoiceKind !== '' ? ' (' . $invoiceKind . ')' : '');
        $sellerAddrDisplay = $sellerAddr !== '—' ? $sellerAddr : 'No. 50 Sokoto Road, Tarauni GRA, Kano, Nigeria';
        $buyerTinDisplay = $buyerTin !== '' ? $buyerTin : ($invoiceKind === 'B2C' ? 'Optional (B2C)' : '—');
        $sourceRef = trim((string) ($doc['source_reference'] ?? ''));
        $paymentMethod = 'Bank transfer / recorded payment';
        if (!empty($doc['source_type']) && (string) $doc['source_type'] === 'manual') {
            $paymentMethod = 'Manual e-invoice';
        }

        $fmtQty = static function ($qty): string {
            return rtrim(rtrim(number_format((float) $qty, 3, '.', ''), '0'), '.');
        };

        $lineRows = '';
        $lineCount = 0;
        if ($lines) {
            foreach ($lines as $i => $line) {
                if (!is_array($line)) {
                    continue;
                }
                $item = isset($line['item']) && is_array($line['item']) ? $line['item'] : array();
                $price = isset($line['price']) && is_array($line['price']) ? $line['price'] : array();
                $name = (string) ($item['name'] ?? ($item['description'] ?? ($line['description'] ?? 'Line')));
                $qty = (float) ($line['invoiced_quantity'] ?? ($line['quantity'] ?? 1));
                if ($qty <= 0) {
                    $qty = 1;
                }
                $amt = (float) ($line['line_extension_amount'] ?? ($line['line_total'] ?? 0));
                $unit = (float) ($price['price_amount'] ?? ($line['unit_price'] ?? ($amt / $qty)));
                $lineVat = (float) ($line['tax_amount'] ?? round($amt * ($vatRate / 100), 2));
                $lineIncl = round($amt + $lineVat, 2);
                $lineCount++;
                $lineRows .= '<tr><td>' . $lineCount . '</td><td>' . nrs_h($name) . '</td><td class="right">'
                    . nrs_h($fmtQty($qty)) . '</td><td class="right">' . $moneyLabel . number_format($unit, 2)
                    . '</td><td class="right">' . $moneyLabel . number_format($lineVat, 2)
                    . '</td><td class="right">' . $moneyLabel . number_format($lineIncl, 2) . '</td></tr>';
            }
        }
        if ($lineRows === '') {
            $lineVat = $vatAmount;
            $lineIncl = round($taxEx + $lineVat, 2);
            $lineRows = '<tr><td>1</td><td>' . nrs_h($note !== '' ? $note : 'Invoice')
                . '</td><td class="right">1</td><td class="right">' . $moneyLabel . number_format($taxEx, 2)
                . '</td><td class="right">' . $moneyLabel . number_format($lineVat, 2)
                . '</td><td class="right">' . $moneyLabel . number_format($lineIncl, 2) . '</td></tr>';
        }

        $detailLine = static function (string $label, string $value): string {
            $value = trim($value);
            if ($value === '') {
                $value = '—';
            }
            return '<div class="detail-line"><span>' . nrs_h($label) . ':</span> ' . nrs_h($value) . '</div>';
        };

        $qrInner = '';
        if ($qr['ok']) {
            $qrImg = nrs_qr_image_data_uri((string) $qr['payload']);
            if ($qrImg['ok']) {
                $qrInner = '<img class="qr-img" src="' . nrs_h($qrImg['uri']) . '" width="160" height="160" alt="NRS authenticity QR">';
            } else {
                $qrInner = '<div id="nrsQrBox" aria-label="NRS authenticity QR"></div>'
                    . '<script type="application/json" id="nrsQrPayload">' . nrs_h($qr['payload']) . '</script>';
            }
        } else {
            $qrInner = '<div class="qr-fallback">QR unavailable<br><small>' . nrs_h($qr['error']) . '</small></div>';
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>e-Invoice ' . nrs_h($invoiceNumber !== '' ? $invoiceNumber : $irn) . '</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root {
    --rocad:#1f4e79;
    --rocad-deep:#163a5c;
    --rocad-soft:#e8f1fa;
    --rocad-mid:#d6e6f5;
    --ink:#1a2332;
    --muted:#64748b;
    --line:#d9e3ef;
    --ok:#1f7a4d;
  }
  * { box-sizing: border-box; }
  body { margin:0; background:#eef2f7; color:var(--ink); font-family:"Segoe UI", Arial, sans-serif; }
  .sheet-wrap { max-width:920px; margin:18px auto; padding:0 12px 28px; }
  .actions { display:flex; flex-wrap:wrap; gap:8px; margin:0 0 14px; }
  .actions button, .actions a {
    appearance:none; border:0; border-radius:8px; padding:10px 14px; font-weight:600; cursor:pointer;
    text-decoration:none; display:inline-flex; align-items:center; gap:6px; font-size:14px;
  }
  .btn-primary { background:var(--rocad); color:#fff; }
  .btn-secondary { background:#fff; color:var(--rocad); border:1px solid #bcd0e6 !important; }
  .btn-ghost { background:transparent; color:#334155; }
  .sheet {
    background:#fff; border:1px solid #d7e0ea; border-radius:14px; padding:22px 24px 20px;
    box-shadow:0 10px 28px rgba(15,23,42,.07);
  }
  .topbar {
    display:flex; justify-content:space-between; gap:16px; align-items:flex-start;
    margin-bottom:14px;
  }
  .brand { display:flex; gap:12px; align-items:center; }
  .brand img { height:54px; width:auto; display:block; }
  .brand .co { margin:0; font-size:18px; font-weight:800; color:var(--rocad); letter-spacing:.2px; }
  .brand .tag { margin:2px 0 0; font-size:12px; color:#475569; font-weight:600; }
  .brand .addr { margin:3px 0 0; font-size:11px; color:var(--muted); line-height:1.35; max-width:28rem; }
  .doc-right { text-align:right; }
  .doc-right .title { margin:0; font-size:18px; font-weight:800; color:var(--rocad-deep); }
  .doc-right .sub { margin:4px 0 0; font-size:12px; color:var(--muted); }
  .banner {
    display:flex; justify-content:space-between; align-items:center; gap:12px;
    background:linear-gradient(90deg, var(--rocad-deep), var(--rocad));
    color:#fff; border-radius:10px; padding:12px 16px; margin-bottom:16px;
  }
  .banner h1 { margin:0; font-size:22px; font-weight:800; letter-spacing:.2px; }
  .valid-pill {
    display:inline-flex; align-items:center; gap:6px; background:#fff; color:var(--ok);
    border-radius:999px; padding:6px 12px; font-weight:800; font-size:12px;
  }
  .valid-pill .dot {
    width:16px; height:16px; border-radius:50%; background:var(--ok); color:#fff;
    display:inline-flex; align-items:center; justify-content:center; font-size:11px; line-height:1;
  }
  .meta-grid {
    display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:10px; margin-bottom:14px;
  }
  .meta-card {
    background:#f7fafc; border:1px solid var(--line); border-radius:10px; padding:10px 12px;
  }
  .meta-card .k { display:block; font-size:11px; color:var(--muted); font-weight:700; text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px; }
  .meta-card .v { font-size:13px; font-weight:700; color:var(--ink); word-break:break-word; }
  .meta-card .v.status-ok { color:var(--ok); }
  .party-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px; }
  .party {
    background:#f5f8fc; border:1px solid var(--line); border-radius:10px; padding:12px 14px;
  }
  .party h2 {
    margin:0 0 8px; font-size:12px; text-transform:uppercase; letter-spacing:.05em;
    color:var(--rocad); font-weight:800;
  }
  .party .name { font-size:15px; font-weight:800; margin-bottom:6px; }
  .detail-line { font-size:12.5px; color:#334155; margin-top:3px; line-height:1.4; }
  .detail-line span { color:var(--muted); font-weight:700; }
  table.lines { width:100%; border-collapse:collapse; margin:0 0 12px; overflow:hidden; border-radius:10px; }
  table.lines thead th {
    background:var(--rocad); color:#fff; font-size:12px; font-weight:700;
    padding:10px 8px; text-align:left; border:0;
  }
  table.lines tbody td {
    padding:10px 8px; font-size:13px; border-bottom:1px solid #eef2f7; vertical-align:top;
  }
  table.lines tbody tr:nth-child(even) td { background:#f8fbfe; }
  .right { text-align:right; }
  .totals-wrap { display:flex; justify-content:flex-end; margin-bottom:14px; }
  .totals {
    width:min(320px,100%); background:#fff; border:1px solid var(--line); border-radius:10px; overflow:hidden;
  }
  .totals .row { display:flex; justify-content:space-between; gap:12px; padding:9px 12px; font-size:13px; }
  .totals .row + .row { border-top:1px solid #eef2f7; }
  .totals .grand {
    background:var(--rocad-soft); color:var(--rocad-deep); font-weight:800; font-size:15px;
  }
  .pay-qr {
    display:grid; grid-template-columns:1.2fr .8fr; gap:12px; margin-bottom:12px; align-items:stretch;
  }
  .pay-box, .qr-box {
    border:1px solid var(--line); border-radius:10px; padding:12px 14px; background:#fff;
  }
  .pay-box h3, .qr-box h3 {
    margin:0 0 8px; font-size:12px; text-transform:uppercase; letter-spacing:.05em; color:var(--rocad); font-weight:800;
  }
  .paid-badge {
    display:inline-flex; align-items:center; gap:6px; background:#e8f7ef; color:var(--ok);
    border-radius:999px; padding:4px 10px; font-weight:800; font-size:12px;
  }
  .qr-box { display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; }
  .qr-img, #nrsQrBox, #nrsQrBox img, #nrsQrBox canvas {
    width:160px; height:160px; display:block; background:#fff; border:1px solid #e2e8f0;
  }
  .qr-fallback { color:var(--muted); font-size:12px; padding:18px; text-align:center; }
  .qr-caption { margin-top:8px; font-size:11px; color:var(--muted); }
  .verify-bar {
    display:flex; flex-wrap:wrap; gap:10px 18px; align-items:center;
    background:var(--rocad-soft); border:1px solid var(--rocad-mid); border-radius:10px;
    padding:12px 14px; margin-bottom:10px;
  }
  .verify-bar .ok {
    width:22px; height:22px; border-radius:50%; background:var(--rocad); color:#fff;
    display:inline-flex; align-items:center; justify-content:center; font-size:12px; font-weight:800;
  }
  .verify-bar .label { font-size:12px; color:var(--muted); font-weight:700; }
  .verify-bar .code { font-size:13px; font-weight:800; color:var(--rocad-deep); word-break:break-all; }
  .verify-bar a { color:var(--rocad); font-size:12px; font-weight:700; }
  .foot {
    display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap;
    font-size:11px; color:var(--muted); margin-top:6px;
  }
  @media (max-width:820px) {
    .meta-grid, .party-grid, .pay-qr { grid-template-columns:1fr 1fr; }
    .meta-grid { grid-template-columns:1fr 1fr; }
  }
  @media (max-width:640px) {
    .topbar, .party-grid, .pay-qr, .meta-grid { grid-template-columns:1fr; display:grid; }
    .doc-right { text-align:left; }
    .banner { flex-direction:column; align-items:flex-start; }
  }
  @page { size: A4 portrait; margin: 12mm; }
  @media print {
    body { background:#fff; }
    .actions { display:none !important; }
    .sheet-wrap { margin:0; max-width:none; padding:0; }
    .sheet { box-shadow:none; border:0; border-radius:0; padding:8px; }
  }
</style>
</head><body>
<div class="sheet-wrap">
  <div class="actions">
    <button type="button" class="btn-primary" id="nrsDownloadPdf">Download PDF</button>
    <button type="button" class="btn-secondary" id="nrsDownloadHtml">Download HTML</button>
    <button type="button" class="btn-secondary" onclick="window.print()">Print</button>
    <a class="btn-ghost" href="nrs_einvoice_view.php?id=' . (int) $docId . '">Back</a>
  </div>
  <div class="sheet" id="nrsReceiptSheet">
    <div class="topbar">
      <div class="brand">
        <img src="' . nrs_h($logo) . '" alt="ROCAD Construction" onerror="this.onerror=null;this.src=\'/rocad_admin/images/icon.png\';">
        <div>
          <p class="co">ROCAD CONSTRUCTION LIMITED</p>
          <p class="tag">Building and Civil Engineering Contractors</p>
          <p class="addr">No. 50 Sokoto Road, Tarauni GRA, Kano · P.O. Box 247, Kano · RC 456130</p>
        </div>
      </div>
      <div class="doc-right">
        <p class="title">Electronic Tax Invoice</p>
        <p class="sub">Issued under the NRS e-Invoicing System</p>
      </div>
    </div>
    <div class="banner">
      <h1>ROCAD e-Invoice</h1>
      <span class="valid-pill"><span class="dot">✓</span> ' . nrs_h($statusPill) . '</span>
    </div>
    <div class="meta-grid">
      <div class="meta-card"><span class="k">Invoice No.</span><span class="v">' . nrs_h($invoiceNumber !== '' ? $invoiceNumber : '—') . '</span></div>
      <div class="meta-card"><span class="k">Issue Date</span><span class="v">' . nrs_h($issueDisplay) . '</span></div>
      <div class="meta-card"><span class="k">Invoice Type</span><span class="v">' . nrs_h($invoiceTypeLabel) . '</span></div>
    </div>
    <div class="party-grid">
      <div class="party">
        <h2>Seller (Supplier)</h2>
        <div class="name">' . nrs_h($sellerName !== '' ? $sellerName : '—') . '</div>
        ' . $detailLine('Address', $sellerAddrDisplay)
        . $detailLine('TIN', $sellerTin !== '' ? $sellerTin : '—')
        . $detailLine('RC No.', '456130')
        . $detailLine('Email', $sellerEmail) . '
      </div>
      <div class="party">
        <h2>Buyer (Customer)</h2>
        <div class="name">' . nrs_h($buyerName !== '' ? $buyerName : '—') . '</div>
        ' . $detailLine('Address', $buyerAddr)
        . $detailLine('TIN', $buyerTinDisplay)
        . $detailLine('Email', $buyerEmail) . '
      </div>
    </div>
    <table class="lines">
      <thead>
        <tr>
          <th>S/N</th>
          <th>Description of Goods / Services</th>
          <th class="right">Qty</th>
          <th class="right">Unit Price (' . nrs_h($currency === 'NGN' ? '₦' : $currency) . ')</th>
          <th class="right">VAT (' . nrs_h($vatRateLabel) . '%) (' . nrs_h($currency === 'NGN' ? '₦' : $currency) . ')</th>
          <th class="right">Subtotal (' . nrs_h($currency === 'NGN' ? '₦' : $currency) . ')</th>
        </tr>
      </thead>
      <tbody>' . $lineRows . '</tbody>
    </table>
    <div class="totals-wrap">
      <div class="totals">
        <div class="row"><span>Subtotal (Excl. VAT)</span><span>' . $moneyLabel . number_format($taxEx, 2) . '</span></div>
        <div class="row"><span>VAT (' . nrs_h($vatRateLabel) . '%)</span><span>' . $moneyLabel . number_format($vatAmount, 2) . '</span></div>
        <div class="row grand"><span>Grand Total (Incl. VAT)</span><span>' . $moneyLabel . number_format($payable, 2) . '</span></div>
      </div>
    </div>
    <div class="pay-qr">
      <div class="pay-box">
        <h3>Payment Information</h3>
        ' . $detailLine('Method', $paymentMethod)
        . $detailLine('Transaction / Source Ref', $sourceRef !== '' ? $sourceRef : ($invoiceNumber !== '' ? $invoiceNumber : '—'))
        . $detailLine('Payment / Issue Date', $issueDisplay)
        . ($serviceId !== '' ? $detailLine('Service ID', $serviceId) : '')
        . ($businessId !== '' ? $detailLine('Business ID', $businessId) : '') . '
      </div>
      <div class="qr-box">
        <h3>Scan to Verify</h3>
        ' . $qrInner . '
        <div class="qr-caption">Scan with MBS 360 to verify this IRN</div>
      </div>
    </div>
    <div class="verify-bar">
      <span class="ok">✓</span>
      <div>
        <div class="label">Verification / IRN</div>
        <div class="code">' . nrs_h($irn !== '' ? $irn : '—') . '</div>
      </div>
      <div style="margin-left:auto;">
        <div class="label">Manual verification</div>
        <a href="https://e-invoice.nrs.gov.ng/verify" target="_blank" rel="noopener">https://e-invoice.nrs.gov.ng/verify</a>
      </div>
    </div>
    <div class="foot">
      <span>This is a computer-generated e-invoice and is valid without a physical signature.</span>
      <span>ROCAD · NRS ' . nrs_h($env) . ' · ' . nrs_h(nrs_now()) . '</span>
    </div>
  </div>
</div>
<script src="/rocad_admin/dist/js/qrcode.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/html2pdf.js@0.10.1/dist/html2pdf.bundle.min.js"></script>
<script>
(function () {
  var fileBase = ' . json_encode($safeFile) . ';
  var payloadEl = document.getElementById("nrsQrPayload");
  var box = document.getElementById("nrsQrBox");
  if (payloadEl && box && window.QRCode) {
    try {
      box.innerHTML = "";
      new QRCode(box, {
        text: payloadEl.textContent || "",
        width: 160,
        height: 160,
        correctLevel: QRCode.CorrectLevel ? QRCode.CorrectLevel.M : 0
      });
    } catch (e) {}
  }
  function downloadBlob(blob, name) {
    var a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = name;
    document.body.appendChild(a);
    a.click();
    setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
  }
  var pdfBtn = document.getElementById("nrsDownloadPdf");
  if (pdfBtn) {
    pdfBtn.addEventListener("click", function () {
      var sheet = document.getElementById("nrsReceiptSheet");
      if (!sheet || !window.html2pdf) { window.print(); return; }
      html2pdf().set({
        margin: [10, 10, 10, 10],
        filename: fileBase + "-receipt.pdf",
        image: { type: "jpeg", quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true, backgroundColor: "#ffffff", scrollY: 0 },
        jsPDF: { unit: "mm", format: "a4", orientation: "portrait" }
      }).from(sheet).save();
    });
  }
  var htmlBtn = document.getElementById("nrsDownloadHtml");
  if (htmlBtn) {
    htmlBtn.addEventListener("click", function () {
      var html = "<!DOCTYPE html>\\n" + document.documentElement.outerHTML;
      downloadBlob(new Blob([html], { type: "text/html;charset=utf-8" }), fileBase + "-receipt.html");
    });
  }
})();
</script>
</body></html>';
    }
}

if (!function_exists('nrs_log_attempt')) {
    function nrs_log_attempt(
        $db,
        int $docId,
        string $action,
        int $httpStatus,
        $request,
        $response,
        bool $success,
        string $error,
        int $userId
    ): void {
        $reqSql = robe_esc($db, is_string($request) ? $request : (string) json_encode($request));
        $resSql = robe_esc($db, is_string($response) ? $response : (string) json_encode($response));
        $errSql = robe_esc($db, substr($error, 0, 500));
        $actionSql = robe_esc($db, $action);
        $now = nrs_now();
        $okInt = $success ? 1 : 0;
        nrs_query($db, "
            INSERT INTO nrs_einvoice_attempt
                (nrs_einvoice_id, action, http_status, request_json, response_json, success, error_message, created_at, created_by)
            VALUES
                ($docId, '$actionSql', $httpStatus, '$reqSql', '$resSql', $okInt, '$errSql', '$now', $userId)
        ");
    }
}

if (!function_exists('nrs_get_document')) {
    /**
     * @return array<string, mixed>|null
     */
    function nrs_get_document($db, int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $res = nrs_query($db, 'SELECT * FROM nrs_einvoice WHERE id=' . $id . ' LIMIT 1');
        if (!$res) {
            return null;
        }
        $row = robe_fetch($res);
        return $row ?: null;
    }
}

if (!function_exists('nrs_counts')) {
    /**
     * @return array<string, int>
     */
    function nrs_counts($db): array
    {
        $out = array('total' => 0, 'draft' => 0, 'validated' => 0, 'submitted' => 0, 'confirmed' => 0, 'failed' => 0);
        $res = nrs_query($db, 'SELECT status, COUNT(*) AS c FROM nrs_einvoice GROUP BY status');
        if ($res) {
            while ($row = robe_fetch($res)) {
                $status = strtoupper((string) ($row['status'] ?? ''));
                $c = (int) ($row['c'] ?? 0);
                $out['total'] += $c;
                if (in_array($status, array('DRAFT', 'READY'), true)) {
                    $out['draft'] += $c;
                } elseif (in_array($status, array('VALIDATED', 'VALIDATING', 'SIGNED'), true)) {
                    $out['validated'] += $c;
                } elseif (in_array($status, array('QUEUED', 'SUBMITTED', 'SUBMITTING', 'TRANSMITTED'), true)) {
                    $out['submitted'] += $c;
                } elseif ($status === 'CONFIRMED') {
                    $out['confirmed'] += $c;
                } elseif (in_array($status, array('FAILED', 'REJECTED'), true)) {
                    $out['failed'] += $c;
                }
            }
        }
        return $out;
    }
}

if (!function_exists('nrs_apply_gateway_result')) {
    /**
     * Persist provider response fields onto an nrs_einvoice row.
     *
     * @param array<string, mixed> $doc
     * @param array{ok:bool,http_status:int,body:string,json:?array} $response
     * @param string $fallbackStatus Used when response has no status
     */
    function nrs_apply_gateway_result(
        $db,
        int $docId,
        array $doc,
        array $response,
        string $action,
        int $userId,
        string $fallbackStatus = ''
    ): array {
        $now = nrs_now();
        $data = nrs_response_data($response);
        $irn = (string) ($doc['irn'] ?? '');
        if (!empty($data['irn'])) {
            $irn = (string) $data['irn'];
        }
        $providerRef = (string) ($doc['provider_ref'] ?? '');
        if (!empty($data['id'])) {
            $providerRef = (string) $data['id'];
        } elseif (!empty($data['submission_id'])) {
            $providerRef = (string) $data['submission_id'];
        }

        $status = $fallbackStatus;
        if (!empty($data['status'])) {
            $status = nrs_map_provider_status((string) $data['status']);
        }
        if ($status === '') {
            $status = $response['ok'] ? 'QUEUED' : 'FAILED';
        }

        $respSql = robe_esc($db, (string) ($response['body'] ?? ''));
        $irnSql = robe_esc($db, $irn);
        $refSql = robe_esc($db, $providerRef);
        $statusSql = robe_esc($db, $status);

        if ($response['ok']) {
            $extra = '';
            if (in_array($status, array('VALIDATED', 'SIGNED'), true)) {
                $extra .= ", validated_at='$now'";
            }
            if (in_array($status, array('QUEUED', 'SUBMITTED', 'TRANSMITTED', 'CONFIRMED', 'SIGNED'), true) && $action === 'submit') {
                $extra .= ", submitted_at='$now'";
            }
            nrs_query($db, "
                UPDATE nrs_einvoice SET
                    status='$statusSql',
                    irn='$irnSql',
                    provider_ref='$refSql',
                    last_response_json='$respSql',
                    last_error='',
                    updated_at='$now',
                    updated_by=$userId
                    $extra
                WHERE id=$docId
            ");
            return array(
                'ok' => true,
                'status' => $status,
                'irn' => $irn,
                'provider_ref' => $providerRef,
                'message' => ucfirst($action) . ' succeeded (' . nrs_status_label($status) . ').',
            );
        }

        $err = substr(nrs_response_error_message($response), 0, 500);
        if (stripos($err, 'issue from nrs') === false && stripos($err, 'nrs —') === false) {
            $err = 'Issue from NRS — ' . $err;
        }
        $errSql = robe_esc($db, $err);
        nrs_query($db, "
            UPDATE nrs_einvoice SET
                status='FAILED',
                last_error='$errSql',
                last_response_json='$respSql',
                updated_at='$now',
                updated_by=$userId
            WHERE id=$docId
        ");
        $actionLabel = $action !== '' ? ucfirst($action) : 'Request';
        return array(
            'ok' => false,
            'status' => 'FAILED',
            'irn' => $irn,
            'provider_ref' => $providerRef,
            'message' => $actionLabel . ' failed because of an NRS / e-invoice provider problem: ' . $err,
            'from_nrs' => true,
        );
    }
}
