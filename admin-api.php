<?php
/** SSM Jelas admin API: login, logout, paginated enquiries and authenticated deletion. */
declare(strict_types=1);
require_once __DIR__ . '/lib/db.php';

const SSM_JELAS_ADMIN_MAX_AGE = 43200; // 12 hours

function ssm_jelas_admin_session(): void
{
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string) SSM_JELAS_ADMIN_MAX_AGE);
    session_name('ssm_jelas_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/admin-api.php',
        'secure' => true, // HTTPS required in Plesk.
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    if (!session_start()) {
        ssm_jelas_json(503, ['error' => 'Unable to start an admin session.']);
    }
}

function ssm_jelas_admin_is_authenticated(): bool
{
    if (!isset($_SESSION['ssm_jelas_admin_at']) || !is_int($_SESSION['ssm_jelas_admin_at'])) {
        return false;
    }
    $age = time() - $_SESSION['ssm_jelas_admin_at'];
    if ($age < 0 || $age > SSM_JELAS_ADMIN_MAX_AGE) {
        unset($_SESSION['ssm_jelas_admin_at']);
        return false;
    }
    return true;
}

/** At most 8 unsuccessful password attempts per remote IP per 15-minute period. */
function ssm_jelas_login_allowed(bool $passwordCorrect): bool
{
    $ipHash = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $filename = sys_get_temp_dir() . '/ssm_jelas_login_' . $ipHash;
    $handle = @fopen($filename, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) fclose($handle);
        throw new RuntimeException('Unable to open the admin login throttle file.');
    }
    try {
        $stored = stream_get_contents($handle);
        $state = json_decode($stored ?: '{}', true);
        $now = time();
        if (!is_array($state) || !isset($state['start'], $state['failures']) || $state['start'] <= $now - 900) {
            $state = ['start' => $now, 'failures' => 0];
        }
        $allowed = $state['failures'] < 8;
        if ($allowed) $state['failures'] = $passwordCorrect ? 0 : $state['failures'] + 1;
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($state));
        fflush($handle);
        return $allowed;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/** Convert a date chosen in Malaysia's UTC+8 timezone into a UTC SQL boundary. */
function ssm_jelas_date_boundary($input, bool $nextDay = false): ?string
{
    if ($input === null || $input === '') return null;
    if (!is_string($input) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $input)) {
        ssm_jelas_json(400, ['error' => 'Invalid date filter.']);
    }
    $local = DateTimeImmutable::createFromFormat('!Y-m-d', $input, new DateTimeZone('Asia/Kuala_Lumpur'));
    if (!$local || $local->format('Y-m-d') !== $input) {
        ssm_jelas_json(400, ['error' => 'Invalid date filter.']);
    }
    if ($nextDay) $local = $local->modify('+1 day');
    return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    ssm_jelas_json(405, ['error' => 'Method not allowed.']);
}
if ($method === 'POST' && !ssm_jelas_same_origin()) {
    ssm_jelas_json(403, ['error' => 'Invalid origin.']);
}

try {
    $config = ssm_jelas_config();
    $passwordHash = $config['admin_password_hash'] ?? null;
    if (!is_string($passwordHash) || $passwordHash === '') {
        throw new RuntimeException('Admin password hash is not configured.');
    }
} catch (Throwable $error) {
    error_log('[SSM Jelas] Admin configuration: ' . $error->getMessage());
    ssm_jelas_json(503, ['error' => 'Admin access is not configured.']);
}

