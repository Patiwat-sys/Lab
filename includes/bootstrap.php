<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

const DB_PATH = __DIR__ . '/../storage/lab.sqlite';
const DEFAULT_TIMEZONE = 'Asia/Bangkok';

date_default_timezone_set(DEFAULT_TIMEZONE);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!is_dir(dirname(DB_PATH))) {
        mkdir(dirname(DB_PATH), 0777, true);
    }

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');

    initializeDatabase($pdo);

    static $tankUnitsReady = false;
    if (!$tankUnitsReady) {
        gasRefreshTankInventoryFromEntries($pdo);
        $tankUnitsReady = true;
    }

    return $pdo;
}

function initializeDatabase(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT "member",
            created_at TEXT NOT NULL,
            last_login_at TEXT
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS coal_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            period TEXT NOT NULL,
            sample INTEGER DEFAULT 0,
            tm REAL,
            ash REAL,
            cv REAL,
            sulfur REAL,
            record_date TEXT NOT NULL,
            created_by INTEGER,
            created_at TEXT NOT NULL,
            FOREIGN KEY (created_by) REFERENCES users(id)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS limestone_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            period TEXT NOT NULL,
            sample INTEGER DEFAULT 0,
            m_power REAL,
            caco_power REAL,
            m_auto REAL,
            caco_auto REAL,
            record_date TEXT NOT NULL,
            created_by INTEGER,
            created_at TEXT NOT NULL,
            FOREIGN KEY (created_by) REFERENCES users(id)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS gas_consumption (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            usage_date TEXT NOT NULL,
            cylinder_code TEXT NOT NULL,
            gas_type TEXT NOT NULL,
            opening_kg REAL,
            closing_kg REAL,
            used_kg REAL NOT NULL,
            note TEXT,
            created_by INTEGER,
            created_at TEXT NOT NULL,
            FOREIGN KEY (created_by) REFERENCES users(id)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS external_links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            category TEXT NOT NULL,
            title TEXT NOT NULL,
            url TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS activity_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            action TEXT NOT NULL,
            module TEXT NOT NULL,
            detail TEXT,
            ip_address TEXT,
            created_at TEXT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS gas_monthly_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            year_month TEXT NOT NULL,
            gas_type TEXT NOT NULL,
            opening_remain INTEGER NOT NULL DEFAULT 0,
            po_order INTEGER NOT NULL DEFAULT 0,
            po_received INTEGER NOT NULL DEFAULT 0,
            created_by INTEGER,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            UNIQUE(year_month, gas_type),
            FOREIGN KEY (created_by) REFERENCES users(id)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS gas_daily_adding (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            monthly_id INTEGER NOT NULL,
            day_of_month INTEGER NOT NULL,
            quantity INTEGER NOT NULL DEFAULT 1,
            FOREIGN KEY (monthly_id) REFERENCES gas_monthly_records(id) ON DELETE CASCADE
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS gas_daily_usage (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            monthly_id INTEGER NOT NULL,
            equipment TEXT NOT NULL,
            day_of_month INTEGER NOT NULL,
            quantity INTEGER NOT NULL DEFAULT 1,
            FOREIGN KEY (monthly_id) REFERENCES gas_monthly_records(id) ON DELETE CASCADE,
            UNIQUE(monthly_id, equipment, day_of_month)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS gas_tank_inventory (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            gas_type TEXT UNIQUE NOT NULL,
            total_tanks INTEGER NOT NULL DEFAULT 0,
            full_tanks INTEGER NOT NULL DEFAULT 0,
            empty_tanks INTEGER NOT NULL DEFAULT 0,
            in_use_tanks INTEGER NOT NULL DEFAULT 0,
            remain_stock INTEGER NOT NULL DEFAULT 0,
            updated_by INTEGER,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (updated_by) REFERENCES users(id)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS gas_tank_units (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            gas_type TEXT NOT NULL,
            tank_no INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT "empty",
            updated_at TEXT NOT NULL,
            UNIQUE(gas_type, tank_no)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS gas_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            gas_type TEXT NOT NULL,
            record_date TEXT NOT NULL,
            entry_type TEXT NOT NULL DEFAULT "usage",
            quantity INTEGER NOT NULL DEFAULT 1,
            note TEXT,
            created_by INTEGER,
            created_at TEXT NOT NULL,
            FOREIGN KEY (created_by) REFERENCES users(id)
        )'
    );

    migrateGasSchema($pdo);
    ensureAppSettingsTable($pdo);

    ensureDefaultAdmin($pdo);
    seedFromLegacyFiles($pdo);
    ensureGasTankInventory($pdo);
}

function nowIso(): string
{
    return date('Y-m-d H:i:s');
}

function ensureAppSettingsTable(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS app_settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL DEFAULT "",
            updated_at TEXT NOT NULL
        )'
    );
}

function appSetting(string $key, string $default = ''): string
{
    $stmt = db()->prepare('SELECT value FROM app_settings WHERE key = :key LIMIT 1');
    $stmt->execute([':key' => $key]);
    $row = $stmt->fetch();

    return $row ? (string) ($row['value'] ?? $default) : $default;
}

function setAppSetting(string $key, string $value): void
{
    $now = nowIso();
    db()->prepare(
        'INSERT INTO app_settings (key, value, updated_at)
         VALUES (:key, :value, :updated_at)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
    )->execute([
        ':key' => $key,
        ':value' => $value,
        ':updated_at' => $now,
    ]);
}

function migrateGasSchema(PDO $pdo): void
{
    $columns = [];
    foreach ($pdo->query('PRAGMA table_info(gas_entries)')->fetchAll() as $column) {
        $columns[(string) ($column['name'] ?? '')] = true;
    }
    if (empty($columns['sub_type'])) {
        $pdo->exec('ALTER TABLE gas_entries ADD COLUMN sub_type TEXT NOT NULL DEFAULT ""');
    }
    if (empty($columns['tank_unit_id'])) {
        $pdo->exec('ALTER TABLE gas_entries ADD COLUMN tank_unit_id INTEGER');
    }
    if (empty($columns['status_before'])) {
        $pdo->exec('ALTER TABLE gas_entries ADD COLUMN status_before TEXT');
    }
    if (empty($columns['status_after'])) {
        $pdo->exec('ALTER TABLE gas_entries ADD COLUMN status_after TEXT');
    }
    if (empty($columns['updated_at'])) {
        $pdo->exec('ALTER TABLE gas_entries ADD COLUMN updated_at TEXT');
        $pdo->exec(
            "UPDATE gas_entries SET updated_at = created_at
             WHERE updated_at IS NULL OR TRIM(updated_at) = ''"
        );
    }

    gasConsolidateDuplicateStatusUpdateEntries($pdo);
    gasBackfillUsageTankUnitIds($pdo);

    $tankColumns = [];
    foreach ($pdo->query('PRAGMA table_info(gas_tank_units)')->fetchAll() as $column) {
        $tankColumns[(string) ($column['name'] ?? '')] = true;
    }
    if (empty($tankColumns['machine_code'])) {
        $pdo->exec('ALTER TABLE gas_tank_units ADD COLUMN machine_code TEXT');
    }
}

function ensureDefaultAdmin(PDO $pdo): void
{
    $total = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

    if ($total === 0) {
        $insert = $pdo->prepare('INSERT INTO users (username, password_hash, role, created_at) VALUES (:username, :hash, :role, :created_at)');
        $insert->execute([
            ':username' => 'admin',
            ':hash' => password_hash('admin123', PASSWORD_DEFAULT),
            ':role' => 'admin',
            ':created_at' => nowIso(),
        ]);
    }
}

function seedFromLegacyFiles(PDO $pdo): void
{
    $coalCount = (int) $pdo->query('SELECT COUNT(*) FROM coal_records')->fetchColumn();
    $limestoneCount = (int) $pdo->query('SELECT COUNT(*) FROM limestone_records')->fetchColumn();
    $linksCount = (int) $pdo->query('SELECT COUNT(*) FROM external_links')->fetchColumn();

    $legacyDataPath = __DIR__ . '/../data.txt';
    if (($coalCount === 0 || $limestoneCount === 0) && is_file($legacyDataPath)) {
        $raw = file_get_contents($legacyDataPath);
        $data = json_decode((string) $raw, true);

        if (is_array($data)) {
            $periods = ['today', 'thismonth', 'thisyear', 'lastmonth', 'lastyear'];

            if ($coalCount === 0 && isset($data['coal']) && is_array($data['coal'])) {
                $insertCoal = $pdo->prepare(
                    'INSERT INTO coal_records (period, sample, tm, ash, cv, sulfur, record_date, created_by, created_at)
                     VALUES (:period, :sample, :tm, :ash, :cv, :sulfur, :record_date, NULL, :created_at)'
                );

                foreach ($periods as $period) {
                    $row = $data['coal'][$period] ?? [];
                    $insertCoal->execute([
                        ':period' => $period,
                        ':sample' => (int) ($row['sample'] ?? 0),
                        ':tm' => parseNullableFloat($row['tm'] ?? null),
                        ':ash' => parseNullableFloat($row['ash'] ?? null),
                        ':cv' => parseNullableFloat($row['cv'] ?? null),
                        ':sulfur' => parseNullableFloat($row['s'] ?? null),
                        ':record_date' => date('Y-m-d'),
                        ':created_at' => nowIso(),
                    ]);
                }
            }

            if ($limestoneCount === 0 && isset($data['limestone']) && is_array($data['limestone'])) {
                $insertLimestone = $pdo->prepare(
                    'INSERT INTO limestone_records (period, sample, m_power, caco_power, m_auto, caco_auto, record_date, created_by, created_at)
                     VALUES (:period, :sample, :m_power, :caco_power, :m_auto, :caco_auto, :record_date, NULL, :created_at)'
                );

                foreach ($periods as $period) {
                    $row = $data['limestone'][$period] ?? [];
                    $insertLimestone->execute([
                        ':period' => $period,
                        ':sample' => (int) ($row['sample'] ?? 0),
                        ':m_power' => parseNullableFloat($row['m_power'] ?? null),
                        ':caco_power' => parseNullableFloat($row['caco_power'] ?? null),
                        ':m_auto' => parseNullableFloat($row['m_auto'] ?? null),
                        ':caco_auto' => parseNullableFloat($row['caco_auto'] ?? null),
                        ':record_date' => date('Y-m-d'),
                        ':created_at' => nowIso(),
                    ]);
                }
            }
        }
    }

    $legacyLinksPath = __DIR__ . '/../link.txt';
    if ($linksCount === 0 && is_file($legacyLinksPath)) {
        $raw = file_get_contents($legacyLinksPath);
        $links = json_decode((string) $raw, true);

        if (is_array($links)) {
            $insertLink = $pdo->prepare(
                'INSERT INTO external_links (category, title, url, sort_order, created_at, updated_at)
                 VALUES (:category, :title, :url, :sort_order, :created_at, :updated_at)'
            );

            $sort = 1;
            foreach ($links as $title => $url) {
                $category = stripos((string) $title, 'LIMESTONE') === 0 ? 'limestone' : 'coal';
                $insertLink->execute([
                    ':category' => $category,
                    ':title' => (string) $title,
                    ':url' => (string) $url,
                    ':sort_order' => $sort++,
                    ':created_at' => nowIso(),
                    ':updated_at' => nowIso(),
                ]);
            }
        }
    }
}

function parseNullableFloat($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    return (float) $value;
}

function currentUser(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $stmt = db()->prepare('SELECT id, username, role FROM users WHERE id = :id');
    $stmt->execute([':id' => (int) $_SESSION['user_id']]);
    $cached = $stmt->fetch() ?: null;

    return $cached;
}

function requireLogin(): void
{
    if (currentUser() === null) {
        header('Location: login.php');
        exit;
    }
}

function requireAdmin(): void
{
    $user = currentUser();
    if ($user === null) {
        header('Location: login.php');
        exit;
    }

    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

function logActivity(string $action, string $module, string $detail = ''): void
{
    $user = currentUser();
    $stmt = db()->prepare(
        'INSERT INTO activity_logs (user_id, action, module, detail, ip_address, created_at)
         VALUES (:user_id, :action, :module, :detail, :ip_address, :created_at)'
    );

    $stmt->execute([
        ':user_id' => $user['id'] ?? null,
        ':action' => $action,
        ':module' => $module,
        ':detail' => $detail,
        ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
        ':created_at' => nowIso(),
    ]);
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function periodLabel(string $period): string
{
    $labels = [
        'today' => 'Today',
        'thismonth' => 'This Month',
        'thisyear' => 'This Year',
        'lastmonth' => 'Last Month',
        'lastyear' => 'Last Year',
    ];

    return $labels[$period] ?? $period;
}

function latestByPeriod(string $table): array
{
    $allowed = ['coal_records', 'limestone_records'];
    if (!in_array($table, $allowed, true)) {
        return [];
    }

    $sql = "SELECT t1.* FROM {$table} t1
            INNER JOIN (
                SELECT period, MAX(id) AS latest_id
                FROM {$table}
                GROUP BY period
            ) t2 ON t1.id = t2.latest_id";

    $rows = db()->query($sql)->fetchAll();
    $result = [];
    foreach ($rows as $row) {
        $result[$row['period']] = $row;
    }

    return $result;
}

function appLinksByCategory(string $category): array
{
    $stmt = db()->prepare('SELECT id, title, url FROM external_links WHERE category = :category ORDER BY sort_order ASC, id ASC');
    $stmt->execute([':category' => $category]);

    return $stmt->fetchAll();
}

function flash(string $key, ?string $value = null): ?string
{
    if ($value !== null) {
        $_SESSION['flash'][$key] = $value;
        return null;
    }

    if (!isset($_SESSION['flash'][$key])) {
        return null;
    }

    $message = $_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);

    return $message;
}

function ensureGasTankInventory(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM gas_tank_inventory')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $defaults = [
        'o2_96' => ['total' => 0, 'full' => 0, 'empty' => 0, 'in_use' => 0, 'remain' => 0],
        'n2' => ['total' => 0, 'full' => 0, 'empty' => 0, 'in_use' => 0, 'remain' => 0],
        'o2_100' => ['total' => 0, 'full' => 0, 'empty' => 0, 'in_use' => 0, 'remain' => 0],
        'helium' => ['total' => 0, 'full' => 0, 'empty' => 0, 'in_use' => 0, 'remain' => 0],
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO gas_tank_inventory (gas_type, total_tanks, full_tanks, empty_tanks, in_use_tanks, remain_stock, updated_at)
         VALUES (:gas_type, :total_tanks, :full_tanks, :empty_tanks, :in_use_tanks, :remain_stock, :updated_at)'
    );

    foreach ($defaults as $type => $row) {
        $stmt->execute([
            ':gas_type' => $type,
            ':total_tanks' => $row['total'],
            ':full_tanks' => $row['full'],
            ':empty_tanks' => $row['empty'],
            ':in_use_tanks' => $row['in_use'],
            ':remain_stock' => $row['remain'],
            ':updated_at' => nowIso(),
        ]);
    }
}

require_once __DIR__ . '/gas_helpers.php';
require_once __DIR__ . '/gas_line_notify.php';
