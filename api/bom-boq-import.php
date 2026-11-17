<?php
/**
 * BOM / BOQ line-item import — reads a spreadsheet of products and hands the
 * matched lines BACK TO THE BROWSER as JSON. Serves both modules; `doc` picks
 * which one (bom | boq).
 *
 * IT NEVER WRITES ANYTHING. The rows it returns are fed into the form's own
 * addItem() and saved by the form's own POST handler, so every existing
 * validation (breakdowns required, breakdowns must sum to the line total,
 * over-allocation, permissions) still runs exactly as it does for typed rows.
 * That is the whole point of this design: no second write path to keep in sync.
 *
 * Actions:
 *   template - download a starter sheet with the right columns for `doc`
 *   parse    - accept an uploaded CSV/XLSX, match products, return JSON lines
 *
 * SHEET FORMAT (Rate is BOM-only — boq_details has no rate column)
 *   Product ID | Product | Breakdown | Qty | Rate | Remarks
 *
 * Repeating a product across rows turns those rows into its breakdowns and the
 * line total becomes their sum, which is why the "breakdowns must sum to the
 * total" rule can never be violated by an import. A blank Breakdown yields a single
 * breakdown covering the whole quantity (the column is NOT NULL, so it is
 * labelled with __('bom_import_default_label')).
 */

require_once '../config/database.php';
require_once '../includes/functions.php';

// Which document. Everything below branches off this.
$doc = ($_REQUEST['doc'] ?? '') === 'boq' ? 'boq' : 'bom';
$hasRate = ($doc === 'bom'); // boq_details has no `rate` column

$action = $_REQUEST['action'] ?? '';

/**
 * Fail as JSON. Kept separate from the template branch, which must not emit
 * a JSON content-type header (it sends a file).
 */
function bbi_fail($httpCode, $message) {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

if (!isLoggedIn()) {
    bbi_fail(401, __('unauthorized'));
}

// Reading a sheet into the form is part of creating or editing the document,
// so it needs no permission of its own — it borrows the module's.
if (!hasPermission($doc, 'create') && !hasPermission($doc, 'edit')) {
    bbi_fail(403, __('no_permission'));
}

/**
 * Convert a value to valid UTF-8.
 *
 * Sheets exported from Excel on Windows are commonly Windows-1252, carrying
 * bytes (smart quotes 0x93/0x94, x 0xD7) that are not valid standalone UTF-8.
 * json_encode() returns FALSE on such a string, which reaches the browser as
 * an empty body and the useless message "Unexpected end of JSON input".
 * Same guard as api/products-import.php, and it matters more here because
 * product descriptions are long free text.
 */
function bbi_utf8($str) {
    if ($str === null || $str === '') return $str;
    if (mb_check_encoding($str, 'UTF-8')) return $str;
    return mb_convert_encoding($str, 'UTF-8', 'Windows-1252');
}

/** Trim, collapse runs of whitespace, lowercase — for header + name matching. */
function bbi_norm($str) {
    if ($str === null) return '';
    return strtolower(trim(preg_replace('/\s+/u', ' ', (string)$str)));
}

/**
 * Read a number out of a spreadsheet cell.
 *
 * Strips currency symbols, spaces and THOUSANDS separators, so "1,234.56",
 * "1 234.56" and "TZS 1234.56" all give 1234.56. A comma is always treated as
 * a thousands separator, never a decimal point — this app formats numbers the
 * en-GB way throughout (formatNumber/number_format) and a sheet exported from
 * it will match. A European "1234,56" would therefore read as 123456, so the
 * template spells the expected format out.
 */
function bbi_number($val) {
    if ($val === null) return 0.0;
    $s = trim((string)$val);
    if ($s === '') return 0.0;
    $s = preg_replace('/[^0-9\.\-]/', '', str_replace(',', '', $s));
    return $s === '' ? 0.0 : (float)$s;
}

// =====================================================================
// ACTION: template — the whole active product catalogue, one row per
// product, with Product ID and Product already filled in. The user types
// Qty (and Breakdown / Rate / Remarks) against the products they want and
// leaves every other row alone.
//
// CSV, not XLSX: SimpleXLSX only READS, and the app has no spreadsheet
// writer. Excel opens a CSV natively, and parse() accepts CSV back.
//
// NO SAMPLE ROWS. They used to demonstrate the format with invented product
// names, which in a pre-filled sheet would sit at the top as rows that match
// nothing and get reported as errors on the way back in. The format is
// explained in the modal instead (bom_import_col_help).
//
// Because Product ID is pre-filled, the id — not the name — is what resolves
// each row, so the duplicate-name case that parse() has to refuse as ambiguous
// cannot arise from an unedited template.
// =====================================================================
if ($action === 'template') {
    $cols = ['Product ID', 'Product', 'Breakdown', 'Qty'];
    if ($hasRate) $cols[] = 'Rate';
    $cols[] = 'Remarks';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $doc . '_import_template.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM, so Excel opens UTF-8 correctly
    fputcsv($out, $cols);

    // Streamed a row at a time — a catalogue of any size stays flat in memory.
    // Ordered by name so the sheet can be scrolled/searched the way the
    // product dropdown is read.
    $blank = $hasRate ? ['', '', '', ''] : ['', '', ''];
    $pstmt = $conn->query("SELECT id, name FROM products WHERE is_active = 1 ORDER BY name");
    while ($p = $pstmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, array_merge([$p['id'], bbi_utf8($p['name'])], $blank));
    }

    fclose($out);
    exit;
}

