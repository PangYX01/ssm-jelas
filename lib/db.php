<?php
/**
 * Shared PHP utilities for SSM Jelas on Plesk MySQL/MariaDB.
 * No database credentials are stored in the public document root.
 */
declare(strict_types=1);

function ssm_jelas_json(int $status, array $data): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function ssm_jelas_same_origin(): bool
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        // Browser fetches normally send Origin for JSON POSTs. Also reject known cross-site requests.
        return strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')) !== 'cross-site';
    }

    $source = parse_url($origin);
    $destination = parse_url('https://' . ($_SERVER['HTTP_HOST'] ?? ''));
    if (!is_array($source) || !is_array($destination)) {
        return false;
    }
    $scheme = strtolower((string) ($source['scheme'] ?? ''));
    if (!in_array($scheme, ['https', 'http'], true)) {
        return false;
    }
    if (!isset($source['host'], $destination['host']) || strcasecmp($source['host'], $destination['host']) !== 0) {
        return false;
    }
    // A non-standard Origin port must match the incoming Host port.
    $expectedPort = $destination['port'] ?? ($scheme === 'https' ? 443 : 80);
    $sourcePort = $source['port'] ?? ($scheme === 'https' ? 443 : 80);
    return $sourcePort === $expectedPort;
}

function ssm_jelas_json_body(int $limit = 12000): array
{
    $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if ($contentType !== 'application/json') {
        ssm_jelas_json(415, ['error' => 'JSON content type is required.']);
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $limit) {
        ssm_jelas_json(413, ['error' => 'Request is too long.']);
    }
    $raw = file_get_contents('php://input', false, null, 0, $limit + 1);
    if ($raw === false || strlen($raw) > $limit) {
        ssm_jelas_json(413, ['error' => 'Request is too long.']);
    }
    $data = json_decode($raw, true, 32);
    if (!is_array($data) || array_is_list($data)) {
        // array_is_list requires PHP 8.1+, hence the PHP 8.1+ requirement in setup notes.
        ssm_jelas_json(400, ['error' => 'Invalid JSON request.']);
    }
    return $data;
}

function ssm_jelas_config(): array
{
    // Example Plesk layout: /var/www/vhosts/example.com/httpdocs/..
    //                        /var/www/vhosts/example.com/private/ssm-jelas-config.php
    $filename = dirname(__DIR__, 2) . '/private/ssm-jelas-config.php';
    if (!is_file($filename) || !is_readable($filename)) {
        throw new RuntimeException('The private site configuration file is missing.');
    }
    $settings = require $filename;
    if (!is_array($settings)) {
        throw new RuntimeException('The private site configuration is invalid.');
    }
    return $settings;
}

function ssm_jelas_database(): PDO
{
    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException('The PHP PDO MySQL extension is not installed.');
    }
    $settings = ssm_jelas_config();
    foreach (['db_host', 'db_name', 'db_user', 'db_password'] as $key) {
        if (!isset($settings[$key]) || !is_string($settings[$key])) {
            throw new RuntimeException('Missing database configuration setting: ' . $key);
        }
    }
    $host = $settings['db_host'];
    $port = (int) ($settings['db_port'] ?? 3306);
    $dbname = $settings['db_name'];
    if ($port < 1 || $port > 65535) {
        throw new RuntimeException('Invalid database port.');
    }
    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    $pdo = new PDO($dsn, $settings['db_user'], $settings['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    // Insert and read all database timestamps as UTC.
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

function ssm_jelas_input_string(array $body, string $field): ?string
{
    $value = $body[$field] ?? '';
    return is_string($value) ? trim($value) : null;
}

function ssm_jelas_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}
