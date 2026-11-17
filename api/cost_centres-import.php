<?php
/**
 * Cost Centres Import API — hierarchical, unlimited depth.
 *
 * Same 5-action shape as the other importers: upload -> preview -> process
 * (chunked) -> cleanup, plus a template download.
 *
 * SHEET FORMAT
 *   code | name | parent code | description | start date | end date | status | budget | active
 *
 * ------------------------------------------------------------------------
 * WHY THIS IS NOT A COPY OF api/locations-import.php
 *
 * `locations` is UNIQUE on name. `cost_centres` is UNIQUE on CODE, and its
 * NAMES REPEAT HEAVILY — on this tenant "Equipment Container" occurs 78 times
 * and "WING A" 18 times, 568 distinct names across 790 rows. So:
 *
 *   - the CODE is the identity, and
 *   - `parent code` refers to a parent BY CODE. Matching a parent by name
 *     would be ambiguous hundreds of times over.
 *
 * The codes happen to look like paths ("SCHNEIDER/B01/Equipment Container"),
 * but the tree is NOT inferred from them: CRDB BANK is the parent of
 * CRDB MAINT/GROUND FLOOR, so the code path and the real parent chain do not
 * always agree. Only the explicit parent column decides the tree.
 *
 * TWO SCHEMA TRAPS THIS GUARDS
 *
 * 1. `code` collates utf8mb4_unicode_ci, i.e. CASE-INSENSITIVE — MySQL already
 *    considers 'schneider/b01' equal to 'SCHNEIDER/B01'. All matching here is
 *    therefore case-insensitive too; treating a differently-cased code as new
 *    would sail past our own duplicate check and then die on the UNIQUE key.
 *
 * 2. sql_mode does NOT include STRICT_TRANS_TABLES, so an over-long value is
 *    SILENTLY TRUNCATED rather than rejected — and the longest existing code is
 *    already exactly 50 characters, the column ceiling. Lengths are therefore
 *    validated in PHP (code <= 50, name <= 100) so a long code is reported
 *    instead of being quietly chopped into a collision.
 *
 * AN EXISTING COST CENTRE IS NEVER RE-PARENTED (user-confirmed). Details are
 * refreshed; parent_id is never written. Reports aggregate a parent plus all
 * its descendants, so moving a node silently moves other people's totals.
 *
 * IT TOUCHES NO OTHER TABLE — in particular `cost_centre_approvers` is never
 * read or written.
 */

header('Content-Type: application/json; charset=utf-8');

require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Gated on `create` (user-confirmed): everyone who can already add a cost
// centre by hand. No user currently holds can_import on this module.
if (!hasPermission('cost_centres', 'create')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied. You need cost_centres.create permission.']);
    exit;
}

$action  = $_REQUEST['action'] ?? '';
$tempDir = __DIR__ . '/../temp/';
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0755, true);
}

define('CCI_FILE_RE', '/^cc_import_[a-f0-9.]+$/');
define('CCI_MAX_ROWS', 20000);
define('CCI_CODE_MAX', 50);   // cost_centres.code  varchar(50)
define('CCI_NAME_MAX', 100);  // cost_centres.name  varchar(100)

/** Trim, collapse whitespace, lowercase — for header matching AND code matching. */
function cci_norm($str) {
    if ($str === null) return '';
    return strtolower(trim(preg_replace('/\s+/u', ' ', (string)$str)));
}

/** Excel on Windows commonly writes Windows-1252; json_encode() returns FALSE on those bytes. */
function cci_utf8($str) {
    if ($str === null || $str === '') return $str;
    if (mb_check_encoding($str, 'UTF-8')) return $str;
    return mb_convert_encoding($str, 'UTF-8', 'Windows-1252');
}

