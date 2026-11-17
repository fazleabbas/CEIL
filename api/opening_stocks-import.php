<?php
/**
 * Opening Stocks Import API
 * Handles file upload, preview, and chunked import processing
 *
 * Actions:
 * - upload: Upload CSV/Excel file, return fileId
 * - preview: Parse file, match products, return summary
 * - process: Import opening stocks in chunks
 * - cleanup: Remove temp files
 * - template: Download CSV template
 */

header('Content-Type: application/json; charset=utf-8');

// Capture any stray output (warnings, notices, BOMs from includes) so it
// doesn't corrupt the JSON response.
ob_start();

// Convert PHP warnings/notices into exceptions so they surface as JSON errors
// instead of silently breaking the response body.
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Trap fatal errors (memory exhausted, parse errors, etc.) and return JSON
// instead of a half-rendered HTML error page.
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        // Drain ALL nested output buffers (server may have output_buffering on)
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success' => false,
            'error' => 'PHP fatal error: ' . $err['message'] . ' in ' . basename($err['file']) . ':' . $err['line']
        ]);
    }
});

require_once '../config/database.php';
require_once '../includes/functions.php';

/**
 * Safe wrapper around json_encode().
 *
 * json_encode() silently returns false (and produces no output via echo) when
 * the data contains malformed UTF-8 — which is common when product names were
 * pasted from Word/Excel using Windows-1252 encoding. Without this wrapper the
 * client just sees an empty 200 response and "Unexpected end of JSON input".
 *
 * Strategy:
 *   1. Try with JSON_INVALID_UTF8_SUBSTITUTE (replace bad bytes with U+FFFD)
 *      and JSON_PARTIAL_OUTPUT_ON_ERROR so a single bad row can't kill the
 *      whole response.
 *   2. If that still returns false, recursively scrub the data with
 *      mb_convert_encoding() to force valid UTF-8 and try again.
 *   3. If all else fails, return a JSON error describing what went wrong so
 *      the client at least sees a real message instead of an empty body.
 */
function safeJsonEncode($data) {
    $flags = JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE;
    $json = @json_encode($data, $flags);
    if ($json !== false) {
        return $json;
    }

    // Fallback: deep-scrub all strings to valid UTF-8
    $scrubbed = scrubUtf8($data);
    $json = @json_encode($scrubbed, $flags);
    if ($json !== false) {
        return $json;
    }

    // Last resort: return a JSON-encoded error so the client sees *something*
    return json_encode([
        'success' => false,
        'error' => 'json_encode failed: ' . json_last_error_msg(),
        'where' => 'safeJsonEncode'
    ]);
}

/**
 * Recursively convert all strings in a data structure to valid UTF-8.
 * Bad bytes get replaced with the Unicode replacement character.
 */
function scrubUtf8($data) {
    if (is_string($data)) {
        if (!mb_check_encoding($data, 'UTF-8')) {
            // Try Windows-1252 first (most common source of bad bytes), then
            // fall back to a forced UTF-8 conversion that drops invalid bytes.
            $converted = @mb_convert_encoding($data, 'UTF-8', 'Windows-1252');
            return $converted !== false ? $converted : mb_convert_encoding($data, 'UTF-8', 'UTF-8');
        }
        return $data;
    }
    if (is_array($data)) {
        $out = [];
        foreach ($data as $k => $v) {
            $out[$k] = scrubUtf8($v);
        }
        return $out;
    }
    return $data;
}

// Check if user is logged in
if (!isLoggedIn()) {
    if (ob_get_level() > 0) ob_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Check permissions
if (!hasPermission('opening_stocks', 'import')) {
    if (ob_get_level() > 0) ob_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied. You need opening_stocks.import permission.']);
    exit;
}

$action = $_REQUEST['action'] ?? '';
$tempDir = __DIR__ . '/../temp/';

// Ensure temp directory exists
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0755, true);
}

/**
 * Ensure a string is valid UTF-8. CSV files exported from Excel (especially
 * the plain "CSV (Comma delimited)" option on Windows) use Windows-1252, where
 * characters like ² (0xB2), ° (0xB0), € (0x80) are single bytes that are
 * invalid in UTF-8. This converts them so "50mm²" in the CSV matches "50mm²"
 * in the database.
 */
function ensureUtf8($str) {
    if ($str === null || $str === '') return $str;
    if (mb_check_encoding($str, 'UTF-8')) return $str;
    // Source is most likely Windows-1252 (CP1252) — Excel's default on Windows
    $converted = @mb_convert_encoding($str, 'UTF-8', 'Windows-1252');
    return $converted !== false ? $converted : $str;
}

