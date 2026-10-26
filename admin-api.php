<?php
/** Admin login, logout and read-only enquiry API for PHP + Plesk MySQL. */
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
        'secure' => true,      // Requires HTTPS in Plesk.
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

/** At most eight failed password attempts per remote IP per 15-minute window. */
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
        if ($allowed) {
            $state['failures'] = $passwordCorrect ? 0 : $state['failures'] + 1;
        }
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
    $body = ssm_jelas_json_body(5000);
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

    if ($action !== 'login' || !is_string($body['password'] ?? null) || strlen($body['password']) > 1000) {
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
    if (!$passwordCorrect) {
        ssm_jelas_json(401, ['error' => 'Incorrect password.']);
    }
    session_regenerate_id(true);
    $_SESSION['ssm_jelas_admin_at'] = time();
    session_write_close();
    ssm_jelas_json(200, ['ok' => true]);
}

if (!ssm_jelas_admin_is_authenticated()) {
    ssm_jelas_json(401, ['error' => 'Please sign in.']);
}
session_write_close();
try {
    $pdo = ssm_jelas_database();
    $records = $pdo->query(
        "SELECT id, name, contact_number, email, topic, message, " .
        "DATE_FORMAT(consent_at, '%Y-%m-%dT%H:%i:%sZ') AS consent_at, " .
        "DATE_FORMAT(created_at, '%Y-%m-%dT%H:%i:%sZ') AS created_at " .
        'FROM guidance_enquiries ORDER BY created_at DESC, id DESC LIMIT 500'
    )->fetchAll();
    ssm_jelas_json(200, ['records' => $records]);
} catch (Throwable $error) {
    error_log('[SSM Jelas] Unable to load enquiries: ' . $error->getMessage());
    ssm_jelas_json(503, ['error' => 'Unable to load enquiries right now.']);
}
