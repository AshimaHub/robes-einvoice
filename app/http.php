<?php

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function robes_base(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $dir = str_replace('\\', '/', dirname($script));
    $dir = rtrim($dir, '/');
    if ($dir === '' || $dir === '/' || $dir === '.') {
        return '';
    }
    return $dir;
}

function robes_url(string $path): string
{
    if ($path === '') {
        $path = '/';
    }
    if ($path[0] !== '/') {
        $path = '/' . $path;
    }
    return robes_base() . $path;
}

function robes_path(): string
{
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $uri = is_string($uri) ? $uri : '/';
    $base = robes_base();
    if ($base !== '' && strpos($uri, $base) === 0) {
        $uri = substr($uri, strlen($base));
    }
    $uri = '/' . trim($uri, '/');
    return $uri === '//' ? '/' : $uri;
}

function robes_redirect(string $path): void
{
    header('Location: ' . robes_url($path));
    exit;
}

function robes_csrf(): string
{
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function robes_csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(robes_csrf()) . '">';
}

function robes_csrf_check(): void
{
    $tok = $_POST['_csrf'] ?? '';
    $real = $_SESSION['csrf'] ?? '';
    if (!is_string($tok) || !is_string($real) || $real === '' || !hash_equals($real, $tok)) {
        http_response_code(419);
        echo 'This form expired. Go back, refresh the page, and try again.';
        exit;
    }
}

function robes_flash(string $type, string $msg): void
{
    $_SESSION['flash'] = array('type' => $type, 'msg' => $msg);
}

function robes_take_flash(): ?array
{
    if (empty($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function robes_settings(bool $reload = false): array
{
    static $cache = null;
    if ($reload || !is_array($cache)) {
        $cache = nrs_get_settings(robe_conn());
    }
    return $cache;
}

function robes_logo_file(): string
{
    foreach (array('png', 'jpg', 'jpeg', 'webp', 'gif') as $ext) {
        $path = ROBES_ROOT . '/storage/brand/logo.' . $ext;
        if (is_file($path)) {
            return $path;
        }
    }
    return '';
}

function robes_brand(?array $settings = null): array
{
    if ($settings === null) {
        $settings = robes_settings();
    }
    $color = (string) ($settings['brand_color'] ?? '#0f6e56');
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
        $color = '#0f6e56';
    }
    $accent = (string) ($settings['brand_accent'] ?? '#c4a15a');
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $accent)) {
        $accent = '#c4a15a';
    }
    $name = trim((string) ($settings['brand_name'] ?? ''));
    if ($name === '') {
        $name = 'Robes eInvoice';
    }
    $tag = trim((string) ($settings['brand_tagline'] ?? ''));
    if ($tag === '') {
        $tag = 'NRS e-invoicing for this business';
    }
    $logo = robes_logo_file();
    return array(
        'name' => $name,
        'tagline' => $tag,
        'color' => $color,
        'accent' => $accent,
        'company' => trim((string) ($settings['seller_name'] ?? '')),
        'env' => (($settings['environment'] ?? '') === 'production') ? 'production' : 'sandbox',
        'logo' => $logo !== '' ? robes_url('/brand-logo') . '?v=' . filemtime($logo) : '',
    );
}

function robes_user(): ?array
{
    static $loaded = false;
    static $user = null;
    if ($loaded) {
        return $user;
    }
    $loaded = true;
    $id = (int) ($_SESSION['uid'] ?? 0);
    if ($id < 1) {
        return null;
    }
    $stmt = robe_pdo()->prepare('SELECT * FROM users WHERE id = ? AND active = 1');
    $stmt->execute(array($id));
    $row = $stmt->fetch();
    $user = is_array($row) ? $row : null;
    if (!$user) {
        unset($_SESSION['uid']);
    }
    return $user;
}

function robes_has_users(): bool
{
    try {
        return (int) robe_pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function robes_require(): array
{
    $user = robes_user();
    if (!$user) {
        robes_redirect('/login');
    }
    return $user;
}

function robes_require_admin(): array
{
    $user = robes_require();
    if (($user['role'] ?? '') !== 'admin') {
        robes_flash('warn', 'Only an administrator can open that page.');
        robes_redirect('/');
    }
    return $user;
}

function robes_active_admins(): int
{
    return (int) robe_pdo()->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1")->fetchColumn();
}

function robes_view(string $name, array $data = array(), bool $bare = false): void
{
    $settings = isset($data['settings']) && is_array($data['settings']) ? $data['settings'] : robes_settings();
    $data['settings'] = $settings;
    $data['brand'] = robes_brand($settings);
    $data['currentUser'] = robes_user();
    $data['flash'] = robes_take_flash();
    $data['bare'] = $bare;
    $data['path'] = robes_path();
    if (!isset($data['pageTitle'])) {
        $data['pageTitle'] = $data['brand']['name'];
    }
    $data['viewFile'] = ROBES_ROOT . '/views/' . $name . '.php';
    extract($data, EXTR_SKIP);
    include ROBES_ROOT . '/views/layout.php';
    exit;
}

function robes_money($amount): string
{
    return '₦' . number_format((float) $amount, 2);
}

function robes_date(string $ymd): string
{
    $ts = strtotime($ymd);
    if (!$ts) {
        return $ymd;
    }
    return date('j M Y', $ts);
}

function robes_parse_date(string $raw): string
{
    $raw = trim($raw);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return $raw;
    }
    if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $raw, $m)) {
        return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
    }
    $ts = strtotime($raw);
    return $ts ? date('Y-m-d', $ts) : '';
}

