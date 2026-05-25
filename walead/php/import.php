<?php
/**
 * WaLead CRM - CSV Import Handler
 * CSV Format: Business Name, Address, Phone, Website, Rating, Reviews, Status
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    // Check if file was uploaded
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No file uploaded or upload error');
    }

    $file = $_FILES['csv_file'];
    $tmpPath = $file['tmp_name'];
    $fileName = $file['name'];

    // Validate file type
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        throw new Exception('Only CSV files are allowed');
    }

    // Read and parse CSV
    $handle = fopen($tmpPath, 'r');
    if (!$handle) {
        throw new Exception('Cannot read uploaded file');
    }

    $imported = 0;
    $skipped = 0;
    $errors = [];
    $lineNumber = 0;
    $hasHeader = isset($_POST['has_header']) && $_POST['has_header'] === '1';

    while (($row = fgetcsv($handle)) !== false) {
        $lineNumber++;

        // Skip header row if specified
        if ($lineNumber === 1 && $hasHeader) {
            continue;
        }

        // Skip empty rows
        if (empty(array_filter($row))) {
            continue;
        }

        // Parse columns based on config
        $businessName = trim($row[CSV_COL_BUSINESS_NAME] ?? '');
        $address = trim($row[CSV_COL_ADDRESS] ?? '');
        $phone = trim($row[CSV_COL_PHONE] ?? '');
        $website = trim($row[CSV_COL_WEBSITE] ?? '');
        $rating = trim($row[CSV_COL_RATING] ?? '0');
        $reviews = trim($row[CSV_COL_REVIEWS] ?? '0');
        $status = trim($row[CSV_COL_STATUS] ?? 'pending');

        // Validate required fields
        if (!$businessName || !$phone) {
            $skipped++;
            $errors[] = "Line {$lineNumber}: Missing business name or phone";
            continue;
        }

        // Clean phone number
        $cleanPhone = preg_replace('/[^0-9+]/', '', $phone);
        if (strlen($cleanPhone) < 10) {
            $skipped++;
            $errors[] = "Line {$lineNumber}: Invalid phone number '{$phone}'";
            continue;
        }

        // Check for duplicates
        $existing = db()->fetchOne(
            "SELECT id FROM leads WHERE phone = ? OR RIGHT(REPLACE(REPLACE(phone, ' ', ''), '-', ''), 10) = RIGHT(?, 10)",
            [$cleanPhone, $cleanPhone]
        );

        if ($existing) {
            $skipped++;
            continue; // Skip duplicates silently
        }

        // Clean rating
        $ratingVal = floatval($rating);
        $reviewsVal = intval(preg_replace('/[^0-9]/', '', $reviews));

        // Normalize status
        $validStatuses = ['pending', 'sent', 'replied', 'failed', 'opted_out'];
        if (!in_array(strtolower($status), $validStatuses)) {
            $status = 'pending';
        }

        // Insert lead
        db()->insert(
            "INSERT INTO leads (business_name, address, phone, website, rating, reviews, status, created_at, updated_at) 
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [$businessName, $address, $cleanPhone, $website, $ratingVal, $reviewsVal, strtolower($status)]
        );

        $imported++;
    }

    fclose($handle);

    echo json_encode([
        'success' => true,
        'imported' => $imported,
        'skipped' => $skipped,
        'total_processed' => $lineNumber - ($hasHeader ? 1 : 0),
        'errors' => array_slice($errors, 0, 10), // Return max 10 errors
        'message' => "{$imported} leads imported successfully" . ($skipped > 0 ? ", {$skipped} skipped" : "")
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