/**
 * Normalize string for matching (trim, collapse spaces, lowercase)
 */
function normalizeForMatch($str) {
    if ($str === null) return '';
    // Ensure valid UTF-8 first so multibyte chars like ² survive lowercasing
    $str = ensureUtf8($str);
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $str)), 'UTF-8');
}

/**
 * Parse CSV file with streaming (memory efficient)
 */
function parseCSV($filePath, $limit = null) {
    $rows = [];
    $handle = fopen($filePath, 'r');
    if (!$handle) return ['error' => 'Cannot open file'];

    // Detect BOM and skip if present
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($handle);
    }

    // Read header
    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        return ['error' => 'Empty file or invalid CSV'];
    }

    // Normalize header names (header is ASCII — no encoding work needed)
    $header = array_map(function($h) {
        return strtolower(trim($h));
    }, $header);

    $count = 0;
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) === count($header)) {
            // Convert each field to valid UTF-8 (fixes Windows-1252 ² °  etc.)
            $row = array_map('ensureUtf8', $row);
            $rows[] = array_combine($header, $row);
        }
        $count++;
        if ($limit && count($rows) >= $limit) {
            break;
        }
    }

    // Count remaining rows if limit was applied
    $totalRows = $count;
    if ($limit && count($rows) >= $limit) {
        while (fgetcsv($handle) !== false) {
            $totalRows++;
        }
    }

    fclose($handle);

    return [
        'header' => $header,
        'rows' => $rows,
        'totalRows' => $totalRows
    ];
}

/**
 * Parse Excel file using SimpleXLSX
 */
function parseExcel($filePath, $limit = null) {
    require_once __DIR__ . '/../includes/SimpleXLSX.php';

    $xlsx = SimpleXLSX::parse($filePath);
    if (!$xlsx) {
        return ['error' => 'Cannot parse Excel file: ' . SimpleXLSX::parseError()];
    }

    $sheetData = $xlsx->rows();
    if (empty($sheetData)) {
        return ['error' => 'Empty Excel file'];
    }

    // First row is header
    $header = array_map(function($h) {
        return strtolower(trim($h ?? ''));
    }, $sheetData[0]);

    $rows = [];
    $totalRows = count($sheetData) - 1; // Exclude header

    for ($i = 1; $i < count($sheetData); $i++) {
        if ($limit && count($rows) >= $limit) {
            break;
        }
        $row = $sheetData[$i];
        // Pad row to match header length
        while (count($row) < count($header)) {
            $row[] = '';
        }
        if (count($row) >= count($header)) {
            $sliced = array_map('ensureUtf8', array_slice($row, 0, count($header)));
            $rows[] = array_combine($header, $sliced);
        }
    }

    return [
        'header' => $header,
        'rows' => $rows,
        'totalRows' => $totalRows
    ];
}

/**
 * Get file extension
 */