// =====================================================================
// ACTION: parse — the real work.
// =====================================================================
if ($action !== 'parse') {
    bbi_fail(400, 'Unknown action');
}

header('Content-Type: application/json; charset=utf-8');

// Two different limits, because they protect two different things.
//
// BBI_MAX_ROWS caps how much SHEET we will read. The template is now the whole
// active product catalogue (one row per product, most of them left untouched),
// so this has to comfortably clear a large product master — the old 2000 would
// have rejected this app's own template at 3,945 products. Reading rows is
// cheap; it is the matched lines that cost anything.
//
// BBI_MAX_LINES caps what actually reaches the browser. Every matched line
// becomes a form block with a select2 product dropdown, so THIS is the number
// that can hang the page, and it is the one worth refusing loudly.
define('BBI_MAX_ROWS', 50000);
define('BBI_MAX_LINES', 2000);

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    bbi_fail(400, __('import_no_file'));
}
if ($_FILES['file']['size'] > MAX_FILE_SIZE) {
    bbi_fail(400, __('import_file_too_large'));
}

$ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['csv', 'xlsx'], true)) {
    bbi_fail(400, __('import_bad_file_type'));
}

$tempDir = __DIR__ . '/../temp/';
if (!is_dir($tempDir)) mkdir($tempDir, 0755, true);
$tmpPath = $tempDir . 'bbi_' . bin2hex(random_bytes(8)) . '.' . $ext;
if (!move_uploaded_file($_FILES['file']['tmp_name'], $tmpPath)) {
    bbi_fail(500, __('import_upload_failed'));
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
        $header = array_map(function ($h) { return bbi_norm(bbi_utf8($h)); }, $head);
        while (($row = fgetcsv($fh)) !== false) {
            $dataRows[] = array_map(function ($c) { return bbi_utf8($c); }, $row);
            if (count($dataRows) > BBI_MAX_ROWS) break;
        }
        fclose($fh);
    } else {
        require_once __DIR__ . '/../includes/SimpleXLSX.php';
        $xlsx = SimpleXLSX::parse($tmpPath);
        if (!$xlsx) throw new Exception(__('import_unreadable') . ' ' . SimpleXLSX::parseError());
        $sheet = $xlsx->rows();
        if (empty($sheet)) throw new Exception(__('import_empty_file'));
        $header = array_map(function ($h) { return bbi_norm(bbi_utf8($h ?? '')); }, array_shift($sheet));
        foreach ($sheet as $row) {
            $dataRows[] = array_map(function ($c) { return bbi_utf8($c ?? ''); }, $row);
            if (count($dataRows) > BBI_MAX_ROWS) break;
        }
    }
} catch (Exception $e) {
    $readError = $e->getMessage();
}

@unlink($tmpPath); // never keep the upload around

if ($readError !== null) {
    bbi_fail(400, $readError);
}
if (count($dataRows) > BBI_MAX_ROWS) {
    bbi_fail(400, sprintf(__('import_too_many_rows'), BBI_MAX_ROWS));
}

// ---------------------------------------------------------------------
// Locate the columns. Header names are matched loosely so a sheet that
// says "Item"/"Quantity"/"Area" works without being re-typed.
// ---------------------------------------------------------------------
function bbi_col($header, array $aliases) {
    foreach ($aliases as $a) {
        $i = array_search($a, $header, true);
        if ($i !== false) return $i;
    }
    return null;
}

$cIdx = [
    'id'      => bbi_col($header, ['product id', 'product_id', 'productid', 'id', 'code', 'product code']),
    'product' => bbi_col($header, ['product', 'product name', 'name', 'item', 'item name', 'description', 'particulars']),
    'label'   => bbi_col($header, ['label', 'area', 'floor', 'location', 'breakdown', 'breakdown label', 'section']),
    'qty'     => bbi_col($header, ['qty', 'quantity', 'total qty', 'total quantity', 'total']),
    'rate'    => bbi_col($header, ['rate', 'unit rate', 'price', 'unit price']),
    'remarks' => bbi_col($header, ['remarks', 'remark', 'note', 'notes', 'comment']),
];

