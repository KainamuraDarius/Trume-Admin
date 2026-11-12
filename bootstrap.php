<?php
declare(strict_types=1);

const ADMIN_ROOT = __DIR__;

function config(): array
{
    static $config;
    if ($config !== null) {
        return $config;
    }

    $path = ADMIN_ROOT . '/config.php';
    if (!is_file($path)) {
        http_response_code(503);
        exit('Admin is not configured. Copy config.example.php to config.php.');
    }

    $config = (array) require $path;
    $config += ['database_path' => '', 'public_site_path' => '', 'public_site_url' => '', 'setup_key' => ''];
    return $config;
}

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $settings = config();
    if (empty($settings['database_path'])) {
        http_response_code(503);
        exit('Admin is not configured. Set database_path in config.php (see config.example.php).');
    }
    $databasePath = $settings['database_path'];
    $directory = dirname($databasePath);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the SQLite database directory.');
    }
    $pdo = new PDO('sqlite:' . $databasePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $schema = file_get_contents(ADMIN_ROOT . '/schema.sql');
    $pdo->exec((string) $schema);
    ensure_admin_access_columns($pdo);
    ensure_content_columns($pdo);
    seed_default_content($pdo);
    return $pdo;
}

function ensure_admin_access_columns(PDO $pdo): void
{
    $columns = array_column($pdo->query('PRAGMA table_info(admins)')->fetchAll(), 'name');
    if (!in_array('role', $columns, true)) {
        $pdo->exec("ALTER TABLE admins ADD COLUMN role TEXT NOT NULL DEFAULT 'admin'");
    }
    if (!in_array('is_active', $columns, true)) {
        $pdo->exec('ALTER TABLE admins ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1');
    }
    $ownerCount = (int) $pdo->query("SELECT COUNT(*) FROM admins WHERE role = 'owner'")->fetchColumn();
    if ($ownerCount === 0) {
        $firstId = $pdo->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn();
        if ($firstId !== false) {
            $pdo->prepare("UPDATE admins SET role = 'owner' WHERE id = ?")->execute([$firstId]);
        }
    }
}

/** Adds columns introduced after the first release to an existing database. */
function ensure_content_columns(PDO $pdo): void
{
    $add = [
        'pages' => ['hero_body' => "TEXT NOT NULL DEFAULT ''"],
        'articles' => ['image_alt' => "TEXT NOT NULL DEFAULT ''", 'meta_description' => "TEXT NOT NULL DEFAULT ''"],
    ];
    foreach ($add as $table => $columns) {
        $existing = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
        foreach ($columns as $column => $definition) {
            if (!in_array($column, $existing, true)) {
                $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
            }
        }
    }
}

/** The public website's default content (includes/defaults.php), or [] if it cannot be found. */
function site_defaults(): array
{
    $path = rtrim((string) config()['public_site_path'], '/') . '/includes/defaults.php';
    return is_file($path) ? (array) require $path : [];
}

/**
 * Fills a new database with the website's current content, once.
 * Pages and settings that are missing are always added; founders and articles
 * are only imported the first time, so deleting them in the admin is permanent.
 */
function seed_default_content(PDO $pdo): void
{
    $defaults = site_defaults();
    if (!$defaults) {
        return;
    }
    $setting = $pdo->prepare('INSERT OR IGNORE INTO site_settings (setting_key, setting_value) VALUES (?, ?)');
    foreach ($defaults['settings'] ?? [] as $key => $value) {
        $setting->execute([$key, $value]);
    }
    $page = $pdo->prepare('INSERT OR IGNORE INTO pages (slug, page_title, meta_description, hero_eyebrow, hero_heading, hero_body, intro_label, intro_heading, intro_body) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($defaults['pages'] ?? [] as $slug => $p) {
        $page->execute([$slug, $p['page_title'], $p['meta_description'], $p['hero_eyebrow'], $p['hero_heading'], $p['hero_body'], $p['intro_label'], $p['intro_heading'], $p['intro_body']]);
    }
    $imported = $pdo->query("SELECT meta_value FROM app_meta WHERE meta_key = 'content_imported'")->fetchColumn();
    if ($imported !== false) {
        return;
    }
    $pdo->beginTransaction();
    $founder = $pdo->prepare('INSERT INTO founders (name, role_title, focus, biography, image_path, email, phone, display_order, is_published) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)');
    foreach ($defaults['founders'] ?? [] as $f) {
        $founder->execute([$f['name'], $f['role_title'], $f['focus'], $f['biography'], $f['image_path'], $f['email'] ?: null, $f['phone'] ?: null, $f['display_order']]);
    }
    $article = $pdo->prepare("INSERT OR IGNORE INTO articles (title, slug, category, excerpt, body, image_path, image_alt, meta_description, status, published_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'published', ?)");
    foreach ($defaults['articles'] ?? [] as $a) {
        $article->execute([$a['title'], $a['slug'], $a['category'], $a['excerpt'], $a['body'], $a['image_path'], $a['image_alt'], $a['meta_description'], $a['published_at']]);
    }
    $pdo->prepare("INSERT INTO app_meta (meta_key, meta_value) VALUES ('content_imported', ?)")->execute([date('c')]);
    $pdo->commit();
}

// ---------------------------------------------------------------- login protection

const LOGIN_MAX_FAILURES = 5;
const LOGIN_WINDOW_SECONDS = 900;

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

/** Seconds until this IP may try again, or 0 if it is not locked out. */
function login_lockout_seconds(): int
{
    $since = time() - LOGIN_WINDOW_SECONDS;
    db()->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')->execute([$since]);
    $query = db()->prepare('SELECT COUNT(*), MIN(attempted_at) FROM login_attempts WHERE ip_address = ? AND attempted_at >= ?');
    $query->execute([client_ip(), $since]);
    [$count, $oldest] = $query->fetch(PDO::FETCH_NUM);
    return (int) $count >= LOGIN_MAX_FAILURES ? max(1, (int) $oldest + LOGIN_WINDOW_SECONDS - time()) : 0;
}

