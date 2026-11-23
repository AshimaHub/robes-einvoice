<?php

function robes_dispatch(): void
{
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
    }
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = robes_path();

    if ($path === '/health' && $method === 'GET') {
        header('Content-Type: text/plain; charset=utf-8');
        echo "ok\n";
        return;
    }

    if ($path === '/brand-logo' && $method === 'GET') {
        robes_send_logo();
        return;
    }

    $installed = robes_has_users();
    if (!$installed && $path !== '/setup') {
        robes_redirect('/setup');
    }
    if ($installed && $path === '/setup') {
        robes_redirect('/login');
    }
    if ($method === 'POST') {
        robes_csrf_check();
    }
    if ($path === '/setup') {
        robes_setup($method);
        return;
    }
    if ($path === '/login') {
        robes_login($method);
        return;
    }

    $user = robes_require();
    if ($path === '/logout' && $method === 'POST') {
        $_SESSION = array();
        session_destroy();
        robes_redirect('/login');
    }
    if ($path === '/onboarding') {
        robes_onboarding($user, $method);
        return;
    }
    if (robes_needs_onboarding($user)) {
        robes_redirect('/onboarding');
    }
    if ($path === '/' && $method === 'GET') {
        robes_dashboard();
        return;
    }
    if ($path === '/environment' && $method === 'POST') {
        robes_switch_environment($user);
        return;
    }
    if ($path === '/invoices' && $method === 'GET') {
        robes_invoice_list();
        return;
    }
    if ($path === '/invoices/new' && $method === 'GET') {
        robes_invoice_edit(0);
        return;
    }
    if ($path === '/invoices/new' && $method === 'POST') {
        robes_invoice_save(0, $user);
        return;
    }
    if ($path === '/invoices/import' && $method === 'POST') {
        robes_invoice_import($user);
        return;
    }
    if (preg_match('#^/invoices/(\d+)$#', $path, $m)) {
        if ($method === 'POST') {
            robes_invoice_action((int) $m[1], $user);
        } else {
            robes_invoice_show((int) $m[1]);
        }
        return;
    }
    if (preg_match('#^/invoices/(\d+)/edit$#', $path, $m)) {
        if ($method === 'POST') {
            robes_invoice_save((int) $m[1], $user);
        } else {
            robes_invoice_edit((int) $m[1]);
        }
        return;
    }
    if ($path === '/customers' && $method === 'GET') {
        robes_customer_list();
        return;
    }
    if ($path === '/customers/new' && $method === 'GET') {
        robes_customer_form(0);
        return;
    }
    if ($path === '/customers/new' && $method === 'POST') {
        robes_customer_save(0);
        return;
    }
    if (preg_match('#^/customers/(\d+)$#', $path, $m)) {
        if ($method === 'POST') {
            robes_customer_save((int) $m[1]);
        } else {
            robes_customer_form((int) $m[1]);
        }
        return;
    }
    if ($path === '/settings') {
        robes_settings_page($user, $method);
        return;
    }
    if ($path === '/users') {
        robes_users_page($user, $method);
        return;
    }
    if (preg_match('#^/users/(\d+)$#', $path, $m) && $method === 'POST') {
        robes_user_update((int) $m[1], $user);
        return;
    }
    if ($path === '/lookup-tin' && $method === 'POST') {
        robes_lookup_tin();
        return;
    }
    http_response_code(404);
    robes_view('notfound', array('pageTitle' => 'Not found'));
}

function robes_send_logo(): void
{
    $file = robes_logo_file();
    if ($file === '') {
        http_response_code(404);
        exit;
    }
    $mime = 'image/png';
    if (function_exists('mime_content_type')) {
        $detected = mime_content_type($file);
        if (is_string($detected) && $detected !== '') {
            $mime = $detected;
        }
    }
    header('Content-Type: ' . $mime);
    header('Cache-Control: private, max-age=3600');
    readfile($file);
    exit;
}

function robes_setup(string $method): void
{
    $errors = array();
    if ($method === 'POST') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $pass = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');
        $company = trim((string) ($_POST['company'] ?? ''));
        if ($name === '') {
            $errors[] = 'Enter your name.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email.';
        }
        if (strlen($pass) < 8) {
            $errors[] = 'Use a password of at least 8 characters.';
        }
        if ($pass !== $confirm) {
            $errors[] = 'The passwords do not match.';
        }
        if ($company === '') {
            $errors[] = 'Enter the company legal name.';
        }
        if (!$errors) {
            $now = nrs_now();
            robe_pdo()->prepare('INSERT INTO users (name, email, password_hash, role, active, created_at) VALUES (?,?,?,?,1,?)')
                ->execute(array($name, $email, password_hash($pass, PASSWORD_DEFAULT), 'admin', $now));
            $uid = (int) robe_pdo()->lastInsertId();
            nrs_save_settings(robe_conn(), array(
                'seller_name' => $company,
                'seller_email' => $email,
                'provider' => 'nrs_direct',
                'onboarding_done' => '0',
                'onboarding_choice' => '',
            ), $uid);
            session_regenerate_id(true);
            $_SESSION['uid'] = $uid;
            robes_settings(true);
            robes_redirect('/onboarding');
        }
    }
    robes_view('setup', array('errors' => $errors, 'pageTitle' => 'Create account'), true);
}

