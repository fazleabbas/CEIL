<?php
/**
 * Locations Import API — hierarchical, unlimited depth.
 *
 * Same 5-action shape as the other importers (categories / products / suppliers
 * / opening stocks): upload -> preview -> process (chunked) -> cleanup, plus a
 * template download.
 *
 * SHEET FORMAT
 *   Name | Parent | Type | Address | Phone | Active
 *
 * `Parent` names another location — either one that already exists, or one
 * defined on ANOTHER ROW OF THE SAME SHEET. Depth is unlimited; a
 * great-grandchild can sit on row 2 with its root on row 400.
 *
 * ------------------------------------------------------------------------
 * THREE THINGS THAT DRIVE THE WHOLE DESIGN
 *
 * 1. `locations.name` is UNIQUE ACROSS THE WHOLE TABLE (unique_location_name),
 *    not per parent. So the name alone identifies a location, and two parents
 *    can never both own a child called "Store". This is why the existing data
 *    names locations by their path ("M3/Main House Store/Entrance").
 *
 * 2. AN EXISTING LOCATION IS NEVER RE-PARENTED (user-confirmed). Details may be
 *    refreshed, `parent_id` is never written. Reports aggregate a parent plus
 *    all its descendants (getDescendantLocationIds), so moving a node silently
 *    moves other people's report totals. A pleasant side effect: because no
 *    existing node can be re-parented, a cycle can only ever be formed WITHIN
 *    the sheet, which is checked below.
 *
 * 3. NOTHING IS WRITTEN UNTIL THE WHOLE SHEET RESOLVES. Rows are topologically
 *    sorted so every parent is inserted before its children, which is what
 *    makes sheet order irrelevant. An unknown parent is REPORTED, never
 *    auto-created as a root — auto-creating turns one typo into a junk
 *    top-level location that then has to be found and deleted by hand.
 *
 * IT NEVER TOUCHES ANY OTHER TABLE. Only `locations` rows are inserted or
 * updated, each via logAudit() as import_create / import_update.
 */

header('Content-Type: application/json; charset=utf-8');

require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if (!hasPermission('locations', 'import')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied. You need locations.import permission.']);
    exit;
}

$action  = $_REQUEST['action'] ?? '';
$tempDir = __DIR__ . '/../temp/';
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0755, true);
}

// Everything here is prefixed li_ so this file can never collide with the
// identically-named helpers in the other *-import.php endpoints.
define('LI_FILE_RE', '/^loc_import_[a-f0-9.]+$/');
define('LI_MAX_ROWS', 20000);

/** Trim, collapse runs of whitespace, lowercase — for header and name matching. */
function li_norm($str) {
    if ($str === null) return '';
    return strtolower(trim(preg_replace('/\s+/u', ' ', (string)$str)));
}

/**
 * Convert a value to valid UTF-8. Sheets saved by Excel on Windows are commonly
 * Windows-1252; json_encode() returns FALSE on such bytes, which reaches the
 * browser as an empty body and the useless "Unexpected end of JSON input".
 */
function li_utf8($str) {
    if ($str === null || $str === '') return $str;
    if (mb_check_encoding($str, 'UTF-8')) return $str;
    return mb_convert_encoding($str, 'UTF-8', 'Windows-1252');
}

/** Same enum handling as api/locations-sync.php: site | warehouse | others. */
function li_isValidType($value) {
    return in_array(strtolower(trim((string)$value)), ['site', 'warehouse', 'others'], true);
}

function li_ext($filename) {
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

/**
 * Yes/no cell. Returns 1, 0, or NULL for blank.
 *
 * NULL matters: blank means "leave this alone" on an existing location, so a
 * sparse sheet can never silently deactivate somewhere. New rows treat NULL as
 * active.
 */
function li_activeValue($value) {
    $v = strtolower(trim((string)$value));
    if ($v === '') return null;
    if (in_array($v, ['yes', '1', 'true', 'active', 'y'], true)) return 1;
    if (in_array($v, ['no', '0', 'false', 'inactive', 'n'], true)) return 0;
    return null;
}

function li_parseCSV($filePath) {
    $handle = fopen($filePath, 'r');
    if (!$handle) return ['error' => 'Cannot open file'];

    if (fread($handle, 3) !== "\xEF\xBB\xBF") rewind($handle);

    $head = fgetcsv($handle);
    if (!$head) {
        fclose($handle);
        return ['error' => 'Empty file or invalid CSV'];
    }
    $header = array_map(function ($h) { return li_norm(li_utf8($h)); }, $head);

    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        // Pad/trim so a short trailing row still lines up with the header.
        while (count($row) < count($header)) $row[] = '';
        $rows[] = array_combine($header, array_map(function ($c) {
            return li_utf8($c);
        }, array_slice($row, 0, count($header))));
        if (count($rows) >= LI_MAX_ROWS) break;
    }
    fclose($handle);

    return ['header' => $header, 'rows' => $rows, 'totalRows' => count($rows)];
}

