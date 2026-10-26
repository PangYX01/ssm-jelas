<?php
/** Public guidance enquiry submission endpoint (JSON POST). */
declare(strict_types=1);
require_once __DIR__ . '/lib/db.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    ssm_jelas_json(405, ['error' => 'Method not allowed.']);
}
if (!ssm_jelas_same_origin()) {
    ssm_jelas_json(403, ['error' => 'Invalid origin.']);
}

$body = ssm_jelas_json_body();
$name = ssm_jelas_input_string($body, 'name');
$contact = ssm_jelas_input_string($body, 'contactNumber');
$email = ssm_jelas_input_string($body, 'email');
$topic = ssm_jelas_input_string($body, 'topic');
$message = ssm_jelas_input_string($body, 'message');
$topics = [
    'SSM renewal or expiry',
    'Business registration guidance',
    'Business documents or information',
    'Business information updates',
    'Other SSM-related question',
];

if ($name === null || $name === '' || ssm_jelas_length($name) > 120 ||
    $contact === null || !preg_match('/^[+\d][\d\s()+.\-]{5,29}$/D', $contact) ||
    $email === null || ssm_jelas_length($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false ||
    $topic === null || !in_array($topic, $topics, true) ||
    $message === null || ssm_jelas_length($message) > 3000 ||
    ($body['consent'] ?? null) !== true
) {
    ssm_jelas_json(400, ['error' => 'Please complete all required fields and accept the consent statement.']);
}

try {
    $pdo = ssm_jelas_database();
    $statement = $pdo->prepare(
        'INSERT INTO guidance_enquiries (name, contact_number, email, topic, message, consent_at) ' .
        'VALUES (:name, :contact, :email, :topic, :message, UTC_TIMESTAMP())'
    );
    $statement->execute([
        ':name' => $name,
        ':contact' => $contact,
        ':email' => $email,
        ':topic' => $topic,
        ':message' => $message,
    ]);
    ssm_jelas_json(201, ['ok' => true]);
} catch (Throwable $error) {
    error_log('[SSM Jelas] Unable to save enquiry: ' . $error->getMessage());
    ssm_jelas_json(503, ['error' => 'Unable to save your enquiry right now. Please try again later.']);
}
