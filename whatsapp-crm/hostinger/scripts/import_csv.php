<?php
/**
 * ============================================================
 * WhatsApp CRM - CSV Import Script
 * ============================================================
 * Imports leads from CSV file into MySQL database
 * 
 * Usage (CLI): php import_csv.php /path/to/file.csv
 * Usage (Web): Called from upload.php after file upload
 * 
 * Expected CSV columns:
 * Business Name, Address, Phone, Website, Rating, Reviews, Status
 */

// Load dependencies
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

// ============================================================
// DETERMINE INPUT SOURCE
// ============================================================

$csvFile = null;
$isWeb = (php_sapi_name() !== 'cli');

if (!$isWeb) {
    // CLI mode
    if ($argc < 2) {
        echo "Usage: php import_csv.php /path/to/leads.csv\n";
        exit(1);
    }
    $csvFile = $argv[1];
} else {
    // Web mode - expect file path as parameter
    $csvFile = $_POST['csv_path'] ?? $_GET['csv_path'] ?? null;
}

if (!$csvFile || !file_exists($csvFile)) {
    $msg = "CSV file not found: " . ($csvFile ?? 'none specified');
    logImport($msg, 'ERROR');
    if ($isWeb) {
        jsonResponse(['success' => false, 'error' => $msg], 400);
    } else {
        echo "[ERROR] {$msg}\n";
        exit(1);
    }
}

// ============================================================
// VALIDATE FILE
// ============================================================

$fileSize = filesize($csvFile);
if ($fileSize > MAX_CSV_SIZE) {
    $msg = "CSV file too large: " . round($fileSize / 1024 / 1024, 2) . "MB (max " . (MAX_CSV_SIZE / 1024 / 1024) . "MB)";
    logImport($msg, 'ERROR');
    if ($isWeb) {
        jsonResponse(['success' => false, 'error' => $msg], 400);
    } else {
        echo "[ERROR] {$msg}\n";
        exit(1);
    }
}

// ============================================================
// IMPORT PROCESS
// ============================================================

logImport("Starting import: {$csvFile}");
$startTime = microtime(true);

$handle = fopen($csvFile, 'r');
if (!$handle) {
    $msg = "Cannot open CSV file";
    logImport($msg, 'ERROR');
    if ($isWeb) jsonResponse(['success' => false, 'error' => $msg], 500);
    exit(1);
}

// Read header row
$header = fgetcsv($handle);
if (!$header) {
    fclose($handle);
    $msg = "CSV file is empty or invalid";
    logImport($msg, 'ERROR');
    if ($isWeb) jsonResponse(['success' => false, 'error' => $msg], 400);
    exit(1);
}

// Normalize header (trim, lowercase)
$header = array_map(function ($col) {
    return strtolower(trim(str_replace(['"', "'"], '', $col)));
}, $header);

// Map expected columns
$colMap = [
    'business_name' => findColumn($header, ['business name', 'business_name', 'name', 'shop name']),
    'address'       => findColumn($header, ['address', 'location', 'full address']),
    'phone'         => findColumn($header, ['phone', 'phone number', 'mobile', 'contact']),
    'website'       => findColumn($header, ['website', 'website url', 'url', 'site']),
    'rating'        => findColumn($header, ['rating', 'google rating', 'stars']),
    'reviews'       => findColumn($header, ['reviews', 'review count', 'total reviews', 'review_count']),
    'status'        => findColumn($header, ['status', 'business status', 'open/closed'])
];

// Verify essential columns exist
if ($colMap['business_name'] === null || $colMap['phone'] === null) {
    fclose($handle);
    $msg = "CSV must have 'Business Name' and 'Phone' columns. Found: " . implode(', ', $header);
    logImport($msg, 'ERROR');
    if ($isWeb) jsonResponse(['success' => false, 'error' => $msg], 400);
    exit(1);
}

logImport("Column mapping: " . json_encode($colMap));

// ============================================================
// PROCESS ROWS
// ============================================================

$db = getDB();
$stats = [
    'total'     => 0,
    'imported'  => 0,
    'skipped'   => 0,
    'duplicate' => 0,
    'no_phone'  => 0,
    'errors'    => 0
];

// Prepare insert statement
$insertSQL = "INSERT INTO leads 
    (business_name, address, locality, city, state, phone_raw, phone_clean, country_code, 
     website_url, website_status, rating, review_count, business_status, pitch_type, 
     language_preference, whatsapp_status, outreach_status)
    VALUES 
    (:business_name, :address, :locality, :city, :state, :phone_raw, :phone_clean, :country_code,
     :website_url, :website_status, :rating, :review_count, :business_status, :pitch_type,
     :language_preference, 'pending', 'pending')
    ON DUPLICATE KEY UPDATE
     business_name = VALUES(business_name),
     address = VALUES(address),
     updated_at = NOW()";

$stmt = $db->prepare($insertSQL);

