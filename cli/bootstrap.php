<?php

/**
 * Boot for the ytds CLI. The order is load-bearing:
 *
 * 1. code/settings.php defines the global $cloSettings (settings.local.php or defaults).
 * 2. The target database is stat'ed BEFORE code/db/db.php is required — requiring
 *    db.php instantiates the global $db, and Db::__construct() CREATES a missing
 *    database file. A read-only CLI must never do that.
 * 3. With --db, $cloSettings['dbConnection'] is rewritten to a path relative to
 *    code/db/ (Db resolves __DIR__ . '/' . dbConnection), then db.php is required.
 */

// File scope on purpose: settings.php assigns $cloSettings at its own top level,
// and this file is only ever required from top level (bin/ytds → cli/main.php).
// Requiring it inside a function would bind $cloSettings to that function's scope.
require_once dirname(__DIR__) . '/code/settings.php';

function ytds_repo_root(): string
{
    return dirname(__DIR__);
}

/**
 * Resolves the target database path without touching the filesystem.
 *
 * @return array{path: string, exists: bool}
 */
function ytds_resolve_db(?string $override): array
{
    global $cloSettings;

    $candidate = ($override !== null && $override !== '')
        ? (str_starts_with($override, '/') ? $override : getcwd() . '/' . $override)
        : ytds_repo_root() . '/code/db/' . $cloSettings['dbConnection'];

    $real = realpath($candidate);
    if ($real !== false && is_file($real)) {
        return ['path' => $real, 'exists' => true];
    }
    return ['path' => $candidate, 'exists' => false];
}

/**
 * Requires the engine bound to the resolved database and returns the read-only ops layer.
 * Only call with an existing file — requiring db.php creates missing databases.
 */
function ytds_open_ops(string $dbAbsPath): AdminOps
{
    global $cloSettings, $db;

    $dbDir = realpath(ytds_repo_root() . '/code/db');
    $target = realpath($dbAbsPath);
    if ($dbDir === false || $target === false) {
        throw new RuntimeException('cannot resolve database path: ' . $dbAbsPath);
    }
    $cloSettings['dbConnection'] = ytds_relative_from($dbDir, $target);

    require_once ytds_repo_root() . '/code/db/db.php'; // instantiates the global $db
    require_once ytds_repo_root() . '/code/adminops.php';

    return new AdminOps($db);
}

/** Path of $toFile relative to $fromDir; both absolute and symlink-free (realpath'ed). */
function ytds_relative_from(string $fromDir, string $toFile): string
{
    $from = explode('/', trim($fromDir, '/'));
    $to = explode('/', trim($toFile, '/'));
    while ($from !== [] && $to !== [] && $from[0] === $to[0]) {
        array_shift($from);
        array_shift($to);
    }
    return str_repeat('../', count($from)) . implode('/', $to);
}