function robes_needs_onboarding(array $user): bool
{
    if (($user['role'] ?? '') !== 'admin') {
        return false;
    }
    $settings = robes_settings();
    return (string) ($settings['onboarding_done'] ?? '0') !== '1';
}

function robes_onboarding(array $user, string $method): void
{
    if (($user['role'] ?? '') !== 'admin') {
        robes_redirect('/');
    }
    $settings = robes_settings(true);
    if ((string) ($settings['onboarding_done'] ?? '0') === '1') {
        robes_redirect('/');
    }
    if ($method === 'POST') {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'choose') {
            $env = (($_POST['environment'] ?? '') === 'production') ? 'production' : 'sandbox';
            nrs_save_settings(robe_conn(), array(
                'environment' => $env,
                'onboarding_choice' => $env,
                'provider' => 'nrs_direct',
            ), (int) $user['id']);
            robes_settings(true);
            robes_redirect('/onboarding');
        }
        if ($action === 'reset') {
            nrs_save_settings(robe_conn(), array('onboarding_choice' => ''), (int) $user['id']);
            robes_settings(true);
            robes_redirect('/onboarding');
        }
        if ($action === 'skip') {
            if (trim((string) ($settings['onboarding_choice'] ?? '')) === '') {
                robes_flash('warn', 'Choose sandbox or production first.');
                robes_redirect('/onboarding');
            }
            nrs_save_settings(robe_conn(), array('onboarding_done' => '1'), (int) $user['id']);
            robes_settings(true);
            robes_flash('success', 'You can add the NRS keys in Settings whenever they are ready. Draft invoices work before that.');
            robes_redirect('/');
        }
        if ($action === 'finish') {
            robes_onboarding_finish($user, $settings);
        }
    }
    $settings = robes_settings(true);
    $choice = (string) ($settings['onboarding_choice'] ?? '');
    if ($choice !== 'sandbox' && $choice !== 'production') {
        $choice = '';
    }
    $profiles = nrs_settings_env_profiles($settings);
    robes_view('onboarding', array(
        'pageTitle' => $choice === '' ? 'Choose NRS' : ($choice === 'production' ? 'Set up production' : 'Set up sandbox'),
        'step' => $choice === '' ? 'choose' : 'connect',
        'choice' => $choice,
        'settings' => $settings,
        'profile' => $choice === '' ? array() : $profiles[$choice],
    ), true);
}

