<?php
if (!function_exists('robes_pill')) {
    function robes_pill(string $status): string
    {
        $s = strtoupper($status);
        if (in_array($s, array('CONFIRMED', 'SIGNED', 'VALIDATED'), true)) {
            return 'ok';
        }
        if (in_array($s, array('FAILED', 'REJECTED'), true)) {
            return 'bad';
        }
        if (in_array($s, array('DRAFT', 'READY'), true)) {
            return 'draft';
        }
        return 'mid';
    }
}
$isAdmin = (($currentUser['role'] ?? '') === 'admin');
$cssVer = is_file(ROBES_ROOT . '/public/assets/app.css') ? filemtime(ROBES_ROOT . '/public/assets/app.css') : '1';
$jsVer = is_file(ROBES_ROOT . '/public/assets/app.js') ? filemtime(ROBES_ROOT . '/public/assets/app.js') : '1';
$initial = strtoupper(substr(trim((string) ($currentUser['name'] ?? 'R')), 0, 1));
$wide = ($path ?? '') === '/onboarding';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="csrf" content="<?= e(robes_csrf()) ?>">
    <meta name="app-base" content="<?= e(robes_base()) ?>">
    <title><?= e($pageTitle) ?> · <?= e($brand['name']) ?></title>
    <link rel="icon" href="<?= e(robes_url('/favicon.svg')) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(robes_url('/assets/app.css')) ?>?v=<?= e((string) $cssVer) ?>">
    <style>:root { --brand: <?= e($brand['color']) ?>; --gold: <?= e($brand['accent']) ?>; }</style>
</head>
<body>
<?php if (!empty($bare)): ?>
<div class="auth<?= $wide ? ' auth-wide' : '' ?>">
    <aside class="auth-brand">
        <div class="brand-lockup">
            <?php if ($brand['logo'] !== ''): ?><img class="auth-logo" src="<?= e($brand['logo']) ?>" alt=""><?php else: ?><span class="logo-mark">R</span><?php endif; ?>
            <div>
                <p class="mark">Robes eInvoice</p>
                <strong><?= e($brand['company'] !== '' ? $brand['company'] : $brand['name']) ?></strong>
            </div>
        </div>
        <div class="pitch">
            <h1>Your company’s e-invoice desk.</h1>
            <p class="tag">Register, choose sandbox or production, then issue invoices your customers can verify with NRS.</p>
            <ul class="pitch-points">
                <li>Draft invoices and keep a customer list</li>
                <li>Validate, sign, and confirm with NRS</li>
                <li>Print a receipt with the authenticity QR</li>
            </ul>
        </div>
        <div class="mock-invoice" aria-hidden="true">
            <div class="mock-top"><span></span><span></span></div>
            <strong>Invoice 1042</strong>
            <p>Sample customer</p>
            <div class="mock-row"><span>Professional services</span><b>₦250,000</b></div>
            <em>Signed</em>
        </div>
    </aside>
    <main class="auth-panel">
        <div class="auth-card">
            <?php if (!empty($flash)): ?>
            <div class="flash <?= e((string) ($flash['type'] ?? 'success')) ?>"><?= e((string) ($flash['msg'] ?? '')) ?></div>
            <?php endif; ?>
            <?php include $viewFile; ?>
        </div>
    </main>
</div>
<?php else: ?>
<input id="navToggle" class="nav-toggle" type="checkbox">
<div class="shell">
    <aside class="side">
        <div class="brand-block">
            <div class="brand-lockup">
                <?php if ($brand['logo'] !== ''): ?><img class="side-logo" src="<?= e($brand['logo']) ?>" alt=""><?php else: ?><span class="logo-mark">R</span><?php endif; ?>
                <div>
                    <p class="mark">Robes</p>
                    <strong><?= e($brand['name']) ?></strong>
                </div>
            </div>
            <?php if ($brand['company'] !== ''): ?><p class="company"><?= e($brand['company']) ?></p><?php endif; ?>
            <span class="env <?= $brand['env'] === 'production' ? 'prod' : 'sand' ?>"><?= $brand['env'] === 'production' ? 'Production' : 'Sandbox' ?></span>
        </div>
        <nav class="nav">
            <a class="<?= $path === '/' ? 'active' : '' ?>" href="<?= e(robes_url('/')) ?>">Home</a>
            <a class="<?= strpos($path, '/invoices') === 0 ? 'active' : '' ?>" href="<?= e(robes_url('/invoices')) ?>">Invoices</a>
            <a class="<?= strpos($path, '/customers') === 0 ? 'active' : '' ?>" href="<?= e(robes_url('/customers')) ?>">Customers</a>
            <?php if ($isAdmin): ?>
            <a class="<?= $path === '/settings' ? 'active' : '' ?>" href="<?= e(robes_url('/settings')) ?>">Settings</a>
            <a class="<?= strpos($path, '/users') === 0 ? 'active' : '' ?>" href="<?= e(robes_url('/users')) ?>">Team</a>
            <?php endif; ?>
        </nav>
        <form class="signout" method="post" action="<?= e(robes_url('/logout')) ?>">
            <?= robes_csrf_field() ?>
            <span class="who"><i><?= e($initial) ?></i><?= e((string) ($currentUser['name'] ?? '')) ?></span>
            <button type="submit">Sign out</button>
        </form>
    </aside>
    <div class="main">
        <header class="topbar">
            <label class="burger" for="navToggle">Menu</label>
            <div>
                <h1><?= e($pageTitle) ?></h1>
            </div>
        </header>
        <?php if ($brand['env'] === 'production'): ?>
        <div class="prod-banner">Production is active. Invoices you sign are sent to the live NRS service.</div>
        <?php endif; ?>
        <?php if (!empty($flash)): ?>
        <div class="flash <?= e((string) ($flash['type'] ?? 'success')) ?>"><?= e((string) ($flash['msg'] ?? '')) ?></div>
        <?php endif; ?>
        <div class="content">
            <?php include $viewFile; ?>
        </div>
    </div>
</div>
<datalist id="ng-states">
    <?php foreach (robes_state_codes() as $code => $label): ?>
    <option value="<?= e($code) ?>"><?= e($label) ?></option>
    <?php endforeach; ?>
</datalist>
<?php endif; ?>
<script src="<?= e(robes_url('/assets/app.js')) ?>?v=<?= e((string) $jsVer) ?>"></script>
</body>
</html>