function li_parseExcel($filePath) {
    require_once __DIR__ . '/../includes/SimpleXLSX.php';

    $xlsx = SimpleXLSX::parse($filePath);
    if (!$xlsx) return ['error' => 'Cannot parse Excel file: ' . SimpleXLSX::parseError()];

    $sheet = $xlsx->rows(); // sheet index 0 — the first sheet only
    if (empty($sheet)) return ['error' => 'Empty Excel file'];

    $header = array_map(function ($h) { return li_norm(li_utf8($h ?? '')); }, $sheet[0]);

    $rows = [];
    for ($i = 1; $i < count($sheet); $i++) {
        $row = $sheet[$i];
        while (count($row) < count($header)) $row[] = '';
        $rows[] = array_combine($header, array_map(function ($c) {
            return li_utf8($c ?? '');
        }, array_slice($row, 0, count($header))));
        if (count($rows) >= LI_MAX_ROWS) break;
    }

    return ['header' => $header, 'rows' => $rows, 'totalRows' => count($rows)];
}

/** Every existing location, keyed by normalized name. Name is UNIQUE, so this is 1:1. */
function li_loadExisting($conn) {
    $map = [];
    $stmt = $conn->query("SELECT id, parent_id, name, address, phone, location_type, is_active FROM locations");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $map[li_norm($r['name'])] = $r;
    }
    return $map;
}

/**
 * THE CORE. Resolve the entire sheet without writing anything.
 *
 * Returns:
 *   plan[]   — one entry per usable row, ALREADY IN SAFE INSERT ORDER
 *              (every parent precedes its children)
 *   errors[] — rows that cannot be used, with the reason
 *   counts   — new / update totals for the preview
 *
 * Both `preview` and `process` call this, so what the user is shown and what
 * actually runs can never drift apart.
 */
