<?php

require_once __DIR__ . '/../db/db.php';   // instantiates the global $db
require_once __DIR__ . '/../adminops.php';

/**
 * Admin API — the remote port of the read-only AdminOps layer. Lives OUTSIDE the hex admin
 * path (always /api/admin.php) so agents can reach it without knowing the secret path. Bearer
 * token in settings.local.php (adminApiToken); GET only; JSON only. Never returns the token,
 * password, or adminPath. Disabled (404) until a token is configured.
 *
 * Success bodies and {code, message, hint} error bodies match the ytds CLI exactly, so the same
 * operation yields identical JSON locally and over HTTP.
 */

/** Bearer check. False when disabled (no token) OR unauthorized (bad/missing token). */
function ytds_admin_api_authorized(array $server, array $settings): bool
{
    $configured = trim((string)($settings['adminApiToken'] ?? ''));
    if ($configured === '') {
        return false;
    }
    $header = (string)($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (!str_starts_with($header, 'Bearer ')) {
        return false;
    }
    return hash_equals($configured, substr($header, 7));
}

/**
 * Pure request router: no echo, no exit, no headers. Returns the HTTP status and body so it can
 * be unit-tested without a socket. Auth is handled by the caller.
 *
 * @param array<string, mixed> $query  the request query params ($_GET)
 * @return array{status: int, body: array<string, mixed>}
 */
function ytds_admin_api_dispatch(Db $db, string $action, array $query, string $codeDir, string $body = ''): array
{
    $ops = new AdminOps($db);
    try {
        return ['status' => 200, 'body' => ytds_admin_api_run_action($ops, $action, $query, $codeDir, $body)];
    } catch (YtdsOpError $e) {
        return ['status' => $e->httpStatus, 'body' => ['code' => $e->errorCode, 'message' => $e->getMessage(), 'hint' => $e->hint]];
    } catch (Throwable $e) {
        return ['status' => 500, 'body' => ['code' => 'INTERNAL', 'message' => get_class($e) . ': ' . $e->getMessage(), 'hint' => '']];
    }
}

/**
 * @param array<string, mixed> $query
 * @return array<string, mixed>
 */
function ytds_admin_api_run_action(AdminOps $ops, string $action, array $query, string $codeDir, string $body = ''): array
{
    switch ($action) {
        case 'version':
            return $ops->version(get_admin_dir() . '/version.txt'); // admin/ is renamed on the instance

        case 'campaigns.list':
            return $ops->campaignsList();

        case 'campaign.get':
            $id = ytds_admin_api_require_int($query, 'id');
            $section = isset($query['section']) ? (string)$query['section'] : null;
            return $ops->campaignGet($id, $section, ytds_admin_api_flag($query, 'full'));

        case 'stats':
            return $ops->stats(
                ytds_admin_api_require_int($query, 'campaign'),
                ytds_admin_api_opt_str($query, 'from'),
                ytds_admin_api_opt_str($query, 'to'),
                ytds_admin_api_csv($query, 'columns'),
                ytds_admin_api_csv($query, 'groupby')
            );

        case 'clicks':
            $view = isset($query['view']) ? (string)$query['view'] : 'allowed';
            $campId = $view === 'trafficback'
                ? 0
                : ytds_admin_api_require_int($query, 'campaign');
            return $ops->clicks(
                $campId,
                $view,
                ytds_admin_api_opt_str($query, 'from'),
                ytds_admin_api_opt_str($query, 'to'),
                isset($query['limit']) ? (int)$query['limit'] : 50,
                ytds_admin_api_flag($query, 'full'),
                [
                    'page' => $query['page'] ?? '1',
                    'sort' => $query['sort'] ?? 'time',
                    'dir' => $query['dir'] ?? 'desc',
                    'search' => $query['search'] ?? '',
                    'filter-cond' => $query['filter-cond'] ?? 'AND',
                    'filter' => (array)($query['filter'] ?? []),
                    'param' => (array)($query['param'] ?? []),
                ]
            );

        case 'landing.list':
            return $ops->landings(ytds_admin_api_landings_dir($codeDir));

        case 'landing.upload':
            $tmp = tempnam(sys_get_temp_dir(), 'ytds_zip_');
            if ($tmp === false || file_put_contents($tmp, $body) === false) {
                throw new YtdsOpError('WRITE_FAILED', 500, 'could not buffer uploaded zip', '');
            }
            try {
                return $ops->landingUpload(ytds_admin_api_landings_dir($codeDir), (string)($query['name'] ?? ''), $tmp, ytds_admin_api_flag($query, 'commit'));
            } finally {
                @unlink($tmp);
            }

        case 'landing.duplicate':
            return $ops->landingDuplicate(ytds_admin_api_landings_dir($codeDir), (string)($query['from'] ?? ''), (string)($query['to'] ?? ''), ytds_admin_api_flag($query, 'commit'));

        case 'landing.delete':
            return $ops->landingDelete(ytds_admin_api_landings_dir($codeDir), (string)($query['name'] ?? ''), ytds_admin_api_flag($query, 'commit'));

        case 'destinations.list':
            return $ops->destinations();

        case 'networks.list':
            return $ops->networksList();

        case 'networks.add':
            return $ops->networkAdd((string)($query['name'] ?? ''), (string)($query['params'] ?? ''), ytds_admin_api_flag($query, 'commit'));

        case 'networks.update':
            return $ops->networkUpdate(
                (string)($query['id'] ?? ''),
                array_key_exists('name', $query) ? (string)$query['name'] : null,
                array_key_exists('params', $query) ? (string)$query['params'] : null,
                ytds_admin_api_flag($query, 'commit')
            );

        case 'networks.delete':
            return $ops->networkDelete((string)($query['id'] ?? ''), ytds_admin_api_flag($query, 'commit'));

        case 'destinations.add':
            return $ops->destinationAdd(
                (string)($query['name'] ?? ''),
                (string)($query['base_url'] ?? ''),
                array_key_exists('network_id', $query) ? (string)$query['network_id'] : null,
                ytds_admin_api_flag($query, 'commit')
            );

        case 'destinations.update':
            return $ops->destinationUpdate(
                (string)($query['id'] ?? ''),
                array_key_exists('name', $query) ? (string)$query['name'] : null,
                array_key_exists('base_url', $query) ? (string)$query['base_url'] : null,
                array_key_exists('network_id', $query) ? (string)$query['network_id'] : null,
                ytds_admin_api_flag($query, 'commit')
            );

        case 'destinations.delete':
            return $ops->destinationDelete((string)($query['id'] ?? ''), ytds_admin_api_flag($query, 'commit'));

        case 'campaign.clone':
            return $ops->cloneCampaign(
                ytds_admin_api_require_int($query, 'id'),
                ytds_admin_api_opt_str($query, 'name'),
                ytds_admin_api_flag($query, 'commit')
            );

        case 'campaign.rename':
            return $ops->renameCampaign(
                ytds_admin_api_require_int($query, 'id'),
                (string)($query['name'] ?? ''),
                ytds_admin_api_flag($query, 'commit')
            );

        case 'campaign.delete':
            return $ops->deleteCampaign(
                ytds_admin_api_require_int($query, 'id'),
                ytds_admin_api_flag($query, 'commit')
            );

        case 'campaign.domains':
            return $ops->setDomains(
                ytds_admin_api_require_int($query, 'id'),
                ytds_admin_api_csv($query, 'set'),
                ytds_admin_api_flag($query, 'commit')
            );

        case 'campaign.patch':
            $decoded = json_decode($body, true);
            if (!is_array($decoded)) {
                throw new YtdsOpError('INVALID_ARG', 400, 'campaign.patch requires a JSON object body', 'POST the settings fragment as the request body');
            }
            return $ops->patch(
                ytds_admin_api_require_int($query, 'id'),
                $decoded,
                ytds_admin_api_flag($query, 'commit')
            );

        case 'campaign.create':
            return $ops->create(
                (string)($query['name'] ?? ''),
                ytds_load_campaign_template($codeDir, ytds_admin_api_opt_str($query, 'template') ?? 'blank'),
                ytds_admin_api_flag($query, 'commit')
            );

        case 'campaign.set':
            $sets = json_decode($body, true);
            if (!is_array($sets)) {
                throw new YtdsOpError('INVALID_ARG', 400, 'campaign.set requires a JSON object body of path => value', 'POST {"uniqueness.enabled":true} as the body');
            }
            return $ops->setFields(
                ytds_admin_api_require_int($query, 'id'),
                $sets,
                ytds_admin_api_flag($query, 'commit')
            );

        case 'campaign.kill-defaults':
            return $ops->killAuthorDefaults(
                ytds_admin_api_require_int($query, 'id'),
                ytds_admin_api_flag($query, 'commit')
            );

        default:
            throw new YtdsOpError('UNKNOWN_ACTION', 400, 'unknown action: ' . $action, 'reads: version, campaigns.list, campaign.get, stats, clicks, landing.list, destinations.list; writes (POST): campaign.create, campaign.clone, campaign.rename, campaign.delete, campaign.domains, campaign.patch, campaign.set, campaign.kill-defaults');
    }
}

/** @param array<string, mixed> $query */
function ytds_admin_api_require_int(array $query, string $key): int
{
    $raw = $query[$key] ?? '';
    if (!is_string($raw) && !is_int($raw)) {
        throw new YtdsOpError('INVALID_ARG', 400, $key . ' is required', '');
    }
    $raw = (string)$raw;
    if (!ctype_digit($raw)) {
        throw new YtdsOpError('INVALID_ARG', 400, $key . ' must be a positive integer, got: ' . $raw, '');
    }
    return (int)$raw;
}

/** @param array<string, mixed> $query */
function ytds_admin_api_opt_str(array $query, string $key): ?string
{
    return isset($query[$key]) && (string)$query[$key] !== '' ? (string)$query[$key] : null;
}

/**
 * @param array<string, mixed> $query
 * @return array<int, string>
 */
function ytds_admin_api_csv(array $query, string $key): array
{
    $raw = ytds_admin_api_opt_str($query, $key);
    if ($raw === null) {
        return [];
    }
    return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn(string $s): bool => $s !== ''));
}

