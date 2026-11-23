<?php

class RobeConn
{
}

class RobeResult
{
    /** @var array<int, array<string, mixed>> */
    private $rows;
    /** @var int */
    private $i = 0;

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetch()
    {
        if ($this->i >= count($this->rows)) {
            return null;
        }
        $row = $this->rows[$this->i];
        $this->i++;
        return $row;
    }

    public function count(): int
    {
        return count($this->rows);
    }
}

function robe_conn(): RobeConn
{
    static $conn = null;
    if (!$conn instanceof RobeConn) {
        $conn = new RobeConn();
    }
    return $conn;
}

function robe_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $path = getenv('ROBES_DB');
    if (!is_string($path) || $path === '') {
        $path = ROBES_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'robes.sqlite';
    }
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the storage folder.');
    }
    $pdo = new PDO('sqlite:' . $path, null, null, array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ));
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    return $pdo;
}

/**
 * @param RobeResult|false|null $res
 * @return array<string, mixed>|null
 */
function robe_fetch($res)
{
    if ($res instanceof RobeResult) {
        return $res->fetch();
    }
    return null;
}

/**
 * @param RobeResult|false|null $res
 */
function robe_num($res): int
{
    if ($res instanceof RobeResult) {
        return $res->count();
    }
    return 0;
}

/**
 * Escape a value for inclusion inside single quotes in the ported NRS SQL.
 *
 * @param mixed $db
 */
function robe_esc($db, $value): string
{
    return str_replace("'", "''", (string) $value);
}

function robes_migrate(): void
{
    $pdo = robe_pdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE COLLATE NOCASE,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'operator',
        active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS customers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        tin TEXT NOT NULL DEFAULT '',
        email TEXT NOT NULL DEFAULT '',
        phone TEXT NOT NULL DEFAULT '',
        street TEXT NOT NULL DEFAULT '',
        city TEXT NOT NULL DEFAULT '',
        postal TEXT NOT NULL DEFAULT '',
        state TEXT NOT NULL DEFAULT '',
        lga TEXT NOT NULL DEFAULT '',
        notes TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");
}
