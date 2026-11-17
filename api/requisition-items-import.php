<?php
/**
 * Requisition Voucher line-item import — reads a spreadsheet of products and
 * hands the matched lines BACK TO THE BROWSER as JSON.
 *
 * IT NEVER WRITES ANYTHING. The rows it returns are fed into the form's own
 * addRow() and saved by the form's own POST handler, so every existing rule
 * (destination lock, at-least-one-item, permissions, approval workflow, the
 * Available column, BOM balances) still runs exactly as it does for typed rows.
 * That is the whole point: no second write path to keep in sync.
 *
 * Modelled on api/bom-boq-import.php, which is left completely alone — adding a
 * third document type to that file would complicate what BOM and BOQ both
 * depend on.
 *
 * Actions:
 *   template - the whole active product catalogue, ready to type quantities into
 *   parse    - accept an uploaded CSV/XLSX, match products, return JSON lines
 *
 * SHEET FORMAT
 *   Product ID | Product | Qty | Remarks
 *
 * No Rate (a requisition has none) and no Breakdown (requisition_voucher_details
 * has no breakdown table). Repeating a product MERGES the rows, summing the
 * quantity — a requisition line is just product + quantity, so two rows for the
 * same product are the same request stated twice.
 */

require_once '../config/database.php';
require_once '../includes/functions.php';

$action = $_REQUEST['action'] ?? '';

/**
 * Fail as JSON. Separate from the template branch, which must not emit a JSON
 * content-type header (it sends a file).
 */
function rvi_fail($httpCode, $message) {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

if (!isLoggedIn()) {
    rvi_fail(401, __('unauthorized'));
}

// Same gate as the form itself (requisition_voucher.php uses `create` for both
// the new and the edit path), so anyone who can reach the form can use this.
if (!hasPermission('requisition_vouchers', 'create')) {
    rvi_fail(403, __('no_permission'));
}

/** Convert a value to valid UTF-8 — Excel on Windows commonly writes Windows-1252. */
function rvi_utf8($str) {
    if ($str === null || $str === '') return $str;
    if (mb_check_encoding($str, 'UTF-8')) return $str;
    return mb_convert_encoding($str, 'UTF-8', 'Windows-1252');
}

/** Trim, collapse runs of whitespace, lowercase — for header + name matching. */
function rvi_norm($str) {
    if ($str === null) return '';
    return strtolower(trim(preg_replace('/\s+/u', ' ', (string)$str)));
}

/**
 * Read a number out of a spreadsheet cell. A comma is always a thousands
 * separator, never a decimal point — this app formats numbers the en-GB way
 * throughout, so a sheet exported from it matches.
 */
function rvi_number($val) {
    if ($val === null) return 0.0;
    $s = trim((string)$val);
    if ($s === '') return 0.0;
    $s = preg_replace('/[^0-9\.\-]/', '', str_replace(',', '', $s));
    return $s === '' ? 0.0 : (float)$s;
}

// =====================================================================
// ACTION: template — the whole active product catalogue, one row per
// product, Product ID and Product already filled in. The user types Qty
// against the products they want and leaves every other row alone.
//
// CSV, not XLSX: SimpleXLSX only READS, and the app has no spreadsheet
// writer. Excel opens a CSV natively, and parse() accepts CSV back.
//
// NO SAMPLE ROWS — in a pre-filled sheet they would sit at the top as rows
// that match nothing and get reported as errors on the way back in.
// =====================================================================
if ($action === 'template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="requisition_items_template.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM, so Excel opens UTF-8 correctly
    fputcsv($out, ['Product ID', 'Product', 'Qty', 'Remarks']);

    // Streamed a row at a time — a catalogue of any size stays flat in memory.
    $pstmt = $conn->query("SELECT id, name FROM products WHERE is_active = 1 ORDER BY name");
    while ($p = $pstmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [$p['id'], rvi_utf8($p['name']), '', '']);
    }

    fclose($out);
    exit;
}

// =====================================================================
// ACTION: parse
// =====================================================================
if ($action !== 'parse') {
    rvi_fail(400, 'Unknown action');
}

header('Content-Type: application/json; charset=utf-8');