function cci_ext($filename) {
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

/** Yes/no cell. NULL for blank = "leave the stored value alone". */
function cci_activeValue($value) {
    $v = strtolower(trim((string)$value));
    if ($v === '') return null;
    if (in_array($v, ['yes', '1', 'true', 'active', 'y'], true)) return 1;
    if (in_array($v, ['no', '0', 'false', 'inactive', 'n'], true)) return 0;
    return null;
}

/** status enum. NULL for blank, FALSE when present but unrecognised. */
function cci_status($value) {
    $v = strtolower(trim((string)$value));
    if ($v === '') return null;
    $v = str_replace([' ', '-'], '_', $v); // "on hold" / "on-hold" -> on_hold
    return in_array($v, ['active', 'completed', 'on_hold'], true) ? $v : false;
}

/**
 * Date cell -> 'Y-m-d'. NULL for blank, FALSE when present but unreadable.
 *
 * Accepts Y-m-d, d-m-Y and d/m/Y (the app displays d-m-Y), plus the raw serial
 * numbers an .xlsx carries for real date cells.
 */
function cci_date($value) {
    $v = trim((string)$value);
    if ($v === '') return null;

    // A real date cell in an .xlsx reaches us from SimpleXLSX already rendered
    // as "2026-05-01 00:00:00", not as a serial. Drop the time part so the
    // formats below see a plain date.
    if (preg_match('/^(.+?)[ T]\d{1,2}:\d{2}(:\d{2})?$/', $v, $m)) {
        $v = trim($m[1]);
    }

    // Excel serial day count. 1900-01-01 is serial 1, with the well-known
    // 1900-leap-year bug, so the epoch used here is 1899-12-30.
    if (preg_match('/^\d+(\.\d+)?$/', $v)) {
        $serial = (float)$v;
        if ($serial < 1 || $serial > 2958465) return false; // out of Excel's range
        $ts = ($serial - 25569) * 86400;
        return gmdate('Y-m-d', (int)$ts);
    }

    foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d', 'd.m.Y'] as $fmt) {
        $d = DateTime::createFromFormat('!' . $fmt, $v);
        if ($d && $d->format($fmt) === $v) return $d->format('Y-m-d');
    }
    return false;
}

/** Money cell. NULL for blank, FALSE when present but not a number. */
function cci_number($value) {
    $v = trim((string)$value);
    if ($v === '') return null;
    $clean = str_replace([',', ' '], '', $v);
    if (!preg_match('/^-?\d+(\.\d+)?$/', $clean)) return false;
    return (float)$clean;
}

function cci_parseCSV($filePath) {
    $handle = fopen($filePath, 'r');
    if (!$handle) return ['error' => 'Cannot open file'];

    if (fread($handle, 3) !== "\xEF\xBB\xBF") rewind($handle);

    $head = fgetcsv($handle);
    if (!$head) {
        fclose($handle);
        return ['error' => 'Empty file or invalid CSV'];
    }
    $header = array_map(function ($h) { return cci_norm(cci_utf8($h)); }, $head);

    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        while (count($row) < count($header)) $row[] = '';
        $rows[] = array_combine($header, array_map(function ($c) {
            return cci_utf8($c);
        }, array_slice($row, 0, count($header))));
        if (count($rows) >= CCI_MAX_ROWS) break;
    }
    fclose($handle);

    return ['header' => $header, 'rows' => $rows, 'totalRows' => count($rows)];
}

function cci_parseExcel($filePath) {
    require_once __DIR__ . '/../includes/SimpleXLSX.php';

    $xlsx = SimpleXLSX::parse($filePath);
    if (!$xlsx) return ['error' => 'Cannot parse Excel file: ' . SimpleXLSX::parseError()];

    $sheet = $xlsx->rows(); // sheet index 0 — the first sheet only
    if (empty($sheet)) return ['error' => 'Empty Excel file'];

    $header = array_map(function ($h) { return cci_norm(cci_utf8($h ?? '')); }, $sheet[0]);

    $rows = [];
    for ($i = 1; $i < count($sheet); $i++) {
        $row = $sheet[$i];
        while (count($row) < count($header)) $row[] = '';
        $rows[] = array_combine($header, array_map(function ($c) {
            return cci_utf8($c ?? '');
        }, array_slice($row, 0, count($header))));
        if (count($rows) >= CCI_MAX_ROWS) break;
    }

    return ['header' => $header, 'rows' => $rows, 'totalRows' => count($rows)];
}

/**
 * Map canonical field => the actual header used in this sheet, so a sheet that
 * says "parent_code" or plain "parent" works without being re-typed.
 */
function cci_columnMap($header) {
    $aliases = [
        'code'        => ['code', 'cost centre code', 'cost center code', 'cc code'],
        'name'        => ['name', 'cost centre name', 'cost center name', 'title'],
        'parent'      => ['parent code', 'parent_code', 'parentcode', 'parent', 'parent cost centre'],
        'description' => ['description', 'desc', 'remarks'],
        'start_date'  => ['start date', 'start_date', 'startdate', 'start'],
        'end_date'    => ['end date', 'end_date', 'enddate', 'end'],
        'status'      => ['status'],
        'budget'      => ['budget', 'budget amount', 'budget_amount', 'budgetamount'],
        'active'      => ['active', 'is active', 'is_active'],
    ];
    $map = [];
    foreach ($aliases as $canonical => $names) {
        foreach ($names as $n) {
            if (in_array($n, $header, true)) { $map[$canonical] = $n; break; }
        }
    }
    return $map;
}

