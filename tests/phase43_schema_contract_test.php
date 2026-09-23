<?php
declare(strict_types=1);

/**
 * A fresh install from database/schema.sql supports every write the code makes.
 *
 * migrate.php adds columns to upgraded installations, and code that relied on
 * one of those worked everywhere except where schema.sql was imported fresh:
 * /api/shares/create inserted expires_hours and created_by, which only the
 * migration created, so every Share click on a new installation was a 500
 * ("Unknown column 'expires_hours'"). This reads every INSERT column list in
 * the PHP sources and checks each column is declared by schema.sql.
 */
$root = dirname(__DIR__);
$schema = (string)file_get_contents($root.'/database/schema.sql');
$checks = [];

// Columns schema.sql declares, per table.
$declared = [];
preg_match_all('/CREATE TABLE IF NOT EXISTS\s+`?(\w+)`?\s*\((.*?)\)\s*ENGINE/si', $schema, $tables, PREG_SET_ORDER);
foreach ($tables as [, $table, $body]) {
    foreach (preg_split('/\R/', $body) as $line) {
        if (preg_match('/^\s*`?([a-z_][a-z0-9_]*)`?\s+(?:INT|BIGINT|TINYINT|SMALLINT|VARCHAR|CHAR|TEXT|MEDIUMTEXT|LONGTEXT|TIMESTAMP|DATETIME|DATE|ENUM|JSON|BOOLEAN|DECIMAL)/i', $line, $m)) {
            $declared[strtolower($table)][strtolower($m[1])] = true;
        }
    }
}
$checks['schema.sql declares its tables'] = count($declared) >= 5;

// Every INSERT column list in the application's PHP.
$sources = array_merge([$root.'/public/index.php'], glob($root.'/src/*/*.php') ?: []);
$missing = [];
$inserts = 0;
foreach ($sources as $file) {
    $php = (string)file_get_contents($file);
    preg_match_all('/INSERT INTO\s+`?(\w+)`?\s*\(([^)]*)\)\s*VALUES/i', $php, $found, PREG_SET_ORDER);
    foreach ($found as [, $table, $columns]) {
        $inserts++;
        foreach (array_map('trim', explode(',', $columns)) as $column) {
            $column = strtolower(trim($column, '` '));
            if (!isset($declared[strtolower($table)][$column])) $missing[] = basename($file).": $table.$column";
        }
    }
}
$checks['the sources were read'] = $inserts >= 5;
$checks['every inserted column exists on a fresh install'] = $missing === [];
foreach (array_unique($missing) as $gap) echo "  not in schema.sql: $gap\n";

$bad = false;
foreach ($checks as $name => $ok) { echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL; $bad = $bad || !$ok; }
exit($bad ? 1 : 0);