function li_resolve($parsed, $conn) {
    $header = $parsed['header'];
    if (!in_array('name', $header, true)) {
        return ['fatal' => 'Missing required column: name', 'foundColumns' => $header];
    }

    $existing = li_loadExisting($conn);

    $errors  = [];
    $items   = [];   // normalized name => row data
    $rowNumOf = [];  // normalized name => sheet row number, for error messages

    // ---- pass 1: read every row, catch anything self-evidently wrong -------
    foreach ($parsed['rows'] as $i => $row) {
        $rowNum = $i + 2; // +1 header, +1 because humans count from 1

        $name       = trim((string)($row['name'] ?? ''));
        $parentName = trim((string)($row['parent'] ?? ''));
        $type       = trim((string)($row['type'] ?? ''));
        $address    = trim((string)($row['address'] ?? ''));
        $phone      = trim((string)($row['phone'] ?? ''));
        $active     = li_activeValue($row['active'] ?? '');

        // Wholly blank line — the trailing rows Excel loves to add.
        if ($name === '' && $parentName === '' && $type === '' && $address === '' && $phone === '') {
            continue;
        }

        if ($name === '') {
            $errors[] = ['row' => $rowNum, 'value' => '', 'error' => 'Name is required'];
            continue;
        }

        $key = li_norm($name);

        if (isset($items[$key])) {
            $errors[] = ['row' => $rowNum, 'value' => $name,
                         'error' => 'Duplicate name in this sheet (already on row ' . $rowNumOf[$key] . ')'];
            continue;
        }

        if ($parentName !== '' && li_norm($parentName) === $key) {
            $errors[] = ['row' => $rowNum, 'value' => $name, 'error' => 'A location cannot be its own parent'];
            continue;
        }

        // An unrecognised type is reported rather than silently stored as
        // "not set" — a typo like "store" should say so.
        if ($type !== '' && !li_isValidType($type)) {
            $errors[] = ['row' => $rowNum, 'value' => $name,
                         'error' => 'Type must be site, warehouse or others (got "' . $type . '")'];
            continue;
        }

        $items[$key] = [
            'row'        => $rowNum,
            'name'       => $name,
            'parentName' => $parentName,
            'parentKey'  => $parentName === '' ? null : li_norm($parentName),
            'type'       => $type === '' ? null : strtolower($type),
            'address'    => $address,
            'phone'      => $phone,
            'active'     => $active,
            'existing'   => $existing[$key] ?? null,
        ];
        $rowNumOf[$key] = $rowNum;
    }

    // ---- pass 2: resolve parents ------------------------------------------
    // A parent is valid if it already exists in the DB or is defined by another
    // row of this sheet. Unknown parents are reported, NEVER auto-created.
    foreach ($items as $key => &$it) {
        if ($it['parentKey'] === null) continue;

        if (isset($existing[$it['parentKey']])) {
            $it['parentFrom'] = 'db';
        } elseif (isset($items[$it['parentKey']])) {
            $it['parentFrom'] = 'sheet';
        } else {
            $errors[] = ['row' => $it['row'], 'value' => $it['name'],
                         'error' => 'Parent not found: "' . $it['parentName'] . '"'];
            $it['dead'] = true;
        }
    }
    unset($it);

    // A row whose parent is unusable cannot be placed, and neither can anything
    // beneath it — walk the descendants so the user is told about all of them
    // rather than discovering them one re-run at a time.
    $changed = true;
    while ($changed) {
        $changed = false;
        foreach ($items as $key => &$it) {
            if (!empty($it['dead']) || $it['parentKey'] === null) continue;
            if (!empty($items[$it['parentKey']]['dead'])) {
                $errors[] = ['row' => $it['row'], 'value' => $it['name'],
                             'error' => 'Parent row "' . $it['parentName'] . '" could not be imported'];
                $it['dead'] = true;
                $changed = true;
            }
        }
        unset($it);
    }

    // ---- pass 3: topological order ----------------------------------------
    // Repeatedly take every row whose parent is already available, until
    // nothing moves. Whatever is left over is a cycle.
    //
    // THE ORDER MUST DEPEND ON THE SHEET ALONE — never on what is currently in
    // the database. `process` re-derives this plan on every chunk and slices it
    // by offset, so if the order shifted as rows got created, chunk N would
    // walk different entries than chunk N-1 and rows would be skipped or
    // double-visited. (It does exactly that if readiness is tested with
    // isset($existing[...]): creating a root in chunk 1 promotes its children
    // to the front of chunk 2's plan.)
    //
    // So a row is ready when its parent is NOT defined by this sheet — meaning
    // the parent can only have come from the database, which pass 2 already
    // proved — or when the sheet row that defines the parent has been placed.
    $plan    = [];
    $placed  = [];  // normalized names of rows already emitted into the plan
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

    // Anything still pending is in a cycle (A -> B -> A) — every member points
    // at another member, so none of them can ever be placed.
    foreach ($pending as $it) {
        $errors[] = ['row' => $it['row'], 'value' => $it['name'],
                     'error' => 'Circular parent reference involving "' . $it['parentName'] . '"'];
    }

    $newCount = 0;
    $updCount = 0;
    foreach ($plan as $p) {
        if ($p['existing']) $updCount++; else $newCount++;
    }

    // Errors read most naturally in sheet order.
    usort($errors, function ($a, $b) { return $a['row'] <=> $b['row']; });

    return [
        'plan'      => $plan,
        'errors'    => $errors,
        'newCount'  => $newCount,
        'updCount'  => $updCount,
    ];
}