ssm_jelas_admin_session();
if ($method === 'POST') {
    $body = ssm_jelas_json_body(20000);
    $action = $body['action'] ?? null;

    if ($action === 'logout') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            setcookie(session_name(), '', [
                'expires' => time() - 3600,
                'path' => '/admin-api.php',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
        }
        session_destroy();
        ssm_jelas_json(200, ['ok' => true]);
    }

    if ($action === 'login') {
        if (!is_string($body['password'] ?? null) || strlen($body['password']) > 1000) {
            ssm_jelas_json(400, ['error' => 'Invalid sign-in request.']);
        }
        $passwordCorrect = password_verify($body['password'], $passwordHash);
        try {
            if (!ssm_jelas_login_allowed($passwordCorrect)) {
                ssm_jelas_json(429, ['error' => 'Too many sign-in attempts. Please try again in 15 minutes.']);
            }
        } catch (Throwable $error) {
            error_log('[SSM Jelas] Admin rate limiting failed: ' . $error->getMessage());
            ssm_jelas_json(503, ['error' => 'Admin sign-in is temporarily unavailable.']);
        }
        if (!$passwordCorrect) ssm_jelas_json(401, ['error' => 'Incorrect password.']);
        session_regenerate_id(true);
        $_SESSION['ssm_jelas_admin_at'] = time();
        session_write_close();
        ssm_jelas_json(200, ['ok' => true]);
    }

    if ($action === 'delete') {
        if (!ssm_jelas_admin_is_authenticated()) {
            ssm_jelas_json(401, ['error' => 'Please sign in.']);
        }
        // We only accept explicitly selected numeric IDs, never a filter or delete-all flag.
        $ids = $body['ids'] ?? null;
        if (!is_array($ids) || !array_is_list($ids) || count($ids) < 1 || count($ids) > 500) {
            ssm_jelas_json(400, ['error' => 'Select between 1 and 500 enquiries.']);
        }
        $valid = [];
        foreach ($ids as $id) {
            if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
                ssm_jelas_json(400, ['error' => 'Invalid enquiry ID.']);
            }
            $number = filter_var((string) $id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($number === false) ssm_jelas_json(400, ['error' => 'Invalid enquiry ID.']);
            $valid[$number] = $number;
        }
        session_write_close();
        try {
            $pdo = ssm_jelas_database();
            $placeholders = implode(', ', array_fill(0, count($valid), '?'));
            $stmt = $pdo->prepare('DELETE FROM guidance_enquiries WHERE id IN (' . $placeholders . ')');
            $stmt->execute(array_values($valid));
            ssm_jelas_json(200, ['ok' => true, 'deleted' => $stmt->rowCount()]);
        } catch (Throwable $error) {
            error_log('[SSM Jelas] Unable to delete enquiries: ' . $error->getMessage());
            ssm_jelas_json(503, ['error' => 'Unable to delete enquiries right now.']);
        }
    }
    ssm_jelas_json(400, ['error' => 'Invalid admin action.']);
}

if (!ssm_jelas_admin_is_authenticated()) {
    ssm_jelas_json(401, ['error' => 'Please sign in.']);
}
session_write_close();

/** Escape cell values that spreadsheets might otherwise interpret as executable formulas. */
function ssm_jelas_csv_cell($value): string
{
    $text = str_replace(["\r\n", "\r"], "\n", (string) ($value ?? ''));
    if (preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $text) === 1) {
        $text = "'" . $text;
    }
    return $text;
}

