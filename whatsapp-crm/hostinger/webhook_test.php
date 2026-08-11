<?php
/**
 * WEBHOOK TEST FILE
 * 
 * This file bypasses ALL signature checks and simply logs whatever comes in.
 * Use this to verify if Node.js is even sending webhooks to your server.
 * 
 * HOW TO TEST:
 * 1. Upload this file to Hostinger root (same folder as webhook.php)
 * 2. In HF Space Secrets, change WEBHOOK_URL to:
 *    https://YOUR_DOMAIN.com/webhook_test.php
 * 3. Send a message from client to your WhatsApp
 * 4. Check if webhook_test_log.txt file appears in same folder
 * 5. If YES = webhook works, problem is in webhook.php signature logic
 * 6. If NO = Node.js is NOT sending webhooks (check HF Space Logs)
 * 
 * AFTER TESTING: Delete this file and change WEBHOOK_URL back to webhook.php
 */

// Accept any method
$method = $_SERVER['REQUEST_METHOD'];
$rawBody = file_get_contents('php://input');
$headers = getallheaders();
$timestamp = date('Y-m-d H:i:s');

// Log everything to a file in the SAME directory (guaranteed writable)
$logFile = __DIR__ . '/webhook_test_log.txt';

$logEntry = "=== [{$timestamp}] ===\n";
$logEntry .= "Method: {$method}\n";
$logEntry .= "IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n";
$logEntry .= "Headers:\n";
foreach ($headers as $key => $value) {
    $logEntry .= "  {$key}: {$value}\n";
}
$logEntry .= "Body: {$rawBody}\n";
$logEntry .= "Body Length: " . strlen($rawBody) . " bytes\n";

// Try to parse JSON
$data = json_decode($rawBody, true);
if ($data) {
    $logEntry .= "Parsed Event: " . ($data['event'] ?? 'N/A') . "\n";
    $logEntry .= "Parsed Phone: " . ($data['phone'] ?? 'N/A') . "\n";
    $logEntry .= "Parsed Message: " . substr($data['message'] ?? '', 0, 100) . "\n";
}

$logEntry .= "---END---\n\n";

// Write to log file
file_put_contents($logFile, $logEntry, FILE_APPEND);

// Always respond 200 OK
http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['status' => 'ok', 'test' => true, 'received' => strlen($rawBody) . ' bytes']);