function robes_onboarding_finish(array $user, array $settings): void
{
    $choice = (string) ($settings['onboarding_choice'] ?? '');
    if ($choice !== 'sandbox' && $choice !== 'production') {
        robes_flash('warn', 'Choose sandbox or production first.');
        robes_redirect('/onboarding');
    }
    $profiles = nrs_settings_env_profiles($settings);
    $prefix = $choice === 'production' ? 'prd' : 'sbx';
    $merged = robes_merge_profile($prefix, $profiles[$choice], $choice);
    $notes = $merged['notes'];
    $general = array(
        'seller_name' => trim((string) ($_POST['seller_name'] ?? $settings['seller_name'] ?? '')),
        'seller_tin' => trim((string) ($_POST['seller_tin'] ?? '')),
        'seller_email' => trim((string) ($_POST['seller_email'] ?? '')),
        'seller_phone' => trim((string) ($_POST['seller_phone'] ?? '')),
        'seller_street' => trim((string) ($_POST['seller_street'] ?? '')),
        'seller_city' => trim((string) ($_POST['seller_city'] ?? '')),
        'seller_state' => trim((string) ($_POST['seller_state'] ?? '')),
        'seller_lga' => trim((string) ($_POST['seller_lga'] ?? '')),
        'company_rc' => trim((string) ($_POST['company_rc'] ?? '')),
        'brand_name' => trim((string) ($_POST['brand_name'] ?? '')),
        'default_isic' => trim((string) ($_POST['default_isic'] ?? '')),
        'default_service_category' => trim((string) ($_POST['default_service_category'] ?? '')),
        'environment' => $choice,
        'onboarding_choice' => $choice,
        'onboarding_done' => '1',
        'provider' => 'nrs_direct',
    );
    if ($general['seller_name'] === '') {
        $notes[] = 'The company still needs a legal name.';
        $general['seller_name'] = (string) ($settings['seller_name'] ?? '');
    }
    if ($general['brand_name'] === '') {
        $general['brand_name'] = 'Robes eInvoice';
    }
    $color = trim((string) ($_POST['brand_color'] ?? ''));
    $general['brand_color'] = preg_match('/^#[0-9A-Fa-f]{6}$/', $color) ? $color : (string) ($settings['brand_color'] ?? '#1d4ed8');
    $accent = trim((string) ($_POST['brand_accent'] ?? ''));
    $general['brand_accent'] = preg_match('/^#[0-9A-Fa-f]{6}$/', $accent) ? $accent : (string) ($settings['brand_accent'] ?? '#0f766e');
    $prefixCode = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['invoice_prefix'] ?? '')));
    $general['invoice_prefix'] = $prefixCode !== '' ? substr($prefixCode, 0, 8) : 'RB';
    $vat = str_replace(',', '.', trim((string) ($_POST['vat_rate'] ?? '7.5')));
    $general['vat_rate'] = (is_numeric($vat) && (float) $vat >= 0 && (float) $vat <= 100) ? (string) (0 + $vat) : '7.5';
    if ($general['seller_email'] !== '' && !filter_var($general['seller_email'], FILTER_VALIDATE_EMAIL)) {
        $notes[] = 'Company email does not look valid. NRS will reject invoices until it is corrected.';
    }
    $profile = $merged['profile'];
    $ready = trim((string) ($profile['api_key'] ?? '')) !== ''
        && trim((string) ($profile['api_secret'] ?? '')) !== ''
        && trim((string) ($profile['business_id'] ?? '')) !== ''
        && trim((string) ($profile['service_id'] ?? '')) !== '';
    $general['enabled'] = $ready ? '1' : '0';
    $values = $general;
    $profiles[$choice] = $profile;
    $values['profile_sandbox'] = nrs_encode_connection_profile($profiles['sandbox']);
    $values['profile_production'] = nrs_encode_connection_profile($profiles['production']);
    foreach (nrs_connection_profile_keys() as $key) {
        $values[$key] = (string) ($profile[$key] ?? '');
    }
    nrs_save_settings(robe_conn(), $values, (int) $user['id']);
    robes_settings(true);
    $label = $choice === 'production' ? 'Production' : 'Sandbox';
    $message = $ready
        ? $label . ' is connected. You can issue an invoice and send it to NRS.'
        : $label . ' is selected. Add the API key, secret, business ID, and service ID in Settings before sending an invoice.';
    if ($notes) {
        $message .= ' ' . implode(' ', $notes);
    }
    robes_flash($ready ? 'success' : 'warn', $message);
    robes_redirect('/');
}

