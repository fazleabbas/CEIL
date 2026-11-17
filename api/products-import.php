<?php
/**
 * Products Import API
 * Handles file upload, preview, and chunked import processing
 * 
 * Actions:
 * - upload: Upload CSV/Excel file, return fileId
 * - preview: Parse file and return summary with sample rows
 * - process: Import products in chunks
 * - cleanup: Remove temp file
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
if (!hasPermission('products', 'import')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied. You need products.import permission.']);
    exit;
}

$action = $_REQUEST['action'] ?? '';
$tempDir = __DIR__ . '/../temp/';

// Ensure temp directory exists
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0755, true);
}

/**
 * Convert a value to valid UTF-8.
 * Files exported from Excel/Windows are often Windows-1252 (Latin-1) encoded,
 * containing bytes like smart quotes (0x93/0x94) or × (0xD7) that are invalid
 * standalone UTF-8. Such bytes make json_encode() return false (empty output),
 * which the browser sees as "Unexpected end of JSON input".
 * Strings that are already valid UTF-8 are returned unchanged.
 */
function toUtf8($str) {
    if ($str === null || $str === '') {
        return $str;
    }
    if (mb_check_encoding($str, 'UTF-8')) {
        return $str;
    }
    // Windows-1252 is a superset of Latin-1 and covers common Excel exports
    return mb_convert_encoding($str, 'UTF-8', 'Windows-1252');
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
        return strtolower(trim(toUtf8($h)));
    }, $header);

    $count = 0;
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) === count($header)) {
            $rows[] = array_combine($header, array_map('toUtf8', $row));
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
    // Include SimpleXLSX
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
        return strtolower(trim(toUtf8($h ?? '')));
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
            $rows[] = array_combine($header, array_map('toUtf8', array_slice($row, 0, count($header))));
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
    $requiredColumns = ['name', 'category', 'unit'];
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
    
    // Load existing products and categories
    $existingProducts = [];
    $stmt = $conn->query("SELECT id, name, barcode FROM products WHERE is_active = 1");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $normalizedName = normalizeForMatch($row['name']);
        $existingProducts['name:' . $normalizedName] = $row;
        if (!empty($row['barcode'])) {
            $normalizedBarcode = normalizeForMatch($row['barcode']);
            $existingProducts['barcode:' . $normalizedBarcode] = $row;
        }
    }
    
    $existingCategories = [];
    $stmt = $conn->query("SELECT id, name FROM product_categories WHERE is_active = 1");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $existingCategories[normalizeForMatch($row['name'])] = $row;
    }
    
    // Analyze each row
    $newProducts = [];
    $updateProducts = [];
    $newCategories = [];
    $errors = [];
    $previewRows = [];
    
    foreach ($rows as $index => $row) {
        $rowNum = $index + 2; // +2 because of header and 0-index
        $name = trim($row['name'] ?? '');
        $brand = trim($row['brand'] ?? '');
        $category = trim($row['category'] ?? '');
        $unit = trim($row['unit'] ?? '');
        $barcode = trim($row['barcode'] ?? '');
        $price = floatval($row['default_selling_price'] ?? $row['price'] ?? 0);
        $description = trim($row['description'] ?? '');
        
        // Validate row
        $rowErrors = [];
        if (empty($name)) {
            $rowErrors[] = 'Name is required';
        }
        if (empty($category)) {
            $rowErrors[] = 'Category is required';
        }
        if (empty($unit)) {
            $rowErrors[] = 'Unit is required';
        }
        
        if (!empty($rowErrors)) {
            $errors[] = [
                'row' => $rowNum,
                'name' => $name,
                'errors' => $rowErrors
            ];
            continue;
        }
        
        // Check for existing product
        $existingProduct = null;
        $matchedBy = null;
        
        // Match by barcode first
        if (!empty($barcode)) {
            $normalizedBarcode = normalizeForMatch($barcode);
            if (isset($existingProducts['barcode:' . $normalizedBarcode])) {
                $existingProduct = $existingProducts['barcode:' . $normalizedBarcode];
                $matchedBy = 'barcode';
            }
        }
        
        // Match by name if not found by barcode
        if (!$existingProduct) {
            $normalizedName = normalizeForMatch($name);
            if (isset($existingProducts['name:' . $normalizedName])) {
                $existingProduct = $existingProducts['name:' . $normalizedName];
                $matchedBy = 'name';
            }
        }
        
        // Check for new category
        $normalizedCategory = normalizeForMatch($category);
        $isNewCategory = !isset($existingCategories[$normalizedCategory]);
        if ($isNewCategory && !in_array($normalizedCategory, array_map('normalizeForMatch', $newCategories))) {
            $newCategories[] = $category;
        }
        
        $previewRow = [
            'row' => $rowNum,
            'name' => $name,
            'brand' => $brand,
            'category' => $category,
            'unit' => $unit,
            'barcode' => $barcode,
            'price' => $price,
            'status' => $existingProduct ? 'update' : 'new',
            'matchedBy' => $matchedBy,
            'existingId' => $existingProduct ? $existingProduct['id'] : null,
            'isNewCategory' => $isNewCategory
        ];
        
        if ($existingProduct) {
            $updateProducts[] = $previewRow;
        } else {
            $newProducts[] = $previewRow;
        }
        
        $previewRows[] = $previewRow;
    }
    
    return [
        'success' => true,
        'summary' => [
            'totalRows' => $parsedData['totalRows'],
            'newProducts' => count($newProducts),
            'updateProducts' => count($updateProducts),
            'newCategories' => count($newCategories),
            'errors' => count($errors)
        ],
        'newCategories' => $newCategories,
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
            $fileId = uniqid('import_', true);
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
            if (empty($fileId) || !preg_match('/^import_[a-f0-9.]+$/', $fileId)) {
                throw new Exception('Invalid file ID');
            }
            
            // Find the file
            $csvPath = $tempDir . $fileId . '.csv';
            $xlsxPath = $tempDir . $fileId . '.xlsx';
            
            if (file_exists($csvPath)) {
                $parsedData = parseCSV($csvPath, 5000); // Limit to 5000 for preview analysis
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
            
            // Store parsed data for processing
            $dataPath = $tempDir . $fileId . '_data.json';
            
            // For large files, we need to re-parse during processing
            // Store only metadata and analysis results
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
            
            if (empty($fileId) || !preg_match('/^import_[a-f0-9.]+$/', $fileId)) {
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
            
            // Load existing data
            $existingProducts = [];
            $stmt = $conn->query("SELECT id, name, barcode FROM products");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $normalizedName = normalizeForMatch($row['name']);
                $existingProducts['name:' . $normalizedName] = $row;
                if (!empty($row['barcode'])) {
                    $normalizedBarcode = normalizeForMatch($row['barcode']);
                    $existingProducts['barcode:' . $normalizedBarcode] = $row;
                }
            }
            
            $existingCategories = [];
            $stmt = $conn->query("SELECT id, name FROM product_categories");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $existingCategories[normalizeForMatch($row['name'])] = $row;
            }
            
            $existingUnits = [];
            $stmt = $conn->query("SELECT id, name FROM units WHERE is_active = 1");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $existingUnits[normalizeForMatch($row['name'])] = $row;
            }
            
            // Process chunk
            $chunk = array_slice($rows, $offset, $limit);
            $created = 0;
            $updated = 0;
            $errors = [];
            $categoriesCreated = 0;
            
            $conn->beginTransaction();
            
            try {
                foreach ($chunk as $index => $row) {
                    $rowNum = $offset + $index + 2;
                    $name = trim($row['name'] ?? '');
                    $brand = trim($row['brand'] ?? '');
                    $category = trim($row['category'] ?? '');
                    $unit = trim($row['unit'] ?? '');
                    $barcode = trim($row['barcode'] ?? '');
                    $price = floatval($row['default_selling_price'] ?? $row['price'] ?? 0);
                    $description = trim($row['description'] ?? '');
                    
                    // Skip invalid rows
                    if (empty($name) || empty($category) || empty($unit)) {
                        $errors[] = ['row' => $rowNum, 'error' => 'Missing required fields'];
                        continue;
                    }
                    
                    // Get or create category
                    $normalizedCategory = normalizeForMatch($category);
                    if (!isset($existingCategories[$normalizedCategory])) {
                        $stmt = $conn->prepare("INSERT INTO product_categories (name, is_active) VALUES (?, 1)");
                        $stmt->execute([$category]);
                        $categoryId = $conn->lastInsertId();
                        $existingCategories[$normalizedCategory] = ['id' => $categoryId, 'name' => $category];
                        $categoriesCreated++;
                    }
                    $categoryId = $existingCategories[$normalizedCategory]['id'];
                    
                    // Resolve unit (use name as-is if not in units table)
                    $normalizedUnit = normalizeForMatch($unit);
                    $unitValue = $unit;
                    if (isset($existingUnits[$normalizedUnit])) {
                        $unitValue = $existingUnits[$normalizedUnit]['name'];
                    }
                    
                    // Check if product exists
                    $existingProduct = null;
                    if (!empty($barcode)) {
                        $normalizedBarcode = normalizeForMatch($barcode);
                        if (isset($existingProducts['barcode:' . $normalizedBarcode])) {
                            $existingProduct = $existingProducts['barcode:' . $normalizedBarcode];
                        }
                    }
                    if (!$existingProduct) {
                        $normalizedName = normalizeForMatch($name);
                        if (isset($existingProducts['name:' . $normalizedName])) {
                            $existingProduct = $existingProducts['name:' . $normalizedName];
                        }
                    }
                    
                    if ($existingProduct) {
                        // Update existing product
                        $stmt = $conn->prepare("
                            UPDATE products SET
                                name = ?,
                                brand = ?,
                                category_id = ?,
                                unit = ?,
                                barcode = ?,
                                default_selling_price = ?,
                                description = ?,
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $name,
                            $brand ?: null,
                            $categoryId,
                            $unitValue,
                            $barcode ?: null,
                            $price,
                            $description ?: null,
                            $existingProduct['id']
                        ]);
                        $updated++;
                    } else {
                        // Create new product
                        $stmt = $conn->prepare("
                            INSERT INTO products (name, brand, category_id, unit, barcode, default_selling_price, description, is_active)
                            VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                        ");
                        $stmt->execute([
                            $name,
                            $brand ?: null,
                            $categoryId,
                            $unitValue,
                            $barcode ?: null,
                            $price,
                            $description ?: null
                        ]);
                        $newId = $conn->lastInsertId();
                        
                        // Add to existing products for duplicate detection in same batch
                        $normalizedName = normalizeForMatch($name);
                        $existingProducts['name:' . $normalizedName] = ['id' => $newId, 'name' => $name, 'barcode' => $barcode];
                        if (!empty($barcode)) {
                            $existingProducts['barcode:' . normalizeForMatch($barcode)] = ['id' => $newId, 'name' => $name, 'barcode' => $barcode];
                        }
                        
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
                'categoriesCreated' => $categoriesCreated,
                'errors' => $errors,
                'done' => $done
            ]);
            break;
            
        case 'cleanup':
            $fileId = clean($_REQUEST['fileId'] ?? '');
            if (!empty($fileId) && preg_match('/^import_[a-f0-9.]+$/', $fileId)) {
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
            header('Content-Disposition: attachment; filename="products_import_template.csv"');
            
            echo "name,brand,category,unit,barcode,default_selling_price,description\n";
            echo "\"Sample Product\",\"Brand Name\",\"Category Name\",\"pcs\",\"1234567890\",\"1000\",\"Optional description\"\n";
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
