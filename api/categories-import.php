<?php
/**
 * Categories Import API
 * Handles file upload, preview, and chunked import processing
 * Supports hierarchical categories via Name + Parent columns
 *
 * Actions:
 * - upload: Upload CSV/Excel file, return fileId
 * - preview: Parse file and return summary with sample rows
 * - process: Import categories in chunks
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
if (!hasPermission('categories', 'create')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied. You need categories.create permission.']);
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
 * Parse active value from various formats
 */
function parseActiveValue($value) {
    if ($value === '' || $value === null) return 1; // default active
    $v = strtolower(trim($value));
    if (in_array($v, ['yes', '1', 'true', 'active', 'y'])) return 1;
    if (in_array($v, ['no', '0', 'false', 'inactive', 'n'])) return 0;
    return 1; // default active
}

/**
 * Load all existing categories into a lookup map
 * Returns: [normalized_name => [id, name, parent_id, ...], ...]
 * Also returns a parent-grouped map for matching name+parent combos
 */
function loadExistingCategories($conn) {
    $categories = [];
    $stmt = $conn->query("SELECT id, parent_id, name, description, is_active FROM product_categories");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $categories[] = $row;
    }
    return $categories;
}

/**
 * Find a category by name and parent_id from a list
 */
function findCategoryByNameAndParent($categories, $name, $parentId) {
    $normalizedName = normalizeForMatch($name);
    foreach ($categories as $cat) {
        if (normalizeForMatch($cat['name']) === $normalizedName) {
            $catParent = $cat['parent_id'] === null ? null : (int)$cat['parent_id'];
            if ($catParent === $parentId) {
                return $cat;
            }
        }
    }
    return null;
}

/**
 * Find a category by name only (first match) from a list
 */
function findCategoryByName($categories, $name) {
    $normalizedName = normalizeForMatch($name);
    foreach ($categories as $cat) {
        if (normalizeForMatch($cat['name']) === $normalizedName) {
            return $cat;
        }
    }
    return null;
}

/**
 * Analyze parsed data and prepare preview
 */