function record_login_failure(string $email): void
{
    db()->prepare('INSERT INTO login_attempts (ip_address, email, attempted_at) VALUES (?, ?, ?)')
        ->execute([client_ip(), mb_substr($email, 0, 190), time()]);
}

function clear_login_failures(): void
{
    db()->prepare('DELETE FROM login_attempts WHERE ip_address = ?')->execute([client_ip()]);
}

// ---------------------------------------------------------------- images

/** Absolute path of the public website's uploads folder. */
function uploads_directory(): string
{
    return rtrim((string) config()['public_site_path'], '/') . '/uploads';
}

/**
 * Validates and stores an uploaded image in the public website's uploads folder.
 * Returns the site-relative path (e.g. "uploads/ab12.jpg"), null if no file was chosen,
 * or throws RuntimeException with a message suitable for the admin user.
 */
function store_uploaded_image(?array $file, int $adminId, string $altText = ''): ?string
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 8 * 1024 * 1024) {
        throw new RuntimeException('Choose an image smaller than 8 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($extensions[$mime]) || !getimagesize($file['tmp_name'])) {
        throw new RuntimeException('Only valid JPG, PNG, WebP and GIF images are allowed.');
    }
    $directory = uploads_directory();
    if (config()['public_site_path'] === '' || (!is_dir($directory) && !mkdir($directory, 0755, true))) {
        throw new RuntimeException('The website uploads folder is not available. Check public_site_path in config.php.');
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $filename)) {
        throw new RuntimeException('Upload failed. Please try again.');
    }
    @chmod($directory . '/' . $filename, 0644);
    $storedPath = 'uploads/' . $filename;
    db()->prepare('INSERT INTO media (original_name, stored_path, mime_type, file_size, alt_text, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([basename((string) $file['name']), $storedPath, $mime, (int) $file['size'], $altText, $adminId]);
    return $storedPath;
}

/** Image paths offered in founder/article forms: uploaded media first, then the site's built-in photos. */
function image_choices(): array
{
    $choices = array_column(db()->query('SELECT stored_path FROM media ORDER BY created_at DESC')->fetchAll(), 'stored_path');
    $assets = rtrim((string) config()['public_site_path'], '/') . '/assets';
    foreach (glob($assets . '/{,*/}*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [] as $file) {
        $relative = 'assets/' . ltrim(substr($file, strlen($assets)), '/');
        if (!str_contains($relative, 'og-image') && !str_contains($relative, 'Logo')) {
            $choices[] = $relative;
        }
    }
    return array_values(array_unique($choices));
}

/** Shared "pick or upload an image" form controls. */
function image_picker(string $name, ?string $current): string
{
    $options = '<option value="">No image</option>';
    $choices = image_choices();
    if ($current && !in_array($current, $choices, true)) {
        array_unshift($choices, $current);
    }
    foreach ($choices as $path) {
        $options .= '<option value="' . e($path) . '"' . ($path === $current ? ' selected' : '') . '>' . e($path) . '</option>';
    }
    $preview = $current ? '<img class="picker-preview" src="' . e(public_site_url($current)) . '" alt="">' : '';
    return '<div class="image-picker full">' . $preview
        . '<label>Choose an existing image<select name="' . e($name) . '">' . $options . '</select></label>'
        . '<label>…or upload a new one<input type="file" name="' . e($name) . '_upload" accept="image/jpeg,image/png,image/webp,image/gif"></label>'
        . '</div>';
}

/** Formatting help shown next to editable text. */
function formatting_hint(bool $paragraphs = false): string
{
    return '<span class="hint">Wrap words in *asterisks* to show them in the lime highlight style.'
        . ($paragraphs ? ' Leave a blank line between paragraphs. Start a line with ## to make a subheading.' : '')
        . '</span>';
}

function log_activity(string $action, string $entityType, ?int $entityId = null): void
{
    $admin = current_admin();
    db()->prepare('INSERT INTO activity_log (admin_id, action, entity_type, entity_id) VALUES (?, ?, ?, ?)')
        ->execute([$admin['id'] ?? null, $action, $entityType, $entityId]);
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('trume_admin');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function public_site_url(string $path = ''): string
{
    if (preg_match('#^https?://#', $path)) {
        return $path;
    }
    return rtrim((string) config()['public_site_url'], '/') . '/' . ltrim($path, '/');
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    start_session();
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        exit('Your session has expired. Please go back and try again.');
    }
}

function current_admin(): ?array
{
    start_session();
    $admin = $_SESSION['admin'] ?? null;
    if (!is_array($admin) || empty($admin['id']) || empty($admin['email'])) {
        unset($_SESSION['admin']);
        return null;
    }
    $statement = db()->prepare('SELECT id, name, email, role, is_active FROM admins WHERE id = ? LIMIT 1');
    $statement->execute([(int) $admin['id']]);
    $account = $statement->fetch();
    if (!$account || !(int) $account['is_active']) {
        unset($_SESSION['admin']);
        return null;
    }
    $_SESSION['admin'] = $account;
    return $account;
}

function require_login(): array
{
    $admin = current_admin();
    if ($admin === null) {
        header('Location: login.php');
        exit;
    }
    return $admin;
}

function require_owner(): array
{
    $admin = require_login();
    if (($admin['role'] ?? '') !== 'owner') {
        flash('notice', 'Only the account owner can manage administrator accounts.');
        redirect('index.php');
    }
    return $admin;
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function flash(string $key, ?string $message = null): ?string
{
    start_session();
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }
    $message = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return $message;
}
