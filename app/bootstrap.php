<?php

define('ROBES_ROOT', dirname(__DIR__));
date_default_timezone_set('Africa/Lagos');

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo "Robes eInvoice needs the PHP pdo_sqlite extension.\n";
    echo "Start the app with start.bat, which turns that extension on.\n";
    exit(1);
}

require __DIR__ . '/db.php';
require __DIR__ . '/http.php';
require __DIR__ . '/nrs_bridge.php';
require __DIR__ . '/receipt.php';
require __DIR__ . '/nrs/nrs_einvoice.lib.php';
require __DIR__ . '/invoices.php';

function robes_exception(Throwable $e): void
{
    $dir = ROBES_ROOT . DIRECTORY_SEPARATOR . 'storage';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents(
        $dir . DIRECTORY_SEPARATOR . 'error.log',
        date('c') . ' ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n",
        FILE_APPEND
    );
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><meta charset="utf-8"><title>Robes eInvoice</title><p>Something went wrong. The detail was written to storage/error.log.</p>';
    exit;
}

try {
    nrs_ensure_tables(robe_conn());
    robes_migrate();
} catch (Throwable $e) {
    robes_exception($e);
}

if (PHP_SAPI !== 'cli') {
    ini_set('session.use_strict_mode', '1');
    session_name('robes_einvoice');
    session_set_cookie_params(array(
        'httponly' => true,
        'samesite' => 'Lax',
        'path' => '/',
    ));
    session_start();
    @set_time_limit(120);
}