if ($cIdx['product'] === null && $cIdx['id'] === null) {
    bbi_fail(400, __('import_no_product_column'));
}
if ($cIdx['qty'] === null) {
    bbi_fail(400, __('import_no_qty_column'));
}

// ---------------------------------------------------------------------
// Product lookup. One pass over the active products (~4k rows) beats a
// query per sheet row, and lets an ambiguous name be REPORTED rather than
// silently resolved to whichever row came first.
// ---------------------------------------------------------------------
$byId = [];
$byName = []; // normalized name => [id, id, ...]
$pstmt = $conn->query("SELECT id, name, unit FROM products WHERE is_active = 1");
foreach ($pstmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $byId[(int)$p['id']] = $p;
    $byName[bbi_norm($p['name'])][] = (int)$p['id'];
}

$defaultLabel = __('bom_import_default_label');

$lines = [];    // product_id => line, in first-seen order
$skipped = [];  // rows we could not use, reported to the user
$blankRows = 0;
$untouchedRows = 0;  // template rows the user simply left alone

foreach ($dataRows as $n => $row) {
    $sheetRow = $n + 2; // +1 for the header, +1 because humans count from 1

    $get = function ($key) use ($row, $cIdx) {
        $i = $cIdx[$key];
        if ($i === null || !isset($row[$i])) return '';
        return trim((string)$row[$i]);
    };

    $rawId   = $get('id');
    $rawName = $get('product');
    $qty     = bbi_number($get('qty'));

    // Wholly blank line in the sheet — not worth reporting.
    if ($rawId === '' && $rawName === '' && $qty <= 0) {
        $blankRows++;
        continue;
    }

    // UNTOUCHED TEMPLATE ROW — the common case, not an error.
    //
    // The template ships EVERY active product with its id and name already
    // filled in, so a row carrying nothing but those two is simply a product
    // the user did not want. Reporting them would bury the handful of real
    // problems under thousands of rows and force the user through the
    // "Add anyway" gate on every single import.
    //
    // Deliberately NOT the same rule as "the quantity is missing": if anything
    // at all was typed against this product — a Breakdown, a Rate, a Remark —
    // then a missing quantity IS a mistake, and the row falls through to the
    // import_bad_qty report below where the user will see it.
    if ($qty <= 0
        && $get('label') === ''
        && $get('remarks') === ''
        && (!$hasRate || $get('rate') === '')) {
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
        $key = bbi_norm($rawName);
        $hits = $byName[$key] ?? [];
        if (count($hits) === 1) {
            $productId = $hits[0];
        } elseif (count($hits) > 1) {
            // e.g. the busbar description that exists three times. Naming one
            // of them would be a guess about money, so make the user choose.
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

    // ---- fold the row into its product's line ----
    if (!isset($lines[$productId])) {
        $lines[$productId] = [
            'product_id'   => $productId,
            'product_text' => $byId[$productId]['name'],
            'unit'         => $byId[$productId]['unit'],
            'total'        => 0,
            'rate'         => 0,
            'remark'       => '',
            'breakdowns'   => [],
        ];
    }

    $label = $get('label');
    $lines[$productId]['breakdowns'][] = [
        'label' => ($label === '' ? $defaultLabel : $label),
        'qty'   => $qty,
    ];
    $lines[$productId]['total'] += $qty;

    // Rate and remark: first non-empty value seen for the product wins, so a
    // sheet that only fills them on the product's first row still works.
    if ($hasRate && $lines[$productId]['rate'] <= 0) {
        $r = bbi_number($get('rate'));
        if ($r > 0) $lines[$productId]['rate'] = $r;
    }
    if ($lines[$productId]['remark'] === '') {
        $lines[$productId]['remark'] = $get('remarks');
    }
}

// What reaches the browser is one select2-backed form block per line, so cap
// the LINES rather than the sheet rows (see BBI_MAX_LINES).
if (count($lines) > BBI_MAX_LINES) {
    bbi_fail(400, sprintf(__('import_too_many_lines'), BBI_MAX_LINES));
}

$rows = array_values($lines);
if (!$hasRate) {
    foreach ($rows as &$r) unset($r['rate']);
    unset($r);
}

echo json_encode([
    'success' => true,
    'doc'     => $doc,
    'rows'    => $rows,
    'skipped' => $skipped,
    'stats'   => [
        'sheet_rows'     => count($dataRows),
        'blank_rows'     => $blankRows,
        'untouched_rows' => $untouchedRows,
        // Rows the user actually filled in (including any that errored). This
        // is what the summary quotes — "from 3945 sheet rows" would be a
        // meaningless number now that the template carries the whole catalogue.
        'used_rows'      => count($dataRows) - $blankRows - $untouchedRows,
        'lines'          => count($rows),
        'skipped'        => count($skipped),
    ],
]);