// "Export CSV" downloads the complete database, regardless of the active filters.
// It streams directly from MySQL and requires the already-verified admin session.
if (($_GET['export'] ?? null) === 'all') {
    try {
        $pdo = ssm_jelas_database();
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $statement = $pdo->query(
            "SELECT id, name, email, contact_number, topic, message, " .
            "DATE_FORMAT(consent_at, '%Y-%m-%dT%H:%i:%sZ') AS consent_at, " .
            "DATE_FORMAT(created_at, '%Y-%m-%dT%H:%i:%sZ') AS created_at " .
            'FROM guidance_enquiries ORDER BY created_at DESC, id DESC'
        );
    } catch (Throwable $error) {
        error_log('[SSM Jelas] Unable to export enquiries: ' . $error->getMessage());
        ssm_jelas_json(503, ['error' => 'Unable to export enquiries right now.']);
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="ssm-jelas-enquiries-all-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    $output = fopen('php://output', 'wb');
    if ($output === false) exit;
    fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel.
    fputcsv($output, ['ID', 'Name', 'Email', 'Contact Number', 'Topic', 'Message', 'Consent At (UTC)', 'Submitted At (UTC)'], ',', '"', '');
    while (($record = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        fputcsv($output, array_map('ssm_jelas_csv_cell', array_values($record)), ',', '"', '');
    }
    fclose($output);
    exit;
}
if (isset($_GET['export'])) {
    ssm_jelas_json(400, ['error' => 'Invalid export request.']);
}

// Server-side filtering, counts, and paging instead of the old newest-500 cap.
$page = filter_var($_GET['page'] ?? '1', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
$perPage = filter_var($_GET['per_page'] ?? '10', FILTER_VALIDATE_INT);
if ($page === false || !in_array($perPage, [10, 20, 50, 100], true)) {
    ssm_jelas_json(400, ['error' => 'Invalid page settings.']);
}
$search = trim((string) ($_GET['search'] ?? ''));
$topic = trim((string) ($_GET['topic'] ?? ''));
if (strlen($search) > 200 || strlen($topic) > 80) {
    ssm_jelas_json(400, ['error' => 'A filter is too long.']);
}
$rawFrom = $_GET['date_from'] ?? '';
$rawTo = $_GET['date_to'] ?? '';
if (is_array($rawFrom) || is_array($rawTo)) ssm_jelas_json(400, ['error' => 'Invalid date filter.']);
$from = ssm_jelas_date_boundary($rawFrom);
$to = ssm_jelas_date_boundary($rawTo, true);
if ($from !== null && $to !== null && strcmp($from, $to) >= 0) {
    ssm_jelas_json(400, ['error' => 'The date range is invalid.']);
}
$where = [];
$params = [];
if ($search !== '') {
    // Escape LIKE wildcards so searches treat user text literally.
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
    $fields = ['name', 'email', 'contact_number', 'topic', 'message', 'CAST(id AS CHAR)'];
    $conditions = [];
    foreach ($fields as $index => $field) {
        $key = ':q' . $index;
        $conditions[] = $field . ' LIKE ' . $key;
        $params[$key] = $like;
    }
    $where[] = '(' . implode(' OR ', $conditions) . ')';
}
if ($topic !== '') {
    $where[] = 'topic = :topic';
    $params[':topic'] = $topic;
}
if ($from !== null) {
    $where[] = 'created_at >= :date_from';
    $params[':date_from'] = $from;
}
if ($to !== null) {
    $where[] = 'created_at < :date_to';
    $params[':date_to'] = $to;
}
$whereSQL = $where ? ' WHERE ' . implode(' AND ', $where) : '';
try {
    $pdo = ssm_jelas_database();
    $totalCount = (int) $pdo->query('SELECT COUNT(*) FROM guidance_enquiries')->fetchColumn();
    $topics = $pdo->query('SELECT DISTINCT topic FROM guidance_enquiries ORDER BY topic ASC')->fetchAll(PDO::FETCH_COLUMN);
    $count = $pdo->prepare('SELECT COUNT(*) FROM guidance_enquiries' . $whereSQL);
    $count->execute($params);
    $filteredCount = (int) $count->fetchColumn();
    $maxPage = max(1, (int) ceil($filteredCount / $perPage));
    $page = min($page, $maxPage);
    $offset = ($page - 1) * $perPage;
    $sql = "SELECT id, name, contact_number, email, topic, message, " .
        "DATE_FORMAT(consent_at, '%Y-%m-%dT%H:%i:%sZ') AS consent_at, " .
        "DATE_FORMAT(created_at, '%Y-%m-%dT%H:%i:%sZ') AS created_at " .
        'FROM guidance_enquiries' . $whereSQL .
        ' ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset';
    $statement = $pdo->prepare($sql);
    foreach ($params as $key => $value) $statement->bindValue($key, $value, PDO::PARAM_STR);
    $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $statement->execute();
    ssm_jelas_json(200, [
        'records' => $statement->fetchAll(),
        'total' => $filteredCount,
        'total_count' => $totalCount,
        'topics' => $topics,
        'page' => $page,
        'per_page' => $perPage,
    ]);
} catch (Throwable $error) {
    error_log('[SSM Jelas] Unable to load enquiries: ' . $error->getMessage());
    ssm_jelas_json(503, ['error' => 'Unable to load enquiries right now.']);
}
