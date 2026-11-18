<?php
/**
 * Applies new files in sql/migrations/ to THIS installation's database
 * (the one in config/database.php). Built for cei.fis.co.tz, run by cron
 * right after `git pull`. Not the multi-tenant api/run-migrations.php.
 *
 * Usage (command line only):
 *   php cli/run-cei-migrations.php                  apply pending migrations
 *   php cli/run-cei-migrations.php --dry-run        list pending, change nothing
 *   php cli/run-cei-migrations.php --baseline=0053  ONE-TIME: mark 0001..0053
 *                                                   as applied WITHOUT running them
 *   php cli/run-cei-migrations.php --config=/path/database.php   (testing)
 *
 * Safety rules:
 * - Tracks applied files in a `migrations` table (same shape as the
 *   multi-tenant runner). Until --baseline has been run it applies NOTHING, so
 *   migrations that were already run by hand can never run twice.
 * - Takes a full gzipped database backup before applying anything, into
 *   ~/cei_db_backups/ (last 10 kept). No backup, no migration.
 * - Stops at the first failing migration, does not record it, and writes
 *   ~/cei_migrate.BLOCKED. While that file exists every run exits at once, so a
 *   broken migration is not retried every 5 minutes. Fix it, then delete the file.
 * - Log: ~/cei_migrate.log. Silent when there is nothing to do.
 * - The password reaches mysql / mysqldump through MYSQL_PWD, never on the
 *   command line, so it does not show in the process list.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$repoRoot = dirname(__DIR__);
$home = getenv('HOME') ?: dirname($repoRoot);

$opts = getopt('', ['dry-run', 'baseline:', 'config:']);
$dryRun = isset($opts['dry-run']);
$baseline = isset($opts['baseline']) ? (string)$opts['baseline'] : null;
$configPath = isset($opts['config']) ? (string)$opts['config'] : $repoRoot . '/config/database.php';

$logFile = $home . '/cei_migrate.log';
$blockFile = $home . '/cei_migrate.BLOCKED';
$backupDir = $home . '/cei_db_backups';
$migrationsDir = $repoRoot . '/sql/migrations';
$keepBackups = 10;

function cm_log($msg) {
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    echo $line;
    @file_put_contents($logFile, $line, FILE_APPEND);
}

function cm_fail($msg) {
    cm_log('ERROR: ' . $msg);
    exit(1);
}

// One run at a time: a slow backup must not overlap the next cron tick.
$lock = fopen(sys_get_temp_dir() . '/cei_migrate_' . md5($repoRoot) . '.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

if (file_exists($blockFile) && $baseline === null) {
    // Logged once per hour at most, so the log does not fill up.
    if (time() - (int)@filemtime($logFile) > 3600) {
        cm_log('Skipped: ' . $blockFile . ' exists. Fix the failed migration, then delete that file.');
    }
    exit(1);
}

if (!is_file($configPath)) cm_fail('Config not found: ' . $configPath);
ob_start();
require $configPath; // defines DB_* and opens $conn
ob_end_clean();
if (!isset($conn) || !($conn instanceof PDO)) cm_fail('No database connection from ' . $configPath);

$conn->exec("CREATE TABLE IF NOT EXISTS `migrations` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `migration` VARCHAR(255) NOT NULL,
    `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `migration_unique` (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$files = [];
foreach ((is_dir($migrationsDir) ? scandir($migrationsDir) : []) as $f) {
    if (preg_match('/^(\d{4})_.+\.sql$/', $f, $m)) {
        $files[$f] = $m[1];
    }
}
ksort($files, SORT_STRING);
if (empty($files)) cm_fail('No migration files in ' . $migrationsDir);

$applied = array_flip($conn->query("SELECT migration FROM migrations")->fetchAll(PDO::FETCH_COLUMN));

// ---------------------------------------------------------------- baseline
if ($baseline !== null) {
    if (!preg_match('/^\d{4}$/', $baseline)) cm_fail('--baseline needs a 4-digit number, e.g. --baseline=0053');
    $stmt = $conn->prepare("INSERT IGNORE INTO migrations (migration) VALUES (?)");
    $n = 0;
    foreach ($files as $f => $num) {
        if (strcmp($num, $baseline) <= 0 && !isset($applied[$f])) {
            if (!$dryRun) $stmt->execute([$f]);
            $n++;
        }
    }
    cm_log(($dryRun ? '[dry-run] Would mark ' : 'Baseline: marked ') . $n . ' migration(s) up to ' . $baseline . ' as applied (not run).');
    exit(0);
}

if (empty($applied)) {
    cm_fail('No migrations are recorded yet. Run once with --baseline=NNNN (the last migration already applied by hand).');
}

$pending = array_values(array_filter(array_keys($files), function ($f) use ($applied) { return !isset($applied[$f]); }));
if (empty($pending)) {
    exit(0); // nothing to do, stay silent
}

if ($dryRun) {
    cm_log('[dry-run] Pending: ' . implode(', ', $pending));
    exit(0);
}

// Run a shell command with the DB password in the environment only.
function cm_run($cmd, &$output) {
    $env = ['MYSQL_PWD' => DB_PASS, 'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin', 'HOME' => getenv('HOME') ?: '/tmp'];
    $bash = getenv('CEI_MIGRATE_BASH') ?: '/bin/bash'; // override only for local testing
    $proc = proc_open([$bash, '-c', $cmd], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($proc)) { $output = 'could not start command'; return 1; }
    $output = trim(stream_get_contents($pipes[1]) . "\n" . stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    return proc_close($proc);
}

$conn = null; // release the PDO connection; mysql / mysqldump do the work

// ---------------------------------------------------------------- backup
if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true)) cm_fail('Cannot create ' . $backupDir);
$backupFile = $backupDir . '/' . DB_NAME . '_' . date('Ymd_His') . '_before_' . substr($pending[0], 0, 4) . '.sql.gz';
$dump = 'set -o pipefail; mysqldump --single-transaction --quick --no-tablespaces --default-character-set=utf8mb4'
      . ' -h ' . escapeshellarg(DB_HOST) . ' -u ' . escapeshellarg(DB_USER) . ' ' . escapeshellarg(DB_NAME)
      . ' | gzip > ' . escapeshellarg($backupFile);
if (cm_run($dump, $out) !== 0 || !is_file($backupFile) || filesize($backupFile) < 100) {
    @unlink($backupFile);
    file_put_contents($blockFile, date('Y-m-d H:i:s') . " Backup failed, nothing applied:\n" . $out . "\n");
    cm_fail('Backup failed, nothing applied. ' . $out);
}
cm_log('Backup: ' . $backupFile . ' (' . round(filesize($backupFile) / 1048576, 1) . ' MB)');

$old = glob($backupDir . '/' . DB_NAME . '_*.sql.gz') ?: [];
sort($old);
foreach (array_slice($old, 0, max(0, count($old) - $keepBackups)) as $f) {
    @unlink($f);
}

// ---------------------------------------------------------------- apply
$mysql = 'mysql --default-character-set=utf8mb4 -h ' . escapeshellarg(DB_HOST) . ' -u ' . escapeshellarg(DB_USER) . ' ' . escapeshellarg(DB_NAME);
$record = 'INSERT INTO migrations (migration) VALUES (';
foreach ($pending as $f) {
    $code = cm_run($mysql . ' < ' . escapeshellarg($migrationsDir . '/' . $f), $out);
    if ($code !== 0) {
        file_put_contents($blockFile, date('Y-m-d H:i:s') . " $f failed (backup: $backupFile):\n" . $out . "\n");
        cm_fail("$f FAILED, stopped. Backup: $backupFile\n" . $out);
    }
    $code = cm_run($mysql . ' -e ' . escapeshellarg($record . "'" . addslashes($f) . "')"), $out2);
    if ($code !== 0) {
        file_put_contents($blockFile, date('Y-m-d H:i:s') . " $f ran but could not be recorded:\n" . $out2 . "\n");
        cm_fail("$f ran but could not be recorded: " . $out2);
    }
    cm_log("Applied $f" . ($out !== '' ? "\n" . $out : ''));
}
exit(0);