function robes_login(string $method): void
{
    if (robes_user()) {
        robes_redirect('/');
    }
    $error = '';
    if ($method === 'POST') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $pass = (string) ($_POST['password'] ?? '');
        $stmt = robe_pdo()->prepare('SELECT * FROM users WHERE email = ? AND active = 1');
        $stmt->execute(array($email));
        $row = $stmt->fetch();
        if (is_array($row) && password_verify($pass, (string) $row['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['uid'] = (int) $row['id'];
            robes_redirect(robes_needs_onboarding($row) ? '/onboarding' : '/');
        }
        $error = 'Email or password is wrong.';
    }
    robes_view('login', array('error' => $error, 'pageTitle' => 'Sign in'), true);
}

function robes_dashboard(): void
{
    $settings = robes_settings(true);
    $counts = nrs_counts(robe_conn());
    $recent = robe_pdo()->query('SELECT * FROM nrs_einvoice ORDER BY id DESC LIMIT 8')->fetchAll();
    robes_view('dashboard', array(
        'pageTitle' => 'Overview',
        'settings' => $settings,
        'counts' => $counts,
        'recent' => is_array($recent) ? $recent : array(),
        'checklist' => robes_checklist($settings),
    ));
}

function robes_switch_environment(array $user): void
{
    if (($user['role'] ?? '') !== 'admin') {
        robes_flash('warn', 'Only an administrator can change sandbox or production.');
        robes_redirect('/');
    }
    $env = (($_POST['environment'] ?? '') === 'production') ? 'production' : 'sandbox';
    nrs_save_settings(robe_conn(), array('environment' => $env), (int) $user['id']);
    robes_settings(true);
    robes_flash('success', $env === 'production' ? 'Active mode is production.' : 'Active mode is sandbox.');
    robes_redirect('/');
}

function robes_invoice_list(): void
{
    $q = trim((string) ($_GET['q'] ?? ''));
    $bucket = trim((string) ($_GET['bucket'] ?? ''));
    $status = strtoupper(trim((string) ($_GET['status'] ?? '')));
    if (strlen($q) > 80) {
        $q = substr($q, 0, 80);
    }
    robes_view('invoices', array(
        'pageTitle' => 'Invoices',
        'rows' => robes_invoice_query($q, $bucket, $status),
        'q' => $q,
        'bucket' => $bucket,
        'status' => $status,
        'counts' => nrs_counts(robe_conn()),
    ));
}

function robes_invoice_edit(int $id): void
{
    $settings = robes_settings();
    $doc = null;
    $payload = array();
    if ($id > 0) {
        $doc = nrs_get_document(robe_conn(), $id);
        if (!$doc) {
            http_response_code(404);
            robes_view('notfound', array('pageTitle' => 'Invoice not found'));
        }
        if (!robes_editable($doc)) {
            robes_flash('warn', 'This invoice is locked because it has already gone to NRS.');
            robes_redirect('/invoices/' . $id);
        }
        $decoded = json_decode((string) ($doc['payload_json'] ?? ''), true);
        $payload = is_array($decoded) ? $decoded : array();
    }
    $customers = robe_pdo()->query('SELECT * FROM customers ORDER BY name COLLATE NOCASE')->fetchAll();
    robes_view('invoice_form', array(
        'pageTitle' => $id > 0 ? 'Edit invoice' : 'New invoice',
        'doc' => $doc,
        'payload' => $payload,
        'settings' => $settings,
        'customers' => is_array($customers) ? $customers : array(),
    ));
}

function robes_invoice_save(int $id, array $user): void
{
    $result = robes_store_from_post($id, $_POST, (int) $user['id']);
    if (!empty($result['missing'])) {
        robes_flash('danger', 'Invoice not found.');
        robes_redirect('/invoices');
    }
    if (!empty($result['locked'])) {
        robes_flash('warn', $result['errors'][0] ?? 'This invoice is locked.');
        robes_redirect('/invoices/' . $id);
    }
    if (!empty($result['ok'])) {
        robes_flash('success', 'Invoice saved and ready to validate.');
        robes_redirect('/invoices/' . (int) $result['id']);
    }
    robes_flash('warn', 'Saved as a draft. ' . implode(' ', $result['errors']));
    robes_redirect('/invoices/' . (int) $result['id'] . '/edit');
}

function robes_invoice_import(array $user): void
{
    if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
        robes_flash('danger', 'Choose a CSV file first.');
        robes_redirect('/invoices');
    }
    if ((int) ($_FILES['csv']['size'] ?? 0) > 2000000) {
        robes_flash('danger', 'That CSV is larger than 2 MB.');
        robes_redirect('/invoices');
    }
    robes_flash('success', robes_import_csv((string) $_FILES['csv']['tmp_name'], (int) $user['id']));
    robes_redirect('/invoices');
}

function robes_invoice_show(int $id): void
{
    $doc = nrs_get_document(robe_conn(), $id);
    if (!$doc) {
        http_response_code(404);
        robes_view('notfound', array('pageTitle' => 'Invoice not found'));
    }
    $payload = json_decode((string) ($doc['payload_json'] ?? ''), true);
    $attempts = robe_pdo()->prepare('SELECT * FROM nrs_einvoice_attempt WHERE nrs_einvoice_id = ? ORDER BY id DESC LIMIT 20');
    $attempts->execute(array($id));
    $attemptRows = $attempts->fetchAll();
    $creator = null;
    if ((int) ($doc['created_by'] ?? 0) > 0) {
        $stmt = robe_pdo()->prepare('SELECT name FROM users WHERE id = ?');
        $stmt->execute(array((int) $doc['created_by']));
        $creator = $stmt->fetchColumn();
    }
    robes_view('invoice_show', array(
        'pageTitle' => (string) ($doc['invoice_number'] ?? 'Invoice'),
        'doc' => $doc,
        'payload' => is_array($payload) ? $payload : array(),
        'attempts' => is_array($attemptRows) ? $attemptRows : array(),
        'creator' => is_string($creator) ? $creator : '',
        'blockers' => robes_blockers(robes_settings()),
    ));
}