/** @param array<string, mixed> $query */
function ytds_admin_api_flag(array $query, string $key): bool
{
    return isset($query[$key]) && in_array((string)$query[$key], ['1', 'true', 'yes', 'on', ''], true);
}

/** Write actions mutate state and must be POSTed (GET stays safe/idempotent). */
function ytds_admin_api_is_write(string $action): bool
{
    return in_array($action, ['campaign.create', 'campaign.clone', 'campaign.rename', 'campaign.delete', 'campaign.domains', 'campaign.patch', 'campaign.set', 'campaign.kill-defaults', 'networks.add', 'networks.update', 'networks.delete', 'destinations.add', 'destinations.update', 'destinations.delete', 'landing.upload', 'landing.duplicate', 'landing.delete'], true);
}

/** Resolves the landings cache directory, falling back to the unresolved path when it does not exist yet. */
function ytds_admin_api_landings_dir(string $codeDir): string
{
    $dir = realpath($codeDir . '/' . get_cache_path('landings'));
    return $dir === false ? $codeDir . '/' . get_cache_path('landings') : $dir;
}

function amarelotds_run_admin_api(Db $db): never
{
    header('Content-Type: application/json; charset=utf-8');

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET' && $method !== 'POST') {
        ytds_admin_api_respond(405, ['code' => 'METHOD_NOT_ALLOWED', 'message' => 'admin API accepts GET (reads) and POST (mutations)', 'hint' => '']);
    }

    global $cloSettings;
    $configured = trim((string)($cloSettings['adminApiToken'] ?? ''));
    if ($configured === '') {
        ytds_admin_api_respond(404, ['code' => 'API_DISABLED', 'message' => 'admin API is disabled', 'hint' => 'set adminApiToken in settings.local.php']);
    }
    if (!ytds_admin_api_authorized($_SERVER, $cloSettings)) {
        ytds_admin_api_respond(401, ['code' => 'AUTH_INVALID', 'message' => 'missing or invalid bearer token', 'hint' => 'Authorization: Bearer <adminApiToken>']);
    }

    $action = isset($_GET['action']) ? (string)$_GET['action'] : '';
    if (ytds_admin_api_is_write($action) && $method !== 'POST') {
        ytds_admin_api_respond(405, ['code' => 'METHOD_NOT_ALLOWED', 'message' => 'mutation requires POST: ' . $action, 'hint' => 'POST with commit=1 to apply; omit commit for a dry-run']);
    }
    $result = ytds_admin_api_dispatch($db, $action, $_GET, __DIR__ . '/..', (string)file_get_contents('php://input'));
    ytds_admin_api_respond($result['status'], $result['body']);
}

/**
 * @param array<string, mixed> $body
 */
function ytds_admin_api_respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (!defined('AMARELOTDS_ADMIN_API_NO_RUN')) {
    global $db;
    amarelotds_run_admin_api($db);
}
