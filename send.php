<?php
/**
 * send.php — JGM Group contact form handler (Web Hosting Canada / cPanel)
 * --------------------------------------------------------------------------
 * Emails form submissions to your own inbox using your own server.
 * No third-party service, no API keys.
 *
 * SETUP
 *   1. In cPanel > Email Accounts, make sure the inbox in $MAIL_TO exists.
 *      (Recommended: also create the $MAIL_FROM address, e.g. website@yourdomain.)
 *   2. Edit the SETTINGS block below.
 *   3. Upload this file into the same folder as index.html (usually public_html).
 *   4. In config.json set:   "endpoint": "/send.php"
 *   5. Submit the form once to test.
 * --------------------------------------------------------------------------
 */

// ============================ SETTINGS ============================
$MAIL_TO        = 'info@jgmgroup.ca';                        // where enquiries should arrive
$MAIL_FROM      = 'website@jgmgroup.ca';                     // an address ON your domain (SPF/DKIM sign it)
$MAIL_FROM_NAME = 'JGM Group Website';
$MAIL_SUBJECT   = 'New project enquiry — JGM Group website';
$ALLOWED_ORIGIN = 'https://jgmgroup.ca';                     // your site URL (or '*' to allow any origin)
// ==================================================================

header('Content-Type: application/json; charset=utf-8');
if ($ALLOWED_ORIGIN !== '') header('Access-Control-Allow-Origin: ' . $ALLOWED_ORIGIN);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Preflight / wrong method
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Honeypot: a bot filled the hidden field — accept silently, send nothing.
if (!empty($_POST['_hp'])) { http_response_code(200); echo json_encode(['success' => true]); exit; }

// Collect + trim submitted values
function field($k) { return isset($_POST[$k]) ? trim((string) $_POST[$k]) : ''; }
$name  = field('fullName');
$email = field('email');
$phone = field('phone');
$city  = field('city');
$scope = field('scope');

// Validate required fields
$errors = [];
if ($name === '')  $errors[] = 'fullName';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'email';
if ($city === '')  $errors[] = 'city';
if ($scope === '') $errors[] = 'scope';
if ($errors) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Please complete all required fields.', 'fields' => $errors]);
    exit;
}

// Strip CR/LF from anything that goes into a mail header (prevents header injection)
function clean_header($v) { return trim(str_replace(["\r", "\n", "%0a", "%0d", "%0A", "%0D"], '', $v)); }
$email    = clean_header($email);
$safeName = clean_header($name);

// Plain-text message body
$body =
    "New project enquiry from the JGM Group website\n" .
    "----------------------------------------------\n\n" .
    "Name:       $name\n" .
    "Email:      $email\n" .
    "Phone:      " . ($phone !== '' ? $phone : '—') . "\n" .
    "Site city:  $city\n\n" .
    "Project scope:\n$scope\n";

// Headers — From is on YOUR domain (so SPF/DKIM pass); Reply-To is the visitor,
// so you can just hit "Reply" to respond to them directly.
$headers  = 'From: ' . clean_header($MAIL_FROM_NAME) . ' <' . $MAIL_FROM . ">\r\n";
$headers .= "Reply-To: $safeName <$email>\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

$subject = clean_header($MAIL_SUBJECT);

// Send. The -f flag sets the envelope sender to your domain, which helps deliverability.
$ok = mail($MAIL_TO, $subject, $body, $headers, '-f' . $MAIL_FROM);

if ($ok) {
    http_response_code(200);
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'The server could not send the email.']);
}