function robes_invoice_action(int $id, array $user): void
{
    $doc = nrs_get_document(robe_conn(), $id);
    if (!$doc) {
        robes_flash('danger', 'Invoice not found.');
        robes_redirect('/invoices');
    }
    $action = (string) ($_POST['action'] ?? '');
    $uid = (int) $user['id'];
    $settings = robes_settings(true);
    $nrsActions = array('validate', 'submit', 'confirm', 'receipt', 'download');
    if (in_array($action, $nrsActions, true)) {
        $blockers = robes_blockers($settings);
        if ($blockers) {
            robes_flash('warn', implode(' ', $blockers));
            robes_redirect('/invoices/' . $id);
        }
    }
    if ($action === 'delete') {
        if (($user['role'] ?? '') !== 'admin') {
            robes_flash('warn', 'Only an administrator can delete an invoice.');
            robes_redirect('/invoices/' . $id);
        }
        nrs_delete_document(robe_conn(), $id);
        robes_flash('success', 'Invoice deleted from this workspace. Nothing was cancelled on NRS.');
        robes_redirect('/invoices');
    }
    if ($action === 'preview') {
        robes_preview_invoice($doc);
    }
    if ($action === 'retry_irn') {
        $result = robes_retry_irn($doc, $uid);
        robes_flash(!empty($result['ok']) ? 'success' : 'warn', (string) $result['message']);
        robes_redirect('/invoices/' . $id);
    }
    if ($action === 'validate' || $action === 'submit') {
        if (!robes_editable($doc)) {
            robes_flash('warn', 'This invoice can no longer be sent.');
            robes_redirect('/invoices/' . $id);
        }
        $result = robes_push($doc, $action, $uid);
        robes_flash(!empty($result['ok']) ? 'success' : 'danger', (string) ($result['message'] ?? 'NRS did not accept the invoice.'));
        robes_redirect('/invoices/' . $id);
    }
    if ($action === 'confirm') {
        $result = robes_confirm($doc, $uid);
        robes_flash(!empty($result['ok']) ? 'success' : 'danger', (string) $result['message']);
        robes_redirect('/invoices/' . $id);
    }
    if ($action === 'receipt' || $action === 'download') {
        $fetched = robes_fetch_signed($doc, $action, $uid);
        if (!empty($fetched['redirect'])) {
            header('Location: ' . $fetched['redirect']);
            exit;
        }
        if (!$fetched['ok']) {
            robes_flash('danger', (string) $fetched['message']);
            robes_redirect('/invoices/' . $id);
        }
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($doc['irn'] ?? 'invoice'));
        if ($safe === '') {
            $safe = 'invoice-' . $id;
        }
        if ($fetched['pdf'] !== null) {
            if ($action === 'receipt') {
                robes_flash('warn', 'NRS returned a PDF. Use Download signed file.');
                robes_redirect('/invoices/' . $id);
            }
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $safe . '.pdf"');
            echo $fetched['pdf'];
            exit;
        }
        if ($action === 'download') {
            $out = (string) $fetched['plain'];
            $pretty = json_decode($out, true);
            if (is_array($pretty)) {
                $encoded = json_encode($pretty, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (is_string($encoded)) {
                    $out = $encoded;
                }
            }
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $safe . '-signed.json"');
            echo $out;
            exit;
        }
        $inv = is_array($fetched['invoice']) ? $fetched['invoice'] : array();
        if ($inv === array() && $fetched['plain'] !== '') {
            $decoded = json_decode((string) $fetched['plain'], true);
            if (is_array($decoded)) {
                $inv = $decoded;
            }
        }
        header('Content-Type: text/html; charset=utf-8');
        echo nrs_render_receipt_html($doc, $inv, robes_settings(true), $id, robe_conn());
        exit;
    }
    robes_flash('warn', 'That action is not available.');
    robes_redirect('/invoices/' . $id);
}

function robes_customer_list(): void
{
    $rows = robe_pdo()->query('SELECT * FROM customers ORDER BY name COLLATE NOCASE')->fetchAll();
    robes_view('customers', array(
        'pageTitle' => 'Customers',
        'rows' => is_array($rows) ? $rows : array(),
    ));
}

function robes_customer_form(int $id): void
{
    $customer = null;
    if ($id > 0) {
        $stmt = robe_pdo()->prepare('SELECT * FROM customers WHERE id = ?');
        $stmt->execute(array($id));
        $customer = $stmt->fetch();
        if (!is_array($customer)) {
            http_response_code(404);
            robes_view('notfound', array('pageTitle' => 'Customer not found'));
        }
    }
    robes_view('customer_form', array(
        'pageTitle' => $customer ? 'Edit customer' : 'New customer',
        'customer' => is_array($customer) ? $customer : array(),
    ));
}