// The template is the whole catalogue, so the sheet is routinely thousands of
// rows long; it is the matched LINES that cost anything, because each becomes a
// select2-backed row in the form.
define('RVI_MAX_ROWS', 50000);
define('RVI_MAX_LINES', 2000);

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    rvi_fail(400, __('import_no_file'));
}
if ($_FILES['file']['size'] > MAX_FILE_SIZE) {
    rvi_fail(400, __('import_file_too_large'));
}

$ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['csv', 'xlsx'], true)) {
    rvi_fail(400, __('import_bad_file_type'));
}

$tempDir = __DIR__ . '/../temp/';
if (!is_dir($tempDir)) mkdir($tempDir, 0755, true);
$tmpPath = $tempDir . 'rvi_' . bin2hex(random_bytes(8)) . '.' . $ext;
if (!move_uploaded_file($_FILES['file']['tmp_name'], $tmpPath)) {
    rvi_fail(500, __('import_upload_failed'));
}

// ---------------------------------------------------------------------
// Read the sheet into [header[], rows[][]]
// ---------------------------------------------------------------------
$header = [];
$dataRows = [];
$readError = null;

try {
    if ($ext === 'csv') {
        $fh = fopen($tmpPath, 'r');
        if (!$fh) throw new Exception(__('import_unreadable'));
        if (fread($fh, 3) !== "\xEF\xBB\xBF") rewind($fh);
        $head = fgetcsv($fh);
        if (!$head) throw new Exception(__('import_empty_file'));
        $header = array_map(function ($h) { return rvi_norm(rvi_utf8($h)); }, $head);
        while (($row = fgetcsv($fh)) !== false) {
            $dataRows[] = array_map(function ($c) { return rvi_utf8($c); }, $row);
            if (count($dataRows) >= RVI_MAX_ROWS) break;
        }
        fclose($fh);
    } else {
        require_once __DIR__ . '/../includes/SimpleXLSX.php';
        $xlsx = SimpleXLSX::parse($tmpPath);
        if (!$xlsx) throw new Exception(__('import_unreadable') . ' ' . SimpleXLSX::parseError());
        $sheet = $xlsx->rows(); // sheet index 0 — the first sheet only
        if (empty($sheet)) throw new Exception(__('import_empty_file'));
        $header = array_map(function ($h) { return rvi_norm(rvi_utf8($h ?? '')); }, array_shift($sheet));
        foreach ($sheet as $row) {
            $dataRows[] = array_map(function ($c) { return rvi_utf8($c ?? ''); }, $row);
            if (count($dataRows) >= RVI_MAX_ROWS) break;
        }
    }
} catch (Exception $e) {
    $readError = $e->getMessage();
}

@unlink($tmpPath); // never keep the upload around

if ($readError !== null) {
    rvi_fail(400, $readError);
}

// ---------------------------------------------------------------------
// Locate the columns. Header names are matched loosely so a sheet that
// says "Item"/"Quantity" works without being re-typed.
// ---------------------------------------------------------------------
function rvi_col($header, array $aliases) {
    foreach ($aliases as $a) {
        $i = array_search($a, $header, true);
        if ($i !== false) return $i;
    }
    return null;
}

$cIdx = [
    'id'      => rvi_col($header, ['product id', 'product_id', 'productid', 'id', 'code', 'product code']),
    'product' => rvi_col($header, ['product', 'product name', 'name', 'item', 'item name', 'description', 'particulars']),
    'qty'     => rvi_col($header, ['qty', 'quantity', 'requested quantity', 'requested_quantity', 'total qty', 'total']),
    'remarks' => rvi_col($header, ['remarks', 'remark', 'note', 'notes', 'comment']),
];

if ($cIdx['product'] === null && $cIdx['id'] === null) {
    rvi_fail(400, __('import_no_product_column'));
}
if ($cIdx['qty'] === null) {
    rvi_fail(400, __('import_no_qty_column'));
}

// ---------------------------------------------------------------------
// Product lookup. One pass over the active products beats a query per
// sheet row, and lets an ambiguous name be REPORTED rather than silently
// resolved to whichever row came first.
// ---------------------------------------------------------------------
$byId = [];
$byName = []; // normalized name => [id, id, ...]
$pstmt = $conn->query("SELECT id, name, unit FROM products WHERE is_active = 1");
foreach ($pstmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $byId[(int)$p['id']] = $p;
    $byName[rvi_norm($p['name'])][] = (int)$p['id'];
}