/** Every existing cost centre, keyed by LOWERCASED code (the collation is ci). */
function cci_loadExisting($conn) {
    $map = [];
    $stmt = $conn->query("SELECT id, parent_id, code, name, description, start_date, end_date,
                                 status, budget_amount, is_active
                          FROM cost_centres");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $map[cci_norm($r['code'])] = $r;
    }
    return $map;
}

/**
 * Resolve the whole sheet without writing anything.
 *
 * Returns plan[] already in safe insert order (parents before children),
 * errors[], and the new/update counts. Both `preview` and `process` call this,
 * so what the user is shown and what runs can never drift apart.
 */
function cci_resolve($parsed, $conn) {
    $header = $parsed['header'];
    $col    = cci_columnMap($header);

    if (!isset($col['code'])) {
        return ['fatal' => 'Missing required column: code', 'foundColumns' => $header];
    }

    $existing = cci_loadExisting($conn);

    $errors   = [];
    $items    = [];  // lowercased code => row data
    $rowNumOf = [];

    $get = function ($row, $key) use ($col) {
        if (!isset($col[$key])) return '';
        return trim((string)($row[$col[$key]] ?? ''));
    };

    // ---- pass 1: read and validate each row in isolation -------------------
    foreach ($parsed['rows'] as $i => $row) {
        $rowNum = $i + 2; // +1 header, +1 humans count from 1

        $code   = $get($row, 'code');
        $name   = $get($row, 'name');
        $parent = $get($row, 'parent');
        $desc   = $get($row, 'description');

        // Wholly blank line — the trailing rows Excel likes to add.
        if ($code === '' && $name === '' && $parent === '' && $desc === ''
            && $get($row, 'status') === '' && $get($row, 'budget') === ''
            && $get($row, 'start_date') === '' && $get($row, 'end_date') === '') {
            continue;
        }

        if ($code === '') {
            $errors[] = ['row' => $rowNum, 'value' => $name, 'error' => 'Code is required'];
            continue;
        }
        if ($name === '') {
            $errors[] = ['row' => $rowNum, 'value' => $code, 'error' => 'Name is required'];
            continue;
        }

        // sql_mode is not strict, so an over-long value would be silently
        // truncated by MySQL — and truncation can turn a distinct code into a
        // collision. Refuse instead.
        if (mb_strlen($code) > CCI_CODE_MAX) {
            $errors[] = ['row' => $rowNum, 'value' => $code,
                         'error' => 'Code is longer than ' . CCI_CODE_MAX . ' characters (' . mb_strlen($code) . ')'];
            continue;
        }
        if (mb_strlen($name) > CCI_NAME_MAX) {
            $errors[] = ['row' => $rowNum, 'value' => $code,
                         'error' => 'Name is longer than ' . CCI_NAME_MAX . ' characters (' . mb_strlen($name) . ')'];
            continue;
        }

        $key = cci_norm($code);

        if (isset($items[$key])) {
            $errors[] = ['row' => $rowNum, 'value' => $code,
                         'error' => 'Duplicate code in this sheet (already on row ' . $rowNumOf[$key] . ')'];
            continue;
        }
        if ($parent !== '' && cci_norm($parent) === $key) {
            $errors[] = ['row' => $rowNum, 'value' => $code, 'error' => 'A cost centre cannot be its own parent'];
            continue;
        }

        $status = cci_status($get($row, 'status'));
        if ($status === false) {
            $errors[] = ['row' => $rowNum, 'value' => $code,
                         'error' => 'Status must be active, completed or on_hold (got "' . $get($row, 'status') . '")'];
            continue;
        }

        $startDate = cci_date($get($row, 'start_date'));
        if ($startDate === false) {
            $errors[] = ['row' => $rowNum, 'value' => $code,
                         'error' => 'Start date is not a valid date (got "' . $get($row, 'start_date') . '")'];
            continue;
        }
        $endDate = cci_date($get($row, 'end_date'));
        if ($endDate === false) {
            $errors[] = ['row' => $rowNum, 'value' => $code,
                         'error' => 'End date is not a valid date (got "' . $get($row, 'end_date') . '")'];
            continue;
        }
        if ($startDate !== null && $endDate !== null && $endDate < $startDate) {
            $errors[] = ['row' => $rowNum, 'value' => $code, 'error' => 'End date is before the start date'];
            continue;
        }

        $budget = cci_number($get($row, 'budget'));
        if ($budget === false) {
            $errors[] = ['row' => $rowNum, 'value' => $code,
                         'error' => 'Budget is not a number (got "' . $get($row, 'budget') . '")'];
            continue;
        }

        $items[$key] = [
            'row'         => $rowNum,
            'code'        => $code,
            'name'        => $name,
            'parentCode'  => $parent,
            'parentKey'   => $parent === '' ? null : cci_norm($parent),
            'description' => $desc,
            'start_date'  => $startDate,
            'end_date'    => $endDate,
            'status'      => $status,
            'budget'      => $budget,
            'active'      => cci_activeValue($get($row, 'active')),
            'existing'    => $existing[$key] ?? null,
        ];
        $rowNumOf[$key] = $rowNum;
    }

    // ---- pass 2: resolve parents ------------------------------------------
    // Valid if the parent already exists or is defined by another row of this
    // sheet. Unknown parents are reported, NEVER auto-created — auto-creating
    // turns one typo into a junk top-level cost centre.
    foreach ($items as $key => &$it) {
        if ($it['parentKey'] === null) continue;

        if (isset($existing[$it['parentKey']])) {
            $it['parentFrom'] = 'db';
        } elseif (isset($items[$it['parentKey']])) {
            $it['parentFrom'] = 'sheet';
        } else {
            $errors[] = ['row' => $it['row'], 'value' => $it['code'],
                         'error' => 'Parent code not found: "' . $it['parentCode'] . '"'];
            $it['dead'] = true;
        }
    }
    unset($it);

    // Anything beneath an unusable row cannot be placed either. Walk the
    // descendants so the user is told about all of them at once rather than
    // discovering them one re-run at a time.
    $changed = true;
    while ($changed) {
        $changed = false;
        foreach ($items as $key => &$it) {
            if (!empty($it['dead']) || $it['parentKey'] === null) continue;
            if (!empty($items[$it['parentKey']]['dead'])) {
                $errors[] = ['row' => $it['row'], 'value' => $it['code'],
                             'error' => 'Parent row "' . $it['parentCode'] . '" could not be imported'];
                $it['dead'] = true;
                $changed = true;
            }
        }
        unset($it);
    }

    // ---- pass 3: topological order ----------------------------------------
    // THE ORDER MUST DEPEND ON THE SHEET ALONE, never on current DB contents.
    // `process` re-derives this plan on every chunk and slices it by offset, so
    // an order that shifted as rows were created would make chunk N walk
    // different entries than chunk N-1 — rows silently skipped or visited
    // twice. A row is ready when its parent is not defined by this sheet
    // (so it can only have come from the DB, which pass 2 proved) or when the
    // sheet row defining it has already been placed.
    $plan    = [];
    $placed  = [];
    $pending = [];
    foreach ($items as $key => $it) {
        if (empty($it['dead'])) $pending[$key] = $it;
    }

    $progress = true;
    while ($progress && !empty($pending)) {
        $progress = false;
        foreach ($pending as $key => $it) {
            $ready = $it['parentKey'] === null
                  || !isset($items[$it['parentKey']])
                  || isset($placed[$it['parentKey']]);
            if (!$ready) continue;

            $plan[] = $it;
            $placed[$key] = true;
            unset($pending[$key]);
            $progress = true;
        }
    }

    // Every survivor points at another survivor — a cycle.
    foreach ($pending as $it) {
        $errors[] = ['row' => $it['row'], 'value' => $it['code'],
                     'error' => 'Circular parent reference involving "' . $it['parentCode'] . '"'];
    }

    $newCount = 0;
    $updCount = 0;
    foreach ($plan as $p) {
        if ($p['existing']) $updCount++; else $newCount++;
    }

    usort($errors, function ($a, $b) { return $a['row'] <=> $b['row']; });

    return ['plan' => $plan, 'errors' => $errors, 'newCount' => $newCount, 'updCount' => $updCount];
}