function analyzeData($parsedData, $conn) {
    $rows = $parsedData['rows'];
    $header = $parsedData['header'];

    // Validate required columns
    if (!in_array('name', $header)) {
        return [
            'success' => false,
            'error' => 'Missing required column: name',
            'foundColumns' => $header
        ];
    }

    $existingCategories = loadExistingCategories($conn);

    // First pass: resolve parents and determine status for each row
    $newCategories = 0;
    $updatedCategories = 0;
    $autoCreatedParents = 0;
    $errors = [];
    $previewRows = [];

    // Track names we've seen in the file to handle parent references to earlier rows
    $fileCategories = []; // [{name, parent_name, resolved_parent_id}, ...]

    foreach ($rows as $index => $row) {
        $rowNum = $index + 2; // +2 for header and 0-index
        $name = trim($row['name'] ?? '');
        $parentName = trim($row['parent'] ?? '');
        $description = trim($row['description'] ?? '');
        $activeRaw = trim($row['active'] ?? '');
        $isActive = parseActiveValue($activeRaw);

        // Validate
        if (empty($name)) {
            $errors[] = ['row' => $rowNum, 'name' => '', 'errors' => ['Name is required']];
            continue;
        }

        // Resolve parent
        $parentId = null;
        $parentStatus = '';
        if (!empty($parentName)) {
            // Check existing DB categories
            $parentCat = findCategoryByName($existingCategories, $parentName);
            if ($parentCat) {
                $parentId = (int)$parentCat['id'];
                $parentStatus = 'exists';
            } else {
                // Check earlier rows in the file
                $foundInFile = false;
                foreach ($fileCategories as $fc) {
                    if (normalizeForMatch($fc['name']) === normalizeForMatch($parentName)) {
                        $foundInFile = true;
                        $parentStatus = 'in_file';
                        break;
                    }
                }
                if (!$foundInFile) {
                    // Will be auto-created
                    $parentStatus = 'auto_create';
                    $autoCreatedParents++;
                    // Add to file categories so later rows can reference it
                    $fileCategories[] = ['name' => $parentName, 'parent_name' => ''];
                }
            }
        }

        // Determine if this is new or update
        // For preview, check by name + parent (approximate - parent resolution may differ at process time)
        $existing = null;
        if ($parentId !== null) {
            $existing = findCategoryByNameAndParent($existingCategories, $name, $parentId);
        } else if (empty($parentName)) {
            // Root level - match by name with null parent
            $existing = findCategoryByNameAndParent($existingCategories, $name, null);
        }
        // If parent is from file or auto-created, it's likely new

        $status = $existing ? 'update' : 'new';
        if ($status === 'update') {
            $updatedCategories++;
        } else {
            $newCategories++;
        }

        $fileCategories[] = ['name' => $name, 'parent_name' => $parentName];

        $previewRow = [
            'row' => $rowNum,
            'name' => $name,
            'parent' => $parentName,
            'description' => $description,
            'active' => $isActive ? 'Yes' : 'No',
            'parent_status' => $parentStatus,
            'status' => $status
        ];

        if (count($previewRows) < 100) {
            $previewRows[] = $previewRow;
        }
    }

    return [
        'success' => true,
        'summary' => [
            'totalRows' => $parsedData['totalRows'],
            'newCategories' => $newCategories,
            'updatedCategories' => $updatedCategories,
            'autoCreatedParents' => $autoCreatedParents,
            'errors' => count($errors)
        ],
        'errors' => array_slice($errors, 0, 50),
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
            $fileId = uniqid('cat_import_', true);
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
            if (empty($fileId) || !preg_match('/^cat_import_[a-f0-9.]+$/', $fileId)) {
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

            echo json_encode($analysis);
            break;

        case 'process':
            $fileId = clean($_REQUEST['fileId'] ?? '');
            $offset = intval($_REQUEST['offset'] ?? 0);
            $limit = intval($_REQUEST['limit'] ?? 100);

            if (empty($fileId) || !preg_match('/^cat_import_[a-f0-9.]+$/', $fileId)) {
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

            // Load existing categories fresh each chunk
            $existingCategories = loadExistingCategories($conn);

            // Process chunk
            $chunk = array_slice($rows, $offset, $limit);
            $created = 0;
            $updated = 0;
            $autoCreated = 0;
            $errors = [];

            $conn->beginTransaction();

            try {
                foreach ($chunk as $index => $row) {
                    $rowNum = $offset + $index + 2;
                    $name = trim($row['name'] ?? '');
                    $parentName = trim($row['parent'] ?? '');
                    $description = trim($row['description'] ?? '');
                    $isActive = parseActiveValue(trim($row['active'] ?? ''));

                    // Skip empty names
                    if (empty($name)) {
                        $errors[] = ['row' => $rowNum, 'error' => 'Name is required'];
                        continue;
                    }

                    // Resolve parent
                    $parentId = null;
                    if (!empty($parentName)) {
                        $parentCat = findCategoryByName($existingCategories, $parentName);
                        if ($parentCat) {
                            $parentId = (int)$parentCat['id'];
                        } else {
                            // Auto-create parent as root-level active category
                            $stmt = $conn->prepare("
                                INSERT INTO product_categories (parent_id, name, description, is_active)
                                VALUES (NULL, ?, '', 1)
                            ");
                            $stmt->execute([$parentName]);
                            $parentId = (int)$conn->lastInsertId();
                            logAudit('categories', 'import_auto_create', $parentId);

                            // Add to existing list so subsequent rows can find it
                            $existingCategories[] = [
                                'id' => $parentId,
                                'parent_id' => null,
                                'name' => $parentName,
                                'description' => '',
                                'is_active' => 1
                            ];
                            $autoCreated++;
                        }
                    }

                    // Check if category with same name + parent already exists
                    $existing = findCategoryByNameAndParent($existingCategories, $name, $parentId);

                    if ($existing) {
                        // Update existing category
                        $stmt = $conn->prepare("
                            UPDATE product_categories
                            SET description = ?, is_active = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$description, $isActive, $existing['id']]);
                        logAudit('categories', 'import_update', $existing['id']);

                        // Update in our local list
                        foreach ($existingCategories as &$ec) {
                            if ((int)$ec['id'] === (int)$existing['id']) {
                                $ec['description'] = $description;
                                $ec['is_active'] = $isActive;
                                break;
                            }
                        }
                        unset($ec);

                        $updated++;
                    } else {
                        // Create new category
                        $stmt = $conn->prepare("
                            INSERT INTO product_categories (parent_id, name, description, is_active)
                            VALUES (?, ?, ?, ?)
                        ");
                        $stmt->execute([$parentId, $name, $description, $isActive]);
                        $newId = (int)$conn->lastInsertId();
                        logAudit('categories', 'import_create', $newId);

                        // Add to existing list
                        $existingCategories[] = [
                            'id' => $newId,
                            'parent_id' => $parentId,
                            'name' => $name,
                            'description' => $description,
                            'is_active' => $isActive
                        ];

                        $created++;
                    }
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
                'updated' => $updated,
                'autoCreated' => $autoCreated,
                'errors' => $errors,
                'done' => $done
            ]);
            break;

        case 'cleanup':
            $fileId = clean($_REQUEST['fileId'] ?? '');
            if (!empty($fileId) && preg_match('/^cat_import_[a-f0-9.]+$/', $fileId)) {
                @unlink($tempDir . $fileId . '.csv');
                @unlink($tempDir . $fileId . '.xlsx');
                @unlink($tempDir . $fileId . '.json');
            }

            echo json_encode(['success' => true]);
            break;

        case 'template':
            // Generate template CSV
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="categories_import_template.csv"');

            echo "name,parent,description,active\n";
            echo "\"Food\",,\"Main food category\",\"Yes\"\n";
            echo "\"Beverages\",\"Food\",\"All drinks\",\"Yes\"\n";
            echo "\"Hot Drinks\",\"Beverages\",\"Tea, coffee, etc.\",\"Yes\"\n";
            echo "\"Cold Drinks\",\"Beverages\",\"Juices, sodas, etc.\",\"Yes\"\n";
            echo "\"Snacks\",\"Food\",\"Light bites\",\"Yes\"\n";
            echo "\"Electronics\",,\"Electronic items\",\"Yes\"\n";
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