$lines = [];         // product_id => line, in first-seen order
$skipped = [];       // rows we could not use, reported to the user
$blankRows = 0;
$untouchedRows = 0;
$mergedRows = 0;     // repeats folded into an existing line

foreach ($dataRows as $n => $row) {
    $sheetRow = $n + 2; // +1 for the header, +1 because humans count from 1

    $get = function ($key) use ($row, $cIdx) {
        $i = $cIdx[$key];
        if ($i === null || !isset($row[$i])) return '';
        return trim((string)$row[$i]);
    };

    $rawId   = $get('id');
    $rawName = $get('product');
    $remark  = $get('remarks');
    $qty     = rvi_number($get('qty'));

    // Wholly blank line in the sheet — not worth reporting.
    if ($rawId === '' && $rawName === '' && $qty <= 0 && $remark === '') {
        $blankRows++;
        continue;
    }

    // UNTOUCHED TEMPLATE ROW — the common case, not an error.
    //
    // The template ships EVERY active product with its id and name filled in,
    // so a row carrying nothing but those is a product the user did not want.
    // Reporting them would bury the real problems under thousands of rows.
    //
    // Deliberately NOT the same rule as "the quantity is missing": if a Remark
    // was typed against this product then a missing quantity IS a mistake, and
    // the row falls through to the report below.
    if ($qty <= 0 && $remark === '') {
        $untouchedRows++;
        continue;
    }

    // ---- resolve the product: an explicit id always wins over the name ----
    $productId = 0;
    if ($rawId !== '' && (int)$rawId > 0) {
        $pid = (int)$rawId;
        if (isset($byId[$pid])) {
            $productId = $pid;
        } else {
            $skipped[] = ['row' => $sheetRow, 'value' => $rawId, 'reason' => __('import_id_not_found')];
            continue;
        }
    } elseif ($rawName !== '') {
        $key = rvi_norm($rawName);
        $hits = $byName[$key] ?? [];
        if (count($hits) === 1) {
            $productId = $hits[0];
        } elseif (count($hits) > 1) {
            // Naming one of them would be a guess — make the user choose.
            $skipped[] = ['row' => $sheetRow, 'value' => $rawName,
                          'reason' => sprintf(__('import_name_ambiguous'), count($hits), implode(', ', $hits))];
            continue;
        } else {
            $skipped[] = ['row' => $sheetRow, 'value' => $rawName, 'reason' => __('import_name_not_found')];
            continue;
        }
    } else {
        $skipped[] = ['row' => $sheetRow, 'value' => '', 'reason' => __('import_no_product')];
        continue;
    }

    if ($qty <= 0) {
        $skipped[] = ['row' => $sheetRow, 'value' => $byId[$productId]['name'], 'reason' => __('import_bad_qty')];
        continue;
    }

    // ---- fold the row into its product's line (user-confirmed) ------------
    // A requisition line is just product + quantity, so two rows for the same
    // product are the same request stated twice: sum them.
    if (!isset($lines[$productId])) {
        $lines[$productId] = [
            'product_id'   => $productId,
            'product_text' => $byId[$productId]['name'],
            'unit'         => $byId[$productId]['unit'],
            'qty'          => 0,
            'remark'       => '',
        ];
    } else {
        $mergedRows++;
    }

    $lines[$productId]['qty'] += $qty;

    // First non-empty remark wins, so a sheet that only fills it on the
    // product's first row still works.
    if ($lines[$productId]['remark'] === '' && $remark !== '') {
        $lines[$productId]['remark'] = $remark;
    }
}

if (count($lines) > RVI_MAX_LINES) {
    rvi_fail(400, sprintf(__('import_too_many_lines'), RVI_MAX_LINES));
}

$rows = array_values($lines);

echo json_encode([
    'success' => true,
    'rows'    => $rows,
    'skipped' => $skipped,
    'stats'   => [
        'sheet_rows'     => count($dataRows),
        'blank_rows'     => $blankRows,
        'untouched_rows' => $untouchedRows,
        // Rows the user actually filled in (including any that errored). The
        // raw sheet row count is meaningless when the template is the whole
        // catalogue.
        'used_rows'      => count($dataRows) - $blankRows - $untouchedRows,
        'merged_rows'    => $mergedRows,
        'lines'          => count($rows),
        'skipped'        => count($skipped),
    ],
]);