function cci_loadFile($tempDir, $fileId) {
    if (empty($fileId) || !preg_match(CCI_FILE_RE, $fileId)) {
        throw new Exception('Invalid file ID');
    }
    $csv  = $tempDir . $fileId . '.csv';
    $xlsx = $tempDir . $fileId . '.xlsx';

    if (file_exists($csv))  return cci_parseCSV($csv);
    if (file_exists($xlsx)) return cci_parseExcel($xlsx);
    throw new Exception('File not found or expired');
}

try {
    switch ($action) {

        // =============================================================
        case 'upload':
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                $uploadErrors = [
                    UPLOAD_ERR_INI_SIZE   => 'File exceeds server limit',
                    UPLOAD_ERR_FORM_SIZE  => 'File exceeds form limit',
                    UPLOAD_ERR_PARTIAL    => 'File partially uploaded',
                    UPLOAD_ERR_NO_FILE    => 'No file uploaded',
                    UPLOAD_ERR_NO_TMP_DIR => 'Missing temp folder',
                    UPLOAD_ERR_CANT_WRITE => 'Failed to write file',
                    UPLOAD_ERR_EXTENSION  => 'Upload blocked by extension',
                ];
                $code = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
                throw new Exception($uploadErrors[$code] ?? 'Upload failed');
            }

            $file = $_FILES['file'];
            $ext  = cci_ext($file['name']);
            if (!in_array($ext, ['csv', 'xlsx'], true)) {
                throw new Exception('Invalid file type. Only CSV and XLSX allowed.');
            }
            if ($file['size'] > MAX_FILE_SIZE) {
                throw new Exception('File is too large');
            }

            $fileId   = uniqid('cc_import_', true);
            $tempPath = $tempDir . $fileId . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], $tempPath)) {
                throw new Exception('Failed to save uploaded file');
            }

            file_put_contents($tempDir . $fileId . '.json', json_encode([
                'originalName' => $file['name'],
                'extension'    => $ext,
                'uploadedAt'   => date('c'),
                'userId'       => getUserId(),
            ]));

            echo json_encode(['success' => true, 'fileId' => $fileId, 'fileName' => $file['name']]);
            break;

        // =============================================================
        case 'preview':
            $parsed = cci_loadFile($tempDir, clean($_REQUEST['fileId'] ?? ''));
            if (isset($parsed['error'])) throw new Exception($parsed['error']);

            $res = cci_resolve($parsed, $conn);
            if (isset($res['fatal'])) {
                echo json_encode(['success' => false, 'error' => $res['fatal'],
                                  'foundColumns' => $res['foundColumns']]);
                break;
            }

            // Preview follows the PLAN, i.e. the real insert order.
            $previewRows = [];
            foreach (array_slice($res['plan'], 0, 100) as $p) {
                $previewRows[] = [
                    'row'        => $p['row'],
                    'code'       => $p['code'],
                    'name'       => $p['name'],
                    'parent'     => $p['parentCode'],
                    'status'     => $p['status'] ?? '',
                    'action'     => $p['existing'] ? 'update' : 'new',
                    'parentFrom' => $p['parentKey'] === null ? 'root' : ($p['parentFrom'] ?? ''),
                ];
            }

            echo json_encode([
                'success' => true,
                'summary' => [
                    'totalRows'      => $parsed['totalRows'],
                    'newCostCentres' => $res['newCount'],
                    'updCostCentres' => $res['updCount'],
                    'errors'         => count($res['errors']),
                ],
                'errors'      => array_slice($res['errors'], 0, 50),
                'previewRows' => $previewRows,
                'planCount'   => count($res['plan']),
                'columns'     => $parsed['header'],
            ]);
            break;

        // =============================================================
        case 'process':
            $fileId = clean($_REQUEST['fileId'] ?? '');
            $offset = max(0, intval($_REQUEST['offset'] ?? 0));
            $limit  = max(1, intval($_REQUEST['limit'] ?? 100));

            $parsed = cci_loadFile($tempDir, $fileId);
            if (isset($parsed['error'])) throw new Exception($parsed['error']);

            $res = cci_resolve($parsed, $conn);
            if (isset($res['fatal'])) throw new Exception($res['fatal']);

            $plan  = $res['plan'];
            $chunk = array_slice($plan, $offset, $limit);

            $created = 0;
            $updated = 0;
            $errors  = [];

            $conn->beginTransaction();
            try {
                foreach ($chunk as $p) {
                    // Re-read rather than trusting the plan's snapshot: an
                    // earlier chunk may have created this row's parent.
                    // LOWER(TRIM(..)) mirrors cci_norm and the ci collation.
                    $find = $conn->prepare("SELECT id, name, description, start_date, end_date,
                                                   status, budget_amount, is_active
                                            FROM cost_centres WHERE LOWER(TRIM(code)) = ? LIMIT 1");
                    $find->execute([cci_norm($p['code'])]);
                    $current = $find->fetch(PDO::FETCH_ASSOC);

                    $parentId = null;
                    if ($p['parentKey'] !== null) {
                        $pf = $conn->prepare("SELECT id FROM cost_centres WHERE LOWER(TRIM(code)) = ? LIMIT 1");
                        $pf->execute([$p['parentKey']]);
                        $parentId = $pf->fetchColumn();
                        if ($parentId === false) {
                            // Unreachable if the plan is correct — refuse rather
                            // than quietly creating an orphan root.
                            $errors[] = ['row' => $p['row'], 'error' => 'Parent code not found: ' . $p['parentCode']];
                            continue;
                        }
                        $parentId = (int)$parentId;
                    }

                    if ($current) {
                        // UPDATE — details only. parent_id is deliberately absent
                        // from this statement. A blank cell keeps the stored value.
                        $stmt = $conn->prepare("
                            UPDATE cost_centres SET
                                name          = ?,
                                description   = ?,
                                start_date    = ?,
                                end_date      = ?,
                                status        = ?,
                                budget_amount = ?,
                                is_active     = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $p['name'],
                            $p['description'] !== ''  ? $p['description'] : $current['description'],
                            $p['start_date']  !== null ? $p['start_date']  : $current['start_date'],
                            $p['end_date']    !== null ? $p['end_date']    : $current['end_date'],
                            $p['status']      !== null ? $p['status']      : $current['status'],
                            $p['budget']      !== null ? $p['budget']      : $current['budget_amount'],
                            $p['active']      !== null ? $p['active']      : $current['is_active'],
                            (int)$current['id'],
                        ]);
                        logAudit('cost_centres', 'import_update', (int)$current['id']);
                        $updated++;
                    } else {
                        $stmt = $conn->prepare("
                            INSERT INTO cost_centres
                                (parent_id, code, name, description, start_date, end_date, status, budget_amount, is_active)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $parentId,
                            $p['code'],
                            $p['name'],
                            $p['description'] !== '' ? $p['description'] : null,
                            $p['start_date'],
                            $p['end_date'],
                            $p['status'] !== null ? $p['status'] : 'active',
                            $p['budget'] !== null ? $p['budget'] : 0,
                            $p['active'] !== null ? $p['active'] : 1,
                        ]);
                        logAudit('cost_centres', 'import_create', (int)$conn->lastInsertId());
                        $created++;
                    }
                }

                $conn->commit();
            } catch (Exception $e) {
                $conn->rollBack();
                throw $e;
            }

            $processed = $offset + count($chunk);
            echo json_encode([
                'success'   => true,
                'created'   => $created,
                'updated'   => $updated,
                'errors'    => $errors,
                'processed' => $processed,
                'total'     => count($plan),
                'hasMore'   => $processed < count($plan),
            ]);
            break;

        // =============================================================
        case 'cleanup':
            $fileId = clean($_REQUEST['fileId'] ?? '');
            if (!empty($fileId) && preg_match(CCI_FILE_RE, $fileId)) {
                @unlink($tempDir . $fileId . '.csv');
                @unlink($tempDir . $fileId . '.xlsx');
                @unlink($tempDir . $fileId . '.json');
            }
            echo json_encode(['success' => true]);
            break;

        // =============================================================
        case 'template':
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="cost_centres_import_template.csv"');

            echo "\xEF\xBB\xBF"; // so Excel opens UTF-8 correctly
            echo "code,name,parent code,description,start date,end date,status,budget,active\n";
            echo "\"SITE A\",\"Site A\",,\"Main project site\",,,\"active\",,\"Yes\"\n";
            echo "\"SITE A/BLOCK 1\",\"Block 1\",\"SITE A\",,,,\"active\",,\"Yes\"\n";
            echo "\"SITE A/BLOCK 1/FLOOR 1\",\"Floor 1\",\"SITE A/BLOCK 1\",,\"01-01-2026\",\"31-12-2026\",\"active\",\"5000000\",\"Yes\"\n";
            echo "\"SITE A/BLOCK 1/FLOOR 1/WING A\",\"Wing A\",\"SITE A/BLOCK 1/FLOOR 1\",,,,\"active\",,\"Yes\"\n";
            echo "\"SITE A/BLOCK 2\",\"Block 2\",\"SITE A\",,,,\"on_hold\",,\"Yes\"\n";
            exit;

        // =============================================================
        default:
            throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