/** Load a previously uploaded sheet by its id. */
function li_loadFile($tempDir, $fileId) {
    if (empty($fileId) || !preg_match(LI_FILE_RE, $fileId)) {
        throw new Exception('Invalid file ID');
    }
    $csv  = $tempDir . $fileId . '.csv';
    $xlsx = $tempDir . $fileId . '.xlsx';

    if (file_exists($csv))  return li_parseCSV($csv);
    if (file_exists($xlsx)) return li_parseExcel($xlsx);
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
            $ext  = li_ext($file['name']);
            if (!in_array($ext, ['csv', 'xlsx'], true)) {
                throw new Exception('Invalid file type. Only CSV and XLSX allowed.');
            }
            if ($file['size'] > MAX_FILE_SIZE) {
                throw new Exception('File is too large');
            }

            $fileId   = uniqid('loc_import_', true);
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
            $parsed = li_loadFile($tempDir, clean($_REQUEST['fileId'] ?? ''));
            if (isset($parsed['error'])) throw new Exception($parsed['error']);

            $res = li_resolve($parsed, $conn);
            if (isset($res['fatal'])) {
                echo json_encode(['success' => false, 'error' => $res['fatal'],
                                  'foundColumns' => $res['foundColumns']]);
                break;
            }

            // Preview rows follow the PLAN, i.e. the real insert order, so what
            // the user reads is exactly what will run.
            $previewRows = [];
            foreach (array_slice($res['plan'], 0, 100) as $p) {
                $previewRows[] = [
                    'row'        => $p['row'],
                    'name'       => $p['name'],
                    'parent'     => $p['parentName'],
                    'type'       => $p['type'] ?? '',
                    'status'     => $p['existing'] ? 'update' : 'new',
                    'parentFrom' => $p['parentKey'] === null ? 'root' : ($p['parentFrom'] ?? ''),
                ];
            }

            echo json_encode([
                'success' => true,
                'summary' => [
                    'totalRows'     => $parsed['totalRows'],
                    'newLocations'  => $res['newCount'],
                    'updLocations'  => $res['updCount'],
                    'errors'        => count($res['errors']),
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

            $parsed = li_loadFile($tempDir, $fileId);
            if (isset($parsed['error'])) throw new Exception($parsed['error']);

            $res = li_resolve($parsed, $conn);
            if (isset($res['fatal'])) throw new Exception($res['fatal']);

            // The plan is re-derived per chunk and is deterministic, so chunk N
            // sees the same order chunk N-1 did. Parents always sort ahead of
            // their children and are committed by the time a later chunk needs
            // them, which is what makes chunking safe for a tree.
            $plan  = $res['plan'];
            $chunk = array_slice($plan, $offset, $limit);

            $created = 0;
            $updated = 0;
            $errors  = [];

            $conn->beginTransaction();
            try {
                foreach ($chunk as $p) {
                    $key = li_norm($p['name']);

                    // Re-read rather than trusting the plan's snapshot: an
                    // earlier chunk may have created this location's parent,
                    // and the row itself may have been created since preview.
                    $find = $conn->prepare("SELECT id, parent_id, address, phone, location_type, is_active
                                            FROM locations WHERE LOWER(TRIM(name)) = ? LIMIT 1");
                    $find->execute([$key]);
                    $current = $find->fetch(PDO::FETCH_ASSOC);

                    $parentId = null;
                    if ($p['parentKey'] !== null) {
                        $pf = $conn->prepare("SELECT id FROM locations WHERE LOWER(TRIM(name)) = ? LIMIT 1");
                        $pf->execute([$p['parentKey']]);
                        $parentId = $pf->fetchColumn();
                        if ($parentId === false) {
                            // Should be unreachable — the plan guarantees the
                            // parent is placed first. Refuse rather than
                            // quietly creating an orphan root.
                            $errors[] = ['row' => $p['row'], 'error' => 'Parent not found: ' . $p['parentName']];
                            continue;
                        }
                        $parentId = (int)$parentId;
                    }

                    if ($current) {
                        // UPDATE — details only. parent_id is deliberately absent
                        // from this statement; see the header note.
                        // A blank cell leaves the stored value alone, so a sparse
                        // sheet cannot wipe an address or deactivate a location.
                        $stmt = $conn->prepare("
                            UPDATE locations SET
                                address       = ?,
                                phone         = ?,
                                location_type = ?,
                                is_active     = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $p['address'] !== '' ? $p['address'] : $current['address'],
                            $p['phone']   !== '' ? $p['phone']   : $current['phone'],
                            $p['type']    !== null ? $p['type']  : $current['location_type'],
                            $p['active']  !== null ? $p['active'] : $current['is_active'],
                            (int)$current['id'],
                        ]);
                        logAudit('locations', 'import_update', (int)$current['id']);
                        $updated++;
                    } else {
                        $stmt = $conn->prepare("
                            INSERT INTO locations (parent_id, name, address, phone, location_type, is_active)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $parentId,
                            $p['name'],
                            $p['address'],
                            $p['phone'],
                            $p['type'],
                            $p['active'] !== null ? $p['active'] : 1,
                        ]);
                        logAudit('locations', 'import_create', (int)$conn->lastInsertId());
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
            if (!empty($fileId) && preg_match(LI_FILE_RE, $fileId)) {
                @unlink($tempDir . $fileId . '.csv');
                @unlink($tempDir . $fileId . '.xlsx');
                @unlink($tempDir . $fileId . '.json');
            }
            echo json_encode(['success' => true]);
            break;

        // =============================================================
        case 'template':
            // Headers plus a worked example: a root, a child, a grandchild and a
            // great-grandchild, plus one row deliberately placed BEFORE its
            // parent to show that sheet order does not matter.
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="locations_import_template.csv"');

            echo "\xEF\xBB\xBF"; // so Excel opens UTF-8 correctly
            echo "name,parent,type,address,phone,active\n";
            echo "\"Main Site\",,\"site\",\"Plot 12, Industrial Area\",\"+255 700 000 000\",\"Yes\"\n";
            echo "\"Main Site/Warehouse A\",\"Main Site\",\"warehouse\",,,\"Yes\"\n";
            echo "\"Main Site/Warehouse A/Rack 1\",\"Main Site/Warehouse A\",\"others\",,,\"Yes\"\n";
            echo "\"Main Site/Warehouse A/Rack 1/Shelf 1\",\"Main Site/Warehouse A/Rack 1\",\"others\",,,\"Yes\"\n";
            echo "\"Main Site/Yard\",\"Main Site\",\"others\",,,\"Yes\"\n";
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