function robes_customer_save(int $id): void
{
    if (($_POST['action'] ?? '') === 'delete' && $id > 0) {
        robe_pdo()->prepare('DELETE FROM customers WHERE id = ?')->execute(array($id));
        robes_flash('success', 'Customer removed. Invoices already saved still keep the buyer details.');
        robes_redirect('/customers');
    }
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        robes_flash('warn', 'A customer needs a name.');
        robes_redirect($id > 0 ? '/customers/' . $id : '/customers/new');
    }
    $now = nrs_now();
    $values = array(
        $name,
        trim((string) ($_POST['tin'] ?? '')),
        trim((string) ($_POST['email'] ?? '')),
        trim((string) ($_POST['phone'] ?? '')),
        trim((string) ($_POST['street'] ?? '')),
        trim((string) ($_POST['city'] ?? '')),
        trim((string) ($_POST['postal'] ?? '')),
        trim((string) ($_POST['state'] ?? '')),
        trim((string) ($_POST['lga'] ?? '')),
        trim((string) ($_POST['notes'] ?? '')),
        $now,
    );
    if ($id > 0) {
        $values[] = $id;
        robe_pdo()->prepare('UPDATE customers SET name=?, tin=?, email=?, phone=?, street=?, city=?, postal=?, state=?, lga=?, notes=?, updated_at=? WHERE id=?')->execute($values);
        robes_flash('success', 'Customer saved.');
        robes_redirect('/customers/' . $id);
    }
    $values[] = $now;
    robe_pdo()->prepare('INSERT INTO customers (name, tin, email, phone, street, city, postal, state, lga, notes, updated_at, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute($values);
    robes_flash('success', 'Customer saved.');
    robes_redirect('/customers/' . (int) robe_pdo()->lastInsertId());
}

function robes_lookup_tin(): void
{
    $tin = trim((string) ($_POST['tin'] ?? ''));
    $role = (($_POST['role'] ?? '') === 'seller') ? 'seller' : 'buyer';
    $settings = robes_settings(true);
    $result = nrs_lookup_tin_party(robe_conn(), $settings, $tin, $role);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result);
    exit;
}

function robes_settings_page(array $user, string $method): void
{
    if (($user['role'] ?? '') !== 'admin') {
        robes_flash('warn', 'Only an administrator can change settings.');
        robes_redirect('/');
    }
    if ($method === 'POST') {
        robes_settings_save($user);
    }
    $settings = robes_settings(true);
    robes_view('settings', array(
        'pageTitle' => 'Settings',
        'settings' => $settings,
        'profiles' => nrs_settings_env_profiles($settings),
        'runtime' => robes_runtime(),
    ));
}

function robes_merge_profile(string $prefix, array $existing, string $environment): array
{
    $out = $existing;
    $notes = array();
    if (!empty($_POST[$prefix . '_clear_crypto'])) {
        $out['crypto_public_key'] = '';
        $out['crypto_certificate'] = '';
    }
    foreach (nrs_connection_profile_keys() as $key) {
        $field = $prefix . '_' . $key;
        if (!array_key_exists($field, $_POST)) {
            continue;
        }
        $value = trim((string) $_POST[$field]);
        if (in_array($key, array('api_key', 'api_secret', 'crypto_public_key', 'crypto_certificate'), true) && $value === '') {
            continue;
        }
        if ($key === 'api_key' || $key === 'api_secret') {
            $stripped = preg_replace('/\s+/', '', $value);
            $value = is_string($stripped) ? $stripped : '';
        }
        $out[$key] = $value;
    }
    $paste = trim((string) ($_POST[$prefix . '_crypto_paste'] ?? ''));
    if ($paste !== '') {
        $parsed = nrs_parse_crypto_keys_file($paste);
        if ($parsed['public_key'] !== '') {
            $out['crypto_public_key'] = $parsed['public_key'];
        }
        if ($parsed['certificate'] !== '') {
            $out['crypto_certificate'] = $parsed['certificate'];
        }
        if (empty($parsed['ok'])) {
            $notes[] = ($environment === 'production' ? 'Production' : 'Sandbox') . ' keys: ' . $parsed['error'];
        }
    }
    $url = nrs_normalize_base_url((string) ($out['base_url'] ?? ''), 'nrs_direct');
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        $url = nrs_default_base_url_for_env('nrs_direct', $environment);
        $notes[] = ucfirst($environment) . ' address was reset to the NRS default.';
    }
    $out['base_url'] = $url;
    return array('profile' => $out, 'notes' => $notes);
}