function getFileExtension($filename) {
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

/**
 * Load all active products indexed by normalized name and barcode
 */
function loadProducts($conn) {
    $products = [];
    $stmt = $conn->query("
        SELECT p.id, p.name, p.barcode, p.unit, pc.name as category_name
        FROM products p
        LEFT JOIN product_categories pc ON p.category_id = pc.id
        WHERE p.is_active = 1
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $normalizedName = normalizeForMatch($row['name']);
        $products['name:' . $normalizedName] = $row;
        if (!empty($row['barcode'])) {
            $normalizedBarcode = normalizeForMatch($row['barcode']);
            $products['barcode:' . $normalizedBarcode] = $row;
        }
    }
    return $products;
}

/**
 * Match a row to an existing product
 */
function matchProduct($row, $existingProducts) {
    $name = trim($row['product_name'] ?? '');
    $barcode = trim($row['barcode'] ?? '');

    // Match by name first
    if (!empty($name)) {
        $normalized = normalizeForMatch($name);
        if (isset($existingProducts['name:' . $normalized])) {
            return ['product' => $existingProducts['name:' . $normalized], 'matchedBy' => 'name'];
        }
    }

    // Fallback to barcode
    if (!empty($barcode)) {
        $normalized = normalizeForMatch($barcode);
        if (isset($existingProducts['barcode:' . $normalized])) {
            return ['product' => $existingProducts['barcode:' . $normalized], 'matchedBy' => 'barcode'];
        }
    }

    return null;
}

/**
 * Analyze parsed data for preview
 */
function analyzeData($parsedData, $conn, $locationId, $stockDate) {
    $rows = $parsedData['rows'];
    $header = $parsedData['header'];

    // Validate required columns
    $requiredColumns = ['product_name', 'quantity', 'rate_per_unit'];
    $missingColumns = [];
    foreach ($requiredColumns as $col) {
        if (!in_array($col, $header)) {
            $missingColumns[] = $col;
        }
    }

    if (!empty($missingColumns)) {
        return [
            'success' => false,
            'error' => 'Missing required columns: ' . implode(', ', $missingColumns),
            'foundColumns' => $header
        ];
    }

    // Load existing products
    $existingProducts = loadProducts($conn);

    // Load existing opening stocks for this location (date-independent — there's
    // only one opening stock per product+location regardless of stock_date).
    $existingStocks = [];
    $stmt = $conn->prepare("
        SELECT os.product_id, os.quantity, os.rate_per_unit, os.stock_date, p.name as product_name
        FROM opening_stocks os
        JOIN products p ON os.product_id = p.id
        WHERE os.location_id = ?
    ");
    $stmt->execute([$locationId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $existingStocks[$row['product_id']] = $row;
    }

    // Analyze each row
    $matched = [];
    $duplicates = [];
    $unmatched = [];
    $errors = [];
    $previewRows = [];

    foreach ($rows as $index => $row) {
        $rowNum = $index + 2; // +2 for header and 0-index
        $productName = trim($row['product_name'] ?? '');
        $quantity = floatval($row['quantity'] ?? 0);
        $rate = floatval($row['rate_per_unit'] ?? 0);
        $remarks = trim($row['remarks'] ?? '');

        // Validate row
        $rowErrors = [];
        if (empty($productName)) {
            $rowErrors[] = 'Product name is required';
        }
        if ($quantity <= 0) {
            $rowErrors[] = 'Quantity must be greater than 0';
        }
        if ($rate <= 0) {
            $rowErrors[] = 'Rate must be greater than 0';
        }

        if (!empty($rowErrors)) {
            $errors[] = [
                'row' => $rowNum,
                'product_name' => $productName,
                'errors' => $rowErrors
            ];
            continue;
        }

        // Try to match product
        $match = matchProduct($row, $existingProducts);

        $previewRow = [
            'row' => $rowNum,
            'product_name' => $productName,
            'quantity' => $quantity,
            'rate_per_unit' => $rate,
            'total_value' => round($quantity * $rate, 2),
            'remarks' => $remarks
        ];

        if (!$match) {
            $previewRow['status'] = 'unmatched';
            $unmatched[] = $previewRow;
        } else {
            $product = $match['product'];
            $previewRow['product_id'] = $product['id'];
            $previewRow['matched_name'] = $product['name'];
            $previewRow['matchedBy'] = $match['matchedBy'];
            $previewRow['unit'] = $product['unit'];
            $previewRow['category'] = $product['category_name'];

            // Check if opening stock already exists (any date — one per product+location)
            if (isset($existingStocks[$product['id']])) {
                $existing = $existingStocks[$product['id']];
                $previewRow['status'] = 'duplicate';
                $previewRow['existing_quantity'] = floatval($existing['quantity']);
                $previewRow['existing_rate'] = floatval($existing['rate_per_unit']);
                $previewRow['existing_date'] = $existing['stock_date'];
                $duplicates[] = $previewRow;
            } else {
                $previewRow['status'] = 'matched';
                $matched[] = $previewRow;
            }
        }

        // Include every analyzed row so the frontend can paginate client-side
        $previewRows[] = $previewRow;
    }

    return [
        'success' => true,
        'summary' => [
            'totalRows' => $parsedData['totalRows'],
            'matched' => count($matched),
            'duplicates' => count($duplicates),
            'unmatched' => count($unmatched),
            'errors' => count($errors)
        ],
        'errors' => array_slice($errors, 0, 50),
        'previewRows' => $previewRows,
        'columns' => $header
    ];
}

$response = null;

try {
    switch ($action) {
        case 'upload':
            // Handle file upload
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                $uploadErrors = [
                    UPLOAD_ERR_INI_SIZE => 'File exceeds server limit',
                    UPLOAD_ERR_FORM_SIZE => 'File exceeds form limit',
                    UPLOAD_ERR_PARTIAL => 'File partially uploaded',
                    UPLOAD_ERR_NO_FILE => 'No file uploaded',
                    UPLOAD_ERR_NO_TMP_DIR => 'Missing temp folder',
                    UPLOAD_ERR_CANT_WRITE => 'Failed to write file',
                    UPLOAD_ERR_EXTENSION => 'Upload blocked by extension'
                ];
                $errorCode = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
                throw new Exception($uploadErrors[$errorCode] ?? 'Upload failed');
            }

            $file = $_FILES['file'];
            $ext = getFileExtension($file['name']);

            if (!in_array($ext, ['csv', 'xlsx'])) {
                throw new Exception('Invalid file type. Only CSV and XLSX allowed.');
            }

            // Generate unique file ID
            $fileId = uniqid('os_import_', true);
            $tempPath = $tempDir . $fileId . '.' . $ext;

            if (!move_uploaded_file($file['tmp_name'], $tempPath)) {
                throw new Exception('Failed to save uploaded file');
            }

            // Store file info
            $infoPath = $tempDir . $fileId . '.json';
            file_put_contents($infoPath, json_encode([
                'originalName' => $file['name'],
                'extension' => $ext,
                'uploadedAt' => date('c'),
                'userId' => getUserId()
            ]));

            $response = [
                'success' => true,
                'fileId' => $fileId,
                'fileName' => $file['name']
            ];
            break;

        case 'preview':
            $fileId = clean($_REQUEST['fileId'] ?? '');
            $locationId = intval($_REQUEST['location_id'] ?? 0);
            $stockDate = clean($_REQUEST['stock_date'] ?? '');

            if (empty($fileId) || !preg_match('/^os_import_[a-f0-9.]+$/', $fileId)) {
                throw new Exception('Invalid file ID');
            }
            if (empty($locationId)) {
                throw new Exception('Location is required');
            }
            if (empty($stockDate)) {
                throw new Exception('Stock date is required');
            }

            // Find the file
            $csvPath = $tempDir . $fileId . '.csv';
            $xlsxPath = $tempDir . $fileId . '.xlsx';

            // Cap preview parsing at 20,000 rows. Anything above that is rare
            // for opening-stock imports and would bloat the JSON payload.
            $previewParseCap = 20000;
            if (file_exists($csvPath)) {
                $parsedData = parseCSV($csvPath, $previewParseCap);
            } elseif (file_exists($xlsxPath)) {
                $parsedData = parseExcel($xlsxPath, $previewParseCap);
            } else {
                throw new Exception('File not found or expired');
            }

            if (isset($parsedData['error'])) {
                throw new Exception($parsedData['error']);
            }

            // Analyze data
            $response = analyzeData($parsedData, $conn, $locationId, $stockDate);
            break;

        case 'process':
            $fileId = clean($_REQUEST['fileId'] ?? '');
            $locationId = intval($_REQUEST['location_id'] ?? 0);
            $stockDate = clean($_REQUEST['stock_date'] ?? '');
            $offset = intval($_REQUEST['offset'] ?? 0);
            $limit = intval($_REQUEST['limit'] ?? 100);

            if (empty($fileId) || !preg_match('/^os_import_[a-f0-9.]+$/', $fileId)) {
                throw new Exception('Invalid file ID');
            }
            if (empty($locationId)) {
                throw new Exception('Location is required');
            }
            if (empty($stockDate)) {
                throw new Exception('Stock date is required');
            }

            // Find the file
            $csvPath = $tempDir . $fileId . '.csv';
            $xlsxPath = $tempDir . $fileId . '.xlsx';

            if (file_exists($csvPath)) {
                $parsedData = parseCSV($csvPath);
            } elseif (file_exists($xlsxPath)) {
                $parsedData = parseExcel($xlsxPath);
            } else {
                throw new Exception('File not found or expired');
            }

            if (isset($parsedData['error'])) {
                throw new Exception($parsedData['error']);
            }

            $rows = $parsedData['rows'];
            $totalRows = count($rows);

            // Load existing products
            $existingProducts = loadProducts($conn);

            // Process chunk. Duplicate (existing opening stock for this product+location,
            // any date) is always skipped with a reason — users edit those via the main
            // form, not the importer.
            $chunk = array_slice($rows, $offset, $limit);
            $created = 0;
            $skipped = 0;
            $skippedItems = [];
            $errors = [];

            $conn->beginTransaction();

            try {
                foreach ($chunk as $index => $row) {
                    $rowNum = $offset + $index + 2;
                    $productName = trim($row['product_name'] ?? '');
                    $quantity = floatval($row['quantity'] ?? 0);
                    $rate = floatval($row['rate_per_unit'] ?? 0);
                    $remarks = trim($row['remarks'] ?? '');

                    if (empty($productName) || $quantity <= 0 || $rate <= 0) {
                        $reasons = [];
                        if (empty($productName)) $reasons[] = 'empty product name';
                        if ($quantity <= 0) $reasons[] = 'invalid quantity';
                        if ($rate <= 0) $reasons[] = 'invalid rate';
                        $skippedItems[] = [
                            'row' => $rowNum,
                            'product_name' => $productName,
                            'reason' => 'Invalid data (' . implode(', ', $reasons) . ')'
                        ];
                        $skipped++;
                        continue;
                    }

                    $match = matchProduct($row, $existingProducts);
                    if (!$match) {
                        $skippedItems[] = [
                            'row' => $rowNum,
                            'product_name' => $productName,
                            'reason' => 'Unmatched product'
                        ];
                        $skipped++;
                        continue;
                    }

                    $productId = $match['product']['id'];

                    $checkStmt = $conn->prepare("
                        SELECT id, stock_date FROM opening_stocks
                        WHERE location_id = ? AND product_id = ?
                    ");
                    $checkStmt->execute([$locationId, $productId]);
                    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

                    if ($existing) {
                        $skippedItems[] = [
                            'row' => $rowNum,
                            'product_name' => $productName,
                            'reason' => 'Duplicate — opening stock already exists (date: ' . $existing['stock_date'] . '). Edit via the Opening Stocks screen.'
                        ];
                        $skipped++;
                        continue;
                    }

                    $stmt = $conn->prepare("
                        INSERT INTO opening_stocks
                        (location_id, product_id, stock_date, quantity, rate_per_unit, remarks, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$locationId, $productId, $stockDate, $quantity, $rate, $remarks ?: null, getUserId()]);

                    $stmt = $conn->prepare("
                        INSERT INTO stock_batches
                        (location_id, product_id, batch_date, transaction_type, quantity_in, quantity_remaining, rate_per_unit)
                        VALUES (?, ?, ?, 'opening', ?, ?, ?)
                    ");
                    $stmt->execute([$locationId, $productId, $stockDate, $quantity, $quantity, $rate]);

                    $created++;
                }

                $conn->commit();
            } catch (Exception $e) {
                $conn->rollBack();
                throw $e;
            }

            $processed = $offset + count($chunk);
            $done = $processed >= $totalRows;

            if ($done) {
                logAudit('opening_stocks', 'import', null, null, [
                    'location_id' => $locationId,
                    'stock_date' => $stockDate,
                    'created' => $created,
                    'skipped' => $skipped
                ]);
            }

            $response = [
                'success' => true,
                'processed' => $processed,
                'total' => $totalRows,
                'created' => $created,
                'skipped' => $skipped,
                'skippedItems' => $skippedItems,
                'errors' => $errors,
                'done' => $done
            ];
            break;

        case 'cleanup':
            $fileId = clean($_REQUEST['fileId'] ?? '');
            if (!empty($fileId) && preg_match('/^os_import_[a-f0-9.]+$/', $fileId)) {
                @unlink($tempDir . $fileId . '.csv');
                @unlink($tempDir . $fileId . '.xlsx');
                @unlink($tempDir . $fileId . '.json');
            }

            $response = ['success' => true];
            break;

        case 'template':
            // Generate template CSV — discard JSON buffer and stream CSV directly
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="opening_stocks_import_template.csv"');

            echo "product_name,quantity,rate_per_unit,remarks\n";
            echo "\"Cement 50kg\",\"100\",\"15000\",\"Optional remark\"\n";
            echo "\"Steel Rod 12mm\",\"50\",\"25000\",\"\"\n";
            exit;

        default:
            throw new Exception('Invalid action');
    }

} catch (Throwable $e) {
    // Roll back if a transaction is open
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) {
        $conn->rollBack();
    }

    // Drain all output buffers (capture stray output from the innermost one,
    // then clean any nested php.ini-level buffers too)
    $strayOutput = '';
    while (ob_get_level() > 0) {
        $chunk = ob_get_clean();
        if ($chunk !== false) {
            $strayOutput = $chunk . $strayOutput;
        }
    }

    if (!headers_sent()) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
    }

    $response = [
        'success' => false,
        'error' => $e->getMessage(),
        'where' => basename($e->getFile()) . ':' . $e->getLine()
    ];
    if ($strayOutput !== '' && $strayOutput !== false) {
        // Trim huge HTML error pages so the response stays manageable
        $response['stray_output'] = mb_substr(trim($strayOutput), 0, 500);
    }
    echo safeJsonEncode($response);
    exit;
}

// Success path: drain ALL output buffers (capture stray output), then emit JSON
$strayOutput = '';
while (ob_get_level() > 0) {
    $chunk = ob_get_clean();
    if ($chunk !== false) {
        $strayOutput = $chunk . $strayOutput;
    }
}
if ($strayOutput !== '' && is_array($response)) {
    $response['stray_output'] = mb_substr(trim($strayOutput), 0, 500);
}
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}
echo safeJsonEncode($response);
