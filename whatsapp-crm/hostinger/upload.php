<?php
/**
 * ============================================================
 * WhatsApp CRM - CSV Upload Handler
 * ============================================================
 * Handles file upload from dashboard, validates, stores,
 * and triggers import_csv.php
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'POST method required'], 405);
}

// Check feature flag
if (!FEATURE_CSV_UPLOAD) {
    jsonResponse(['success' => false, 'error' => 'CSV upload is disabled'], 403);
}

// ============================================================
// VALIDATE UPLOAD
// ============================================================

if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    $errors = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server limit',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form limit',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
    ];

    $errorCode = $_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE;
    $errorMsg = $errors[$errorCode] ?? 'Unknown upload error';

    jsonResponse(['success' => false, 'error' => $errorMsg], 400);
}

$file = $_FILES['csv_file'];

// Validate file type
$allowedMimes = ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$detectedMime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($detectedMime, $allowedMimes)) {
    jsonResponse(['success' => false, 'error' => "Invalid file type: {$detectedMime}. Only CSV allowed."], 400);
}

// Validate extension
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if ($ext !== 'csv') {
    jsonResponse(['success' => false, 'error' => 'File must have .csv extension'], 400);
}

// Validate size
if ($file['size'] > MAX_CSV_SIZE) {
    $maxMB = MAX_CSV_SIZE / 1024 / 1024;
    jsonResponse(['success' => false, 'error' => "File too large. Maximum: {$maxMB}MB"], 400);
}

// Validate not empty
if ($file['size'] === 0) {
    jsonResponse(['success' => false, 'error' => 'File is empty'], 400);
}

// ============================================================
// STORE FILE
// ============================================================

$uploadDir = defined('CSV_UPLOAD_PATH') ? CSV_UPLOAD_PATH : __DIR__ . '/uploads/';

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$filename = 'leads_' . date('Y-m-d_His') . '_' . bin2hex(random_bytes(4)) . '.csv';
$destination = $uploadDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $destination)) {
    jsonResponse(['success' => false, 'error' => 'Failed to save uploaded file'], 500);
}

logImport("File uploaded: {$filename} ({$file['size']} bytes)");

// ============================================================
// QUICK VALIDATION (Check headers)
// ============================================================

$handle = fopen($destination, 'r');
$header = fgetcsv($handle);
fclose($handle);

if (!$header || count($header) < 3) {
    unlink($destination);
    jsonResponse(['success' => false, 'error' => 'CSV must have at least 3 columns'], 400);
}

// Check for required columns
$headerLower = array_map(fn($h) => strtolower(trim($h)), $header);
$hasName = in_array('business name', $headerLower) || in_array('name', $headerLower);
$hasPhone = in_array('phone', $headerLower) || in_array('mobile', $headerLower) || in_array('contact', $headerLower);

if (!$hasName || !$hasPhone) {
    unlink($destination);
    jsonResponse([
        'success' => false, 
        'error' => 'CSV must have "Business Name" and "Phone" columns. Found: ' . implode(', ', $header)
    ], 400);
}

// ============================================================
// RUN IMPORT
// ============================================================

// Include and run import directly
$_POST['csv_path'] = $destination;
ob_start();
require_once __DIR__ . '/scripts/import_csv.php';
$output = ob_get_clean();

// The import script outputs JSON when in web mode
$result = json_decode($output, true);

if ($result) {
    jsonResponse($result);
} else {
    jsonResponse([
        'success' => true,
        'message' => 'File uploaded and import triggered',
        'file' => $filename
    ]);
}