$rowNum = 1;
while (($row = fgetcsv($handle)) !== false) {
    $rowNum++;
    $stats['total']++;

    try {
        // Extract values using column map
        $businessName = getColValue($row, $colMap['business_name']);
        $address      = getColValue($row, $colMap['address']);
        $phoneRaw     = getColValue($row, $colMap['phone']);
        $website      = getColValue($row, $colMap['website']);
        $rating       = getColValue($row, $colMap['rating']);
        $reviews      = getColValue($row, $colMap['reviews']);
        $status       = getColValue($row, $colMap['status']);

        // Skip if no business name
        if (empty($businessName)) {
            $stats['skipped']++;
            logImport("Row {$rowNum}: Skipped - no business name");
            continue;
        }

        // Process phone
        if (!isValidPhone($phoneRaw)) {
            $stats['no_phone']++;
            logImport("Row {$rowNum}: No valid phone for '{$businessName}'");
            continue;
        }

        $phoneClean = cleanPhone($phoneRaw);
        if (!$phoneClean) {
            $stats['no_phone']++;
            logImport("Row {$rowNum}: Could not clean phone '{$phoneRaw}' for '{$businessName}'");
            continue;
        }

        // Parse address
        $addressData = parseAddress($address ?? '');

        // Parse website
        $websiteData = parseWebsiteStatus($website ?? '');

        // Determine pitch type
        $pitchType = getPitchType($websiteData['status']);

        // Get language preference
        $langPref = getLanguagePreference($addressData['state']);

        // Clean rating/reviews
        $ratingClean = is_numeric($rating) ? floatval($rating) : null;
        $reviewsClean = is_numeric(str_replace(',', '', $reviews)) ? intval(str_replace(',', '', $reviews)) : 0;

        // Insert/Update
        $stmt->execute([
            ':business_name'      => sanitizeDB($businessName),
            ':address'            => sanitizeDB($address ?? ''),
            ':locality'           => $addressData['locality'],
            ':city'               => $addressData['city'],
            ':state'              => $addressData['state'],
            ':phone_raw'          => $phoneRaw,
            ':phone_clean'        => $phoneClean,
            ':country_code'       => '91',
            ':website_url'        => $websiteData['url'],
            ':website_status'     => $websiteData['status'],
            ':rating'             => $ratingClean,
            ':review_count'       => $reviewsClean,
            ':business_status'    => sanitizeDB($status ?? 'Open'),
            ':pitch_type'         => $pitchType,
            ':language_preference'=> $langPref
        ]);

        if ($stmt->rowCount() > 0) {
            $stats['imported']++;
        } else {
            $stats['duplicate']++;
        }

    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
            $stats['duplicate']++;
            logImport("Row {$rowNum}: Duplicate phone for '{$businessName}'");
        } else {
            $stats['errors']++;
            logImport("Row {$rowNum}: DB error - " . $e->getMessage(), 'ERROR');
        }
    } catch (Exception $e) {
        $stats['errors']++;
        logImport("Row {$rowNum}: Error - " . $e->getMessage(), 'ERROR');
    }
}

fclose($handle);

// ============================================================
// REPORT
// ============================================================

$elapsed = round(microtime(true) - $startTime, 2);
$summary = "Import complete in {$elapsed}s | Total: {$stats['total']} | Imported: {$stats['imported']} | Duplicates: {$stats['duplicate']} | No Phone: {$stats['no_phone']} | Skipped: {$stats['skipped']} | Errors: {$stats['errors']}";

logImport($summary);

if ($isWeb) {
    jsonResponse([
        'success' => true,
        'message' => 'Import completed successfully',
        'stats'   => $stats,
        'elapsed' => $elapsed
    ]);
} else {
    echo "\n╔══════════════════════════════════════════╗\n";
    echo "║         CSV IMPORT COMPLETE              ║\n";
    echo "╠══════════════════════════════════════════╣\n";
    echo "║  Total Rows:    {$stats['total']}\n";
    echo "║  Imported:      {$stats['imported']}\n";
    echo "║  Duplicates:    {$stats['duplicate']}\n";
    echo "║  No Phone:      {$stats['no_phone']}\n";
    echo "║  Skipped:       {$stats['skipped']}\n";
    echo "║  Errors:        {$stats['errors']}\n";
    echo "║  Time:          {$elapsed}s\n";
    echo "╚══════════════════════════════════════════╝\n";
}

// ============================================================
// HELPER: Find column index by possible names
// ============================================================
function findColumn(array $header, array $possibleNames): ?int {
    foreach ($possibleNames as $name) {
        $idx = array_search($name, $header);
        if ($idx !== false) {
            return $idx;
        }
    }
    return null;
}

// ============================================================
// HELPER: Get column value safely
// ============================================================
function getColValue(array $row, ?int $index): ?string {
    if ($index === null || !isset($row[$index])) {
        return null;
    }
    $val = trim($row[$index]);
    return ($val === '' || $val === 'N/A') ? null : $val;
}