function robes_settings_save(array $user): void
{
    $settings = robes_settings(true);
    $profiles = nrs_settings_env_profiles($settings);
    $sandbox = robes_merge_profile('sbx', $profiles['sandbox'], 'sandbox');
    $production = robes_merge_profile('prd', $profiles['production'], 'production');
    $notes = array_merge($sandbox['notes'], $production['notes']);
    $general = array(
        'seller_name' => trim((string) ($_POST['seller_name'] ?? '')),
        'seller_tin' => trim((string) ($_POST['seller_tin'] ?? '')),
        'seller_email' => trim((string) ($_POST['seller_email'] ?? '')),
        'seller_phone' => trim((string) ($_POST['seller_phone'] ?? '')),
        'seller_desc' => trim((string) ($_POST['seller_desc'] ?? '')),
        'seller_street' => trim((string) ($_POST['seller_street'] ?? '')),
        'seller_city' => trim((string) ($_POST['seller_city'] ?? '')),
        'seller_postal' => trim((string) ($_POST['seller_postal'] ?? '')),
        'seller_state' => trim((string) ($_POST['seller_state'] ?? '')),
        'seller_lga' => trim((string) ($_POST['seller_lga'] ?? '')),
        'company_rc' => trim((string) ($_POST['company_rc'] ?? '')),
        'brand_name' => trim((string) ($_POST['brand_name'] ?? '')),
        'brand_tagline' => trim((string) ($_POST['brand_tagline'] ?? '')),
        'default_isic' => trim((string) ($_POST['default_isic'] ?? '')),
        'default_service_category' => trim((string) ($_POST['default_service_category'] ?? '')),
    );
    if ($general['brand_name'] === '') {
        $general['brand_name'] = 'Robes eInvoice';
    }
    if ($general['brand_tagline'] === '') {
        $general['brand_tagline'] = 'NRS e-invoicing for this business';
    }
    if ($general['seller_desc'] === '') {
        $general['seller_desc'] = 'Goods and services';
    }
    $color = trim((string) ($_POST['brand_color'] ?? ''));
    $general['brand_color'] = preg_match('/^#[0-9A-Fa-f]{6}$/', $color) ? $color : (string) ($settings['brand_color'] ?? '#1d4ed8');
    $accent = trim((string) ($_POST['brand_accent'] ?? ''));
    $general['brand_accent'] = preg_match('/^#[0-9A-Fa-f]{6}$/', $accent) ? $accent : (string) ($settings['brand_accent'] ?? '#0f766e');
    $prefix = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['invoice_prefix'] ?? '')));
    $general['invoice_prefix'] = $prefix !== '' ? substr($prefix, 0, 8) : 'RB';
    $vat = str_replace(',', '.', trim((string) ($_POST['vat_rate'] ?? '7.5')));
    $general['vat_rate'] = (is_numeric($vat) && (float) $vat >= 0 && (float) $vat <= 100) ? (string) (0 + $vat) : '7.5';
    $kind = strtoupper(trim((string) ($_POST['invoice_kind'] ?? 'B2B')));
    $general['invoice_kind'] = in_array($kind, array('B2B', 'B2C', 'B2G', 'G2B'), true) ? $kind : 'B2B';
    $code = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['invoice_type_code'] ?? '381')));
    $general['invoice_type_code'] = $code !== '' ? substr((string) $code, 0, 8) : '381';
    $general['environment'] = (($_POST['environment'] ?? '') === 'production') ? 'production' : 'sandbox';
    $general['enabled'] = isset($_POST['enabled']) ? '1' : '0';
    $general['provider'] = 'nrs_direct';
    if ($general['seller_email'] !== '' && !filter_var($general['seller_email'], FILTER_VALIDATE_EMAIL)) {
        $notes[] = 'Company email does not look valid. NRS will reject invoices until it is corrected.';
    }
    $active = $general['environment'] === 'production' ? $production['profile'] : $sandbox['profile'];
    $values = $general;
    $values['profile_sandbox'] = nrs_encode_connection_profile($sandbox['profile']);
    $values['profile_production'] = nrs_encode_connection_profile($production['profile']);
    foreach (nrs_connection_profile_keys() as $key) {
        $values[$key] = (string) ($active[$key] ?? '');
    }
    nrs_save_settings(robe_conn(), $values, (int) $user['id']);
    robes_store_logo($notes);
    $fresh = robes_settings(true);
    if (trim((string) $fresh['crypto_public_key']) !== '' && trim((string) $fresh['crypto_certificate']) !== '') {
        $sid = trim((string) $fresh['service_id']);
        $sid = $sid !== '' ? preg_replace('/[^A-Za-z0-9]/', '', $sid) : 'SERVICE';
        $stamp = nrs_build_qr_stamp('TESTIRN-' . $sid . '-' . date('Ymd'), $fresh);
        $notes[] = !empty($stamp['ok']) ? 'QR stamp checks out.' : ('QR stamp: ' . (string) ($stamp['error'] ?? 'failed'));
    }
    $message = 'Settings saved.';
    $tested = array('ok' => true);
    if (($_POST['intent'] ?? '') === 'test') {
        $probe = $fresh;
        $probe['enabled'] = '1';
        $probe['provider'] = 'nrs_direct';
        if (trim((string) ($probe['api_key'] ?? '')) === '' || trim((string) ($probe['api_secret'] ?? '')) === '') {
            $tested = array('ok' => false, 'message' => 'Add an API key and secret in the active mode before testing.');
        } else {
            $tested = nrs_test_api_connection($probe);
        }
        $message .= ' ' . (string) ($tested['message'] ?? '');
    }
    if ($notes) {
        $message .= ' ' . implode(' ', $notes);
    }
    robes_flash(($_POST['intent'] ?? '') === 'test' && empty($tested['ok']) ? 'warn' : 'success', $message);
    robes_redirect('/settings');
}