function robes_editable(array $doc): bool
{
    return in_array(strtoupper((string) ($doc['status'] ?? '')), array('DRAFT', 'READY', 'VALIDATED', 'FAILED', 'REJECTED'), true);
}

function robes_blockers(array $settings): array
{
    $blockers = array();
    if (($settings['enabled'] ?? '') !== '1') {
        $blockers[] = 'Turn on NRS submission in Settings.';
    }
    if (trim((string) ($settings['api_key'] ?? '')) === '' || trim((string) ($settings['api_secret'] ?? '')) === '') {
        $blockers[] = 'Add the NRS API key and API secret.';
    }
    if (trim((string) ($settings['business_id'] ?? '')) === '') {
        $blockers[] = 'Add the NRS business ID.';
    }
    if (trim((string) ($settings['service_id'] ?? '')) === '') {
        $blockers[] = 'Add the NRS service ID.';
    }
    return $blockers;
}

function robes_checklist(array $settings): array
{
    $emailOk = (bool) filter_var((string) ($settings['seller_email'] ?? ''), FILTER_VALIDATE_EMAIL);
    return array(
        array(
            'ok' => trim((string) ($settings['seller_name'] ?? '')) !== '' && trim((string) ($settings['seller_tin'] ?? '')) !== '',
            'label' => 'Company legal name and TIN',
            'href' => '/settings#company',
        ),
        array(
            'ok' => $emailOk
                && trim((string) ($settings['seller_street'] ?? '')) !== ''
                && trim((string) ($settings['seller_city'] ?? '')) !== ''
                && trim((string) ($settings['seller_state'] ?? '')) !== ''
                && trim((string) ($settings['seller_lga'] ?? '')) !== '',
            'label' => 'Company email, street, city, state and LGA',
            'href' => '/settings#company',
        ),
        array(
            'ok' => trim((string) ($settings['api_key'] ?? '')) !== ''
                && trim((string) ($settings['api_secret'] ?? '')) !== ''
                && trim((string) ($settings['business_id'] ?? '')) !== ''
                && trim((string) ($settings['service_id'] ?? '')) !== '',
            'label' => 'NRS key, secret, business ID and service ID',
            'href' => '/settings#nrs',
        ),
        array(
            'ok' => ($settings['enabled'] ?? '') === '1',
            'label' => 'NRS submission turned on',
            'href' => '/settings#nrs',
        ),
        array(
            'ok' => trim((string) ($settings['default_isic'] ?? '')) !== '' && trim((string) ($settings['default_service_category'] ?? '')) !== '',
            'label' => 'ISIC code and service category',
            'href' => '/settings#defaults',
        ),
        array(
            'ok' => trim((string) ($settings['crypto_public_key'] ?? '')) !== '' && trim((string) ($settings['crypto_certificate'] ?? '')) !== '',
            'label' => 'Receipt QR keys',
            'href' => '/settings#nrs',
        ),
    );
}

function robes_state_codes(): array
{
    return array(
        'NG-AB' => 'Abia',
        'NG-AD' => 'Adamawa',
        'NG-AK' => 'Akwa Ibom',
        'NG-AN' => 'Anambra',
        'NG-BA' => 'Bauchi',
        'NG-BY' => 'Bayelsa',
        'NG-BE' => 'Benue',
        'NG-BO' => 'Borno',
        'NG-CR' => 'Cross River',
        'NG-DE' => 'Delta',
        'NG-EB' => 'Ebonyi',
        'NG-ED' => 'Edo',
        'NG-EK' => 'Ekiti',
        'NG-EN' => 'Enugu',
        'NG-FC' => 'Federal Capital Territory',
        'NG-GO' => 'Gombe',
        'NG-IM' => 'Imo',
        'NG-JI' => 'Jigawa',
        'NG-KD' => 'Kaduna',
        'NG-KN' => 'Kano',
        'NG-KT' => 'Katsina',
        'NG-KE' => 'Kebbi',
        'NG-KO' => 'Kogi',
        'NG-KW' => 'Kwara',
        'NG-LA' => 'Lagos',
        'NG-NA' => 'Nasarawa',
        'NG-NI' => 'Niger',
        'NG-OG' => 'Ogun',
        'NG-ON' => 'Ondo',
        'NG-OS' => 'Osun',
        'NG-OY' => 'Oyo',
        'NG-PL' => 'Plateau',
        'NG-RI' => 'Rivers',
        'NG-SO' => 'Sokoto',
        'NG-TA' => 'Taraba',
        'NG-YO' => 'Yobe',
        'NG-ZA' => 'Zamfara',
    );
}

function robes_runtime(): array
{
    return array(
        'php' => PHP_VERSION,
        'curl' => function_exists('curl_init'),
        'openssl' => function_exists('openssl_public_encrypt'),
        'gd' => function_exists('imagepng'),
        'sqlite' => in_array('sqlite', PDO::getAvailableDrivers(), true),
    );
}
