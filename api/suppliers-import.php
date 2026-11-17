<?php
/**
 * Suppliers Import API
 * Handles file upload, preview, and chunked import processing
 *
 * Actions:
 * - upload: Upload CSV/Excel file, return fileId
 * - preview: Parse file and return summary with sample rows
 * - process: Import suppliers in chunks
 * - cleanup: Remove temp file
 * - template: Download CSV template
 */

header('Content-Type: application/json; charset=utf-8');

require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Check permissions
if (!hasPermission('suppliers', 'import')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied. You need suppliers.import permission.']);
    exit;
}

$action = $_REQUEST['action'] ?? '';
$tempDir = __DIR__ . '/../temp/';

// Ensure temp directory exists
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0755, true);
}

/**
 * Normalize string for matching (trim, collapse spaces, lowercase)
 */
function normalizeForMatch($str) {
    if ($str === null) return '';
    return strtolower(trim(preg_replace('/\s+/', ' ', $str)));
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

    // Normalize header names
    $header = array_map(function($h) {
        return strtolower(trim($h));
    }, $header);

    $count = 0;
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) === count($header)) {
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
            $rows[] = array_combine($header, array_slice($row, 0, count($header)));
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
 * Analyze parsed data and prepare preview
 */
function analyzeData($parsedData, $conn) {
    $rows = $parsedData['rows'];
    $header = $parsedData['header'];

    // Validate required columns
    $requiredColumns = ['name'];
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

    // Load existing ledger account names for duplicate detection
    $existingAccounts = [];
    $stmt = $conn->query("SELECT id, name FROM ledger_accounts");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $existingAccounts[normalizeForMatch($row['name'])] = $row;
    }

    // Analyze each row
    $newSuppliers = [];
    $skippedDuplicates = [];
    $errors = [];
    $previewRows = [];

    // Track names within the file itself to detect intra-file duplicates
    $seenNames = [];

    foreach ($rows as $index => $row) {
        $rowNum = $index + 2; // +2 because of header and 0-index
        $name = trim($row['name'] ?? '');
        $contactPerson = trim($row['contact_person'] ?? '');
        $phone = trim($row['phone'] ?? '');
        $email = trim($row['email'] ?? '');
        $address = trim($row['address'] ?? '');
        $gstin = trim($row['gstin'] ?? '');
        $pan = trim($row['pan'] ?? '');
        $openingBalance = floatval($row['opening_balance'] ?? 0);

        // Validate row
        $rowErrors = [];
        if (empty($name)) {
            $rowErrors[] = 'Name is required';
        }

        // Email validation (if provided)
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $rowErrors[] = 'Invalid email format';
        }

        if (!empty($rowErrors)) {
            $errors[] = [
                'row' => $rowNum,
                'name' => $name,
                'errors' => $rowErrors
            ];
            continue;
        }

        // Check for duplicate in existing ledger accounts
        $normalizedName = normalizeForMatch($name);
        $isDuplicate = isset($existingAccounts[$normalizedName]);

        // Also check for intra-file duplicates
        if (!$isDuplicate && isset($seenNames[$normalizedName])) {
            $isDuplicate = true;
        }
        $seenNames[$normalizedName] = true;

        $previewRow = [
            'row' => $rowNum,
            'name' => $name,
            'contact_person' => $contactPerson,
            'phone' => $phone,
            'email' => $email,
            'opening_balance' => $openingBalance,
            'status' => $isDuplicate ? 'duplicate' : 'new'
        ];

        if ($isDuplicate) {
            $skippedDuplicates[] = $previewRow;
        } else {
            $newSuppliers[] = $previewRow;
        }

        // Only include first 100 rows in preview
        if (count($previewRows) < 100) {
            $previewRows[] = $previewRow;
        }
    }

    return [
        'success' => true,
        'summary' => [
            'totalRows' => $parsedData['totalRows'],
            'newSuppliers' => count($newSuppliers),
            'skippedDuplicates' => count($skippedDuplicates),
            'errors' => count($errors)
        ],
        'errors' => array_slice($errors, 0, 50), // Limit errors shown
        'previewRows' => $previewRows,
        'columns' => $header
    ];
}

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
            $fileId = uniqid('sup_import_', true);
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

            echo json_encode([
                'success' => true,
                'fileId' => $fileId,
                'fileName' => $file['name']
            ]);
            break;

        case 'preview':
            $fileId = clean($_REQUEST['fileId'] ?? '');
            if (empty($fileId) || !preg_match('/^sup_import_[a-f0-9.]+$/', $fileId)) {
                throw new Exception('Invalid file ID');
            }

            // Find the file
            $csvPath = $tempDir . $fileId . '.csv';
            $xlsxPath = $tempDir . $fileId . '.xlsx';

            if (file_exists($csvPath)) {
                $parsedData = parseCSV($csvPath, 5000);
            } elseif (file_exists($xlsxPath)) {
                $parsedData = parseExcel($xlsxPath, 5000);
            } else {
                throw new Exception('File not found or expired');
            }

            if (isset($parsedData['error'])) {
                throw new Exception($parsedData['error']);
            }

            // Analyze data
            $analysis = analyzeData($parsedData, $conn);

            // Store analysis for reference
            $dataPath = $tempDir . $fileId . '_data.json';
            file_put_contents($dataPath, json_encode([
                'analysis' => $analysis,
                'analyzedAt' => date('c')
            ]));

            echo json_encode($analysis);
            break;

        case 'process':
            $fileId = clean($_REQUEST['fileId'] ?? '');
            $offset = intval($_REQUEST['offset'] ?? 0);
            $limit = intval($_REQUEST['limit'] ?? 100);

            if (empty($fileId) || !preg_match('/^sup_import_[a-f0-9.]+$/', $fileId)) {
                throw new Exception('Invalid file ID');
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

            // Load existing ledger account names for duplicate detection
            $existingAccounts = [];
            $stmt = $conn->query("SELECT id, name FROM ledger_accounts");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $existingAccounts[normalizeForMatch($row['name'])] = $row;
            }

            // Get default currency
            $defaultCurrency = getDefaultCurrency();

            // Process chunk
            $chunk = array_slice($rows, $offset, $limit);
            $created = 0;
            $skipped = 0;
            $errors = [];

            $conn->beginTransaction();

            try {
                foreach ($chunk as $index => $row) {
                    $rowNum = $offset + $index + 2;
                    $name = trim($row['name'] ?? '');
                    $contactPerson = trim($row['contact_person'] ?? '');
                    $phone = trim($row['phone'] ?? '');
                    $email = trim($row['email'] ?? '');
                    $address = trim($row['address'] ?? '');
                    $gstin = trim($row['gstin'] ?? '');
                    $pan = trim($row['pan'] ?? '');
                    $openingBalance = floatval($row['opening_balance'] ?? 0);

                    // Skip invalid rows
                    if (empty($name)) {
                        $errors[] = ['row' => $rowNum, 'error' => 'Name is required'];
                        continue;
                    }

                    // Email validation
                    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $errors[] = ['row' => $rowNum, 'error' => 'Invalid email format'];
                        continue;
                    }

                    // Check for duplicate
                    $normalizedName = normalizeForMatch($name);
                    if (isset($existingAccounts[$normalizedName])) {
                        $skipped++;
                        continue;
                    }

                    // Step 1: Create ledger account
                    $stmt = $conn->prepare("
                        INSERT INTO ledger_accounts
                        (name, account_type, opening_balance, current_balance, currency_id, is_active)
                        VALUES (?, 'supplier', ?, ?, ?, 1)
                    ");
                    $stmt->execute([$name, $openingBalance, $openingBalance, $defaultCurrency['id']]);
                    $ledgerAccountId = $conn->lastInsertId();

                    // Step 2: Create supplier
                    $stmt = $conn->prepare("
                        INSERT INTO suppliers
                        (ledger_account_id, contact_person, phone, email, address, gstin, pan, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                    ");
                    $stmt->execute([
                        $ledgerAccountId,
                        $contactPerson ?: null,
                        $phone ?: null,
                        $email ?: null,
                        $address ?: null,
                        $gstin ?: null,
                        $pan ?: null
                    ]);
                    $newSupplierId = $conn->lastInsertId();

                    // Step 3: Update ledger account with reference_id
                    $stmt = $conn->prepare("UPDATE ledger_accounts SET reference_id = ? WHERE id = ?");
                    $stmt->execute([$newSupplierId, $ledgerAccountId]);

                    // Add to existing accounts to prevent duplicates within same batch
                    $existingAccounts[$normalizedName] = ['id' => $ledgerAccountId, 'name' => $name];

                    logAudit('suppliers', 'import', $newSupplierId);

                    $created++;
                }

                $conn->commit();
            } catch (Exception $e) {
                $conn->rollBack();
                throw $e;
            }

            $processed = $offset + count($chunk);
            $done = $processed >= $totalRows;

            echo json_encode([
                'success' => true,
                'processed' => $processed,
                'total' => $totalRows,
                'created' => $created,
                'skipped' => $skipped,
                'errors' => $errors,
                'done' => $done
            ]);
            break;

        case 'cleanup':
            $fileId = clean($_REQUEST['fileId'] ?? '');
            if (!empty($fileId) && preg_match('/^sup_import_[a-f0-9.]+$/', $fileId)) {
                // Delete all related files
                @unlink($tempDir . $fileId . '.csv');
                @unlink($tempDir . $fileId . '.xlsx');
                @unlink($tempDir . $fileId . '.json');
                @unlink($tempDir . $fileId . '_data.json');
            }

            echo json_encode(['success' => true]);
            break;

        case 'template':
            // Generate template CSV
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="suppliers_import_template.csv"');

            echo "name,contact_person,phone,email,address,gstin,pan,opening_balance\n";
            echo "\"Sample Supplier\",\"John Doe\",\"+255123456789\",\"supplier@example.com\",\"123 Main St\",\"\",\"\",\"0\"\n";
            exit;

        default:
            throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