function robes_store_logo(array &$notes): void
{
    if (empty($_FILES['logo']['tmp_name']) || !is_uploaded_file($_FILES['logo']['tmp_name'])) {
        return;
    }
    if ((int) ($_FILES['logo']['size'] ?? 0) > 2000000) {
        $notes[] = 'Logo was larger than 2 MB and was not saved.';
        return;
    }
    $info = @getimagesize($_FILES['logo']['tmp_name']);
    $map = array(
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_GIF => 'gif',
    );
    $type = is_array($info) ? (int) ($info[2] ?? 0) : 0;
    if (!isset($map[$type])) {
        $notes[] = 'Logo must be a PNG, JPEG, WebP, or GIF.';
        return;
    }
    $dir = ROBES_ROOT . '/storage/brand';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    foreach (array('png', 'jpg', 'jpeg', 'webp', 'gif') as $ext) {
        $old = $dir . '/logo.' . $ext;
        if (is_file($old)) {
            unlink($old);
        }
    }
    $dest = $dir . '/logo.' . $map[$type];
    if (!move_uploaded_file($_FILES['logo']['tmp_name'], $dest)) {
        $notes[] = 'Logo could not be stored.';
        return;
    }
    $notes[] = 'Logo updated.';
}

function robes_users_page(array $user, string $method): void
{
    if (($user['role'] ?? '') !== 'admin') {
        robes_flash('warn', 'Only an administrator can manage the team.');
        robes_redirect('/');
    }
    if ($method === 'POST') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $pass = (string) ($_POST['password'] ?? '');
        $role = (($_POST['role'] ?? '') === 'admin') ? 'admin' : 'operator';
        $errors = array();
        if ($name === '') {
            $errors[] = 'Enter a name.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email.';
        }
        if (strlen($pass) < 8) {
            $errors[] = 'Use a password of at least 8 characters.';
        }
        $exists = robe_pdo()->prepare('SELECT id FROM users WHERE email = ?');
        $exists->execute(array($email));
        if ($exists->fetchColumn()) {
            $errors[] = 'That email is already on the team.';
        }
        if ($errors) {
            robes_flash('warn', implode(' ', $errors));
            robes_redirect('/users');
        }
        robe_pdo()->prepare('INSERT INTO users (name, email, password_hash, role, active, created_at) VALUES (?,?,?,?,1,?)')
            ->execute(array($name, $email, password_hash($pass, PASSWORD_DEFAULT), $role, nrs_now()));
        robes_flash('success', $name . ' can sign in now.');
        robes_redirect('/users');
    }
    $rows = robe_pdo()->query('SELECT id, name, email, role, active, created_at FROM users ORDER BY name COLLATE NOCASE')->fetchAll();
    robes_view('users', array(
        'pageTitle' => 'Team',
        'rows' => is_array($rows) ? $rows : array(),
    ));
}

function robes_user_update(int $id, array $actor): void
{
    if (($actor['role'] ?? '') !== 'admin') {
        robes_flash('warn', 'Only an administrator can manage the team.');
        robes_redirect('/');
    }
    $stmt = robe_pdo()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute(array($id));
    $row = $stmt->fetch();
    if (!is_array($row)) {
        robes_flash('danger', 'That person was not found.');
        robes_redirect('/users');
    }
    $role = (($_POST['role'] ?? '') === 'admin') ? 'admin' : 'operator';
    $active = isset($_POST['active']) ? 1 : 0;
    $isLastAdmin = ($row['role'] === 'admin' && (int) $row['active'] === 1 && robes_active_admins() < 2);
    if ($isLastAdmin && ($role !== 'admin' || $active !== 1)) {
        robes_flash('warn', 'Keep at least one active administrator.');
        robes_redirect('/users');
    }
    $pass = (string) ($_POST['password'] ?? '');
    if ($pass !== '' && strlen($pass) < 8) {
        robes_flash('warn', 'A new password needs at least 8 characters.');
        robes_redirect('/users');
    }
    if ($pass !== '') {
        robe_pdo()->prepare('UPDATE users SET role=?, active=?, password_hash=? WHERE id=?')
            ->execute(array($role, $active, password_hash($pass, PASSWORD_DEFAULT), $id));
    } else {
        robe_pdo()->prepare('UPDATE users SET role=?, active=? WHERE id=?')->execute(array($role, $active, $id));
    }
    robes_flash('success', 'Team member updated.');
    robes_redirect('/users');
}
