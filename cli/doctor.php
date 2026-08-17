<?php

/**
 * ytds doctor: environment checks. Read-only by contract — opens SQLite with
 * SQLITE3_OPEN_READONLY and never requires code/db/db.php (whose global
 * instantiation would create a missing database file).
 *
 * Check statuses: ok | warn | fail | skip. Overall ok = no fail.
 */

/** @return array<int, array{id: string, status: string, detail: string}> */
function ytds_doctor(string $repoRoot, string $dbPath, bool $dbExists): array
{
    $checks = [];

    $checks[] = ['id' => 'php.version', 'status' => 'ok', 'detail' => PHP_VERSION];
    $checks[] = class_exists('SQLite3')
        ? ['id' => 'php.sqlite3', 'status' => 'ok', 'detail' => 'loaded']
        : ['id' => 'php.sqlite3', 'status' => 'fail', 'detail' => 'sqlite3 extension not loaded'];

    $checks[] = [
        'id' => 'settings.local',
        'status' => 'ok',
        'detail' => is_file($repoRoot . '/code/settings.local.php') ? 'present' : 'absent (defaults active)',
    ];

    global $cloSettings;
    $apiEnabled = trim((string)($cloSettings['adminApiToken'] ?? '')) !== '';
    $checks[] = [
        'id' => 'admin.api',
        'status' => 'ok',
        'detail' => $apiEnabled ? 'adminApiToken configured (remote enabled)' : 'adminApiToken empty (remote disabled)',
    ];

    if ($dbExists) {
        $checks[] = [
            'id' => 'db.file',
            'status' => 'ok',
            'detail' => sprintf('%s, %d bytes', basename($dbPath), (int)filesize($dbPath)),
        ];
        array_push($checks, ...ytds_doctor_db_checks($dbPath));
    } else {
        $checks[] = ['id' => 'db.file', 'status' => 'fail', 'detail' => 'missing: ' . $dbPath];
        $checks[] = ['id' => 'db.schema', 'status' => 'skip', 'detail' => 'no database'];
        $checks[] = ['id' => 'db.campaigns', 'status' => 'skip', 'detail' => 'no database'];
    }

    $defaults = @file_get_contents($repoRoot . '/code/db/default.json');
    $checks[] = is_array(json_decode((string)$defaults, true))
        ? ['id' => 'defaults.json', 'status' => 'ok', 'detail' => 'parseable']
        : ['id' => 'defaults.json', 'status' => 'fail', 'detail' => 'code/db/default.json missing or invalid JSON'];

    $version = @file_get_contents($repoRoot . '/code/admin/version.txt');
    $checks[] = ($version !== false && trim($version) !== '')
        ? ['id' => 'version', 'status' => 'ok', 'detail' => trim($version)]
        : ['id' => 'version', 'status' => 'warn', 'detail' => 'code/admin/version.txt missing or empty'];

    $missing = array_values(array_filter(
        ['country.mmdb', 'asn.mmdb'],
        fn (string $f): bool => !is_file($repoRoot . '/code/bases/' . $f)
    ));
    $checks[] = $missing === []
        ? ['id' => 'geobases', 'status' => 'ok', 'detail' => 'country.mmdb + asn.mmdb present']
        : ['id' => 'geobases', 'status' => 'warn', 'detail' => implode(', ', $missing) . ' missing (see AGENTS.md §5)'];

    return $checks;
}

/** @return array<int, array{id: string, status: string, detail: string}> */
function ytds_doctor_db_checks(string $dbPath): array
{
    $expected = ['campaigns', 'clicks', 'click_steps', 'conversions', 'blocked', 'trafficback', 'common'];
    try {
        $db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
    } catch (Throwable $e) {
        return [
            ['id' => 'db.schema', 'status' => 'fail', 'detail' => 'cannot open read-only: ' . $e->getMessage()],
            ['id' => 'db.campaigns', 'status' => 'skip', 'detail' => 'database unreadable'],
        ];
    }

    $checks = [];
    try {
        $tables = [];
        $result = $db->query("SELECT name FROM sqlite_master WHERE type = 'table'");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $tables[] = $row['name'];
        }
        $missing = array_values(array_diff($expected, $tables));
        $checks[] = $missing === []
            ? ['id' => 'db.schema', 'status' => 'ok', 'detail' => count($expected) . '/' . count($expected) . ' tables']
            : ['id' => 'db.schema', 'status' => 'fail', 'detail' => 'missing tables: ' . implode(', ', $missing)];

        if ($missing === []) {
            $bad = [];
            $count = 0;
            $rows = $db->query('SELECT id, settings FROM campaigns');
            while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
                $count++;
                if (!is_array(json_decode((string)$row['settings'], true))) {
                    $bad[] = (int)$row['id'];
                }
            }
            $checks[] = $bad === []
                ? ['id' => 'db.campaigns', 'status' => 'ok', 'detail' => $count . ' campaigns, settings JSON valid']
                : ['id' => 'db.campaigns', 'status' => 'fail', 'detail' => 'invalid settings JSON: campaign ' . implode(', ', $bad)];
        } else {
            $checks[] = ['id' => 'db.campaigns', 'status' => 'skip', 'detail' => 'schema incomplete'];
        }
    } finally {
        $db->close();
    }
    return $checks;
}
