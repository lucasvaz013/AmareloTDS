<?php

/**
 * ytds CLI dispatcher.
 *
 * Two ports, one contract: --env local runs the read-only AdminOps layer in-process; any other
 * environment (--env stg|prod|<name>) proxies to that instance's /api/admin.php. Both emit the
 * same result JSON on stdout and the same {code, message, hint} on stderr.
 *
 * exit: 0 ok · 1 internal/environment · 2 input validation · 3 not found · 4 auth/config
 *       (5 reserved for domain conflicts in a later mutation phase)
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/doctor.php';
require_once __DIR__ . '/remote.php';

const YTDS_SURFACE = 'ytds <campaigns list | campaign get <id> [--section p] [--full] | campaign create --name X [--from-template blank] [--yes] | campaign clone|rename|delete|domains|kill-defaults <id> [...] [--yes] | campaign patch <id> (--apply f.json | --set path=val ...) [--yes] | networks list|add|update|delete | destinations list|add|update|delete | landing list|upload|duplicate|delete | stats --campaign N [--columns a,b] [--groupby c] | clicks --campaign N [--view v] [--limit N] [--page N] [--sort field] [--dir asc|desc] [--filter field:op:value] [--param key] [--search term] | version | doctor>; global: [--env local|stg|prod] [--db path]';

function ytds_run(array $argv): int
{
    try {
        return ytds_dispatch(array_slice($argv, 1));
    } catch (Throwable $e) {
        return ytds_fail('INTERNAL', get_class($e) . ': ' . $e->getMessage(), '', 1);
    }
}

/** @param array<int, string> $args */
function ytds_dispatch(array $args): int
{
    [$pos, $opts, $err] = ytds_parse($args);
    if ($err !== null) {
        return ytds_fail('INVALID_ARG', $err, YTDS_SURFACE, 2);
    }
    if ($pos === []) {
        return ytds_fail('USAGE', 'missing command', YTDS_SURFACE, 2);
    }

    $cmd = implode(' ', array_slice($pos, 0, 2));
    if (isset($opts['section']) && $cmd !== 'campaign get') {
        return ytds_fail('INVALID_ARG', '--section only applies to campaign get', YTDS_SURFACE, 2);
    }

    return match (true) {
        $cmd === 'campaigns list' && count($pos) === 2 => ytds_cmd_campaigns_list($opts),
        $cmd === 'campaign get' => ytds_cmd_campaign_get($pos, $opts),
        $cmd === 'campaign clone' => ytds_cmd_campaign_write('clone', $pos, $opts),
        $cmd === 'campaign rename' => ytds_cmd_campaign_write('rename', $pos, $opts),
        $cmd === 'campaign delete' => ytds_cmd_campaign_write('delete', $pos, $opts),
        $cmd === 'campaign domains' => ytds_cmd_campaign_write('domains', $pos, $opts),
        $cmd === 'campaign patch' => ytds_cmd_campaign_patch($pos, $opts),
        $cmd === 'campaign kill-defaults' => ytds_cmd_campaign_write('kill-defaults', $pos, $opts),
        $cmd === 'campaign create' && count($pos) === 2 => ytds_cmd_campaign_create($opts),
        $pos[0] === 'landing' => ytds_cmd_landing($pos, $opts),
        $pos[0] === 'destinations' => ytds_cmd_destinations($pos, $opts),
        $pos[0] === 'networks' => ytds_cmd_networks($pos, $opts),
        $pos[0] === 'stats' && count($pos) === 1 => ytds_cmd_stats($opts),
        $pos[0] === 'clicks' && count($pos) === 1 => ytds_cmd_clicks($opts),
        $pos[0] === 'version' && count($pos) === 1 => ytds_cmd_version($opts),
        $pos[0] === 'doctor' && count($pos) === 1 => ytds_cmd_doctor($opts),
        default => ytds_fail('USAGE', 'unknown command: ' . implode(' ', $pos), YTDS_SURFACE, 2),
    };
}

/**
 * @param array<int, string> $args
 * @return array{0: array<int, string>, 1: array<string, string|true>, 2: ?string}
 */
function ytds_parse(array $args): array
{
    $valueFlags = ['db', 'env', 'section', 'campaign', 'from', 'to', 'columns', 'groupby', 'view', 'limit', 'name', 'set', 'apply', 'from-template', 'params', 'base-url', 'network', 'zip', 'page', 'sort', 'dir', 'search', 'filter', 'filter-cond', 'param'];
    $boolFlags = ['full', 'yes'];
    $pos = [];
    $opts = [];
    for ($i = 0, $n = count($args); $i < $n; $i++) {
        $arg = $args[$i];
        if (!str_starts_with($arg, '--')) {
            $pos[] = $arg;
            continue;
        }
        $body = substr($arg, 2);
        $eq = strpos($body, '=');
        $name = $eq === false ? $body : substr($body, 0, $eq);
        if (in_array($name, $boolFlags, true)) {
            if ($eq !== false) {
                return [[], [], '--' . $name . ' takes no value'];
            }
            $opts[$name] = true;
            continue;
        }
        if (!in_array($name, $valueFlags, true)) {
            return [[], [], 'unknown flag: --' . $name];
        }
        $value = $eq !== false ? substr($body, $eq + 1) : null;
        if ($value === null) {
            $i++;
            if ($i >= $n || str_starts_with($args[$i], '--')) {
                return [[], [], '--' . $name . ' requires a value'];
            }
            $value = $args[$i];
        }
        if (in_array($name, ['set', 'filter', 'param'], true)) {
            $opts[$name][] = $value; // repeatable flags accumulate into a list
        } else {
            $opts[$name] = $value;
        }
    }
    return [$pos, $opts, null];
}

// ── Commands ────────────────────────────────────────────────────────────────

/** @param array<string, string|true> $opts */
function ytds_cmd_campaigns_list(array $opts): int
{
    if (($env = ytds_env($opts)) !== 'local') {
        return ytds_remote($env, 'campaigns.list', []);
    }
    return ytds_local($opts, static fn(AdminOps $ops): array => $ops->campaignsList());
}

/**
 * @param array<int, string> $pos
 * @param array<string, string|true> $opts
 */
function ytds_cmd_campaign_get(array $pos, array $opts): int
{
    if (count($pos) !== 3) {
        return ytds_fail('USAGE', 'campaign get needs exactly one id', 'ytds campaign get <id>', 2);
    }
    $idArg = $pos[2];
    if (!ctype_digit($idArg)) {
        return ytds_fail('INVALID_ARG', 'campaign id must be a positive integer, got: ' . $idArg, 'ytds campaigns list', 2);
    }
    $section = isset($opts['section']) ? (string)$opts['section'] : null;
    $full = isset($opts['full']);
    if ($section !== null && $full) {
        return ytds_fail('INVALID_ARG', '--section and --full are mutually exclusive', '', 2);
    }

    if (($env = ytds_env($opts)) !== 'local') {
        $params = ['id' => $idArg];
        if ($section !== null) {
            $params['section'] = $section;
        }
        if ($full) {
            $params['full'] = '1';
        }
        return ytds_remote($env, 'campaign.get', $params);
    }
    return ytds_local($opts, static fn(AdminOps $ops): array => $ops->campaignGet((int)$idArg, $section, $full));
}

/**
 * Mutations: clone | rename | delete | domains. Dry-run (before/after diff) unless --yes commits;
 * remote mutations are POSTed. Same result JSON local and remote.
 * @param array<int, string> $pos
 * @param array<string, string|true> $opts
 */
function ytds_cmd_campaign_write(string $verb, array $pos, array $opts): int
{
    if (count($pos) !== 3) {
        return ytds_fail('USAGE', 'campaign ' . $verb . ' needs exactly one id', 'ytds campaign ' . $verb . ' <id>', 2);
    }
    $idArg = $pos[2];
    if (!ctype_digit($idArg)) {
        return ytds_fail('INVALID_ARG', 'campaign id must be a positive integer, got: ' . $idArg, 'ytds campaigns list', 2);
    }
    $id = (int)$idArg;
    $commit = isset($opts['yes']);
    $name = isset($opts['name']) ? (string)$opts['name'] : null;

    switch ($verb) {
        case 'clone':
            $action = 'campaign.clone';
            $params = ['id' => $idArg];
            if ($name !== null) {
                $params['name'] = $name;
            }
            $local = static fn(AdminOps $ops): array => $ops->cloneCampaign($id, $name, $commit);
            break;
        case 'rename':
            if ($name === null || trim($name) === '') {
                return ytds_fail('INVALID_ARG', 'rename requires --name <new>', 'ytds campaign rename ' . $idArg . ' --name X', 2);
            }
            $action = 'campaign.rename';
            $params = ['id' => $idArg, 'name' => $name];
            $local = static fn(AdminOps $ops): array => $ops->renameCampaign($id, $name, $commit);
            break;
        case 'delete':
            $action = 'campaign.delete';
            $params = ['id' => $idArg];
            $local = static fn(AdminOps $ops): array => $ops->deleteCampaign($id, $commit);
            break;
        case 'domains':
            if (!isset($opts['set'])) {
                return ytds_fail('INVALID_ARG', 'domains requires --set a.com,b.com (full replacement)', 'ytds campaign domains ' . $idArg . ' --set a.com,b.com', 2);
            }
            $domains = ytds_csv(implode(',', (array)$opts['set']));
            $action = 'campaign.domains';
            $params = ['id' => $idArg, 'set' => implode(',', $domains)];
            $local = static fn(AdminOps $ops): array => $ops->setDomains($id, $domains, $commit);
            break;
        case 'kill-defaults':
            $action = 'campaign.kill-defaults';
            $params = ['id' => $idArg];
            $local = static fn(AdminOps $ops): array => $ops->killAuthorDefaults($id, $commit);
            break;
        default:
            return ytds_fail('USAGE', 'unknown campaign verb: ' . $verb, YTDS_SURFACE, 2);
    }

    if ($commit) {
        $params['commit'] = '1';
    }
    if (($env = ytds_env($opts)) !== 'local') {
        return ytds_remote($env, $action, $params, 'POST');
    }
    return ytds_local($opts, $local);
}

/**
 * campaign create --name "<name>" [--from-template <name>]: creates a campaign from a versioned
 * template (default: blank) with the author's dangerous defaults already stripped (Guardrail #9).
 * Dry-run unless --yes; remote resolves the template server-side.
 * @param array<string, string|true> $opts
 */
function ytds_cmd_campaign_create(array $opts): int
{
    $name = isset($opts['name']) ? trim((string)$opts['name']) : '';
    if ($name === '') {
        return ytds_fail('INVALID_ARG', 'create requires --name "<campaign name>"', 'ytds campaign create --name "My Campaign" --from-template blank', 2);
    }
    $template = isset($opts['from-template']) ? (string)$opts['from-template'] : 'blank';
    $commit = isset($opts['yes']);

    if (($env = ytds_env($opts)) !== 'local') {
        $params = ['name' => $name, 'template' => $template];
        if ($commit) {
            $params['commit'] = '1';
        }
        return ytds_remote($env, 'campaign.create', $params, 'POST');
    }
    return ytds_local($opts, static function (AdminOps $ops) use ($name, $template, $commit): array {
        $settings = ytds_load_campaign_template(ytds_repo_root() . '/code', $template);
        return $ops->create($name, $settings, $commit);
    });
}

/**
 * campaign patch <id> --apply <file.json>: merges a settings fragment through the panel validators
 * (uniqueness/event/conversion/postback/capi/flow). Dry-run diff unless --yes; remote POSTs the
 * fragment as the JSON body.
 * @param array<int, string> $pos
 * @param array<string, string|true> $opts
 */
function ytds_cmd_campaign_patch(array $pos, array $opts): int
{
    if (count($pos) !== 3 || !ctype_digit($pos[2])) {
        return ytds_fail('INVALID_ARG', 'campaign patch needs one numeric id', 'ytds campaign patch <id> (--apply f.json | --set path=val)', 2);
    }
    $id = (int)$pos[2];
    $commit = isset($opts['yes']);
    $hasApply = isset($opts['apply']);
    $hasSet = isset($opts['set']);
    if ($hasApply && $hasSet) {
        return ytds_fail('INVALID_ARG', 'use --apply or --set, not both', '', 2);
    }
    if (!$hasApply && !$hasSet) {
        return ytds_fail('INVALID_ARG', 'patch requires --apply <file.json> or --set path=value', 'ytds campaign patch ' . $pos[2] . ' --set uniqueness.enabled=true', 2);
    }

    if ($hasSet) {
        $sets = [];
        foreach ((array)$opts['set'] as $pair) {
            $eq = strpos((string)$pair, '=');
            if ($eq === false) {
                return ytds_fail('INVALID_ARG', '--set expects path=value, got: ' . $pair, '', 2);
            }
            $sets[substr((string)$pair, 0, $eq)] = ytds_parse_set_value(substr((string)$pair, $eq + 1));
        }
        if (($env = ytds_env($opts)) !== 'local') {
            $params = ['id' => $pos[2]];
            if ($commit) {
                $params['commit'] = '1';
            }
            return ytds_remote($env, 'campaign.set', $params, 'POST', (string)json_encode($sets));
        }
        return ytds_local($opts, static fn(AdminOps $ops): array => $ops->setFields($id, $sets, $commit));
    }

    $raw = @file_get_contents((string)$opts['apply']);
    if ($raw === false) {
        return ytds_fail('INVALID_ARG', 'cannot read --apply file: ' . (string)$opts['apply'], '', 2);
    }
    $fragment = json_decode($raw, true);
    if (!is_array($fragment)) {
        return ytds_fail('INVALID_ARG', '--apply file is not a JSON object', '', 2);
    }
    if (($env = ytds_env($opts)) !== 'local') {
        $params = ['id' => $pos[2]];
        if ($commit) {
            $params['commit'] = '1';
        }
        return ytds_remote($env, 'campaign.patch', $params, 'POST', $raw);
    }
    return ytds_local($opts, static fn(AdminOps $ops): array => $ops->patch($id, $fragment, $commit));
}

/** Parses a --set value: valid JSON (true/false/number/array/"string") is decoded; a bare word stays a string. */
function ytds_parse_set_value(string $raw): mixed
{
    $decoded = json_decode($raw, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
}

/** @param array<string, string|true> $opts */
function ytds_cmd_stats(array $opts): int
{
    $campaign = $opts['campaign'] ?? null;
    if (!is_string($campaign) || !ctype_digit($campaign)) {
        return ytds_fail('INVALID_ARG', 'stats requires --campaign <id>', 'ytds stats --campaign 1 [--from DD.MM.YY --to DD.MM.YY]', 2);
    }
    $from = isset($opts['from']) ? (string)$opts['from'] : null;
    $to = isset($opts['to']) ? (string)$opts['to'] : null;
    $columns = ytds_csv($opts['columns'] ?? null);
    $groupby = ytds_csv($opts['groupby'] ?? null);

    if (($env = ytds_env($opts)) !== 'local') {
        return ytds_remote($env, 'stats', ytds_compact([
            'campaign' => $campaign, 'from' => $from, 'to' => $to,
            'columns' => $columns === [] ? null : implode(',', $columns),
            'groupby' => $groupby === [] ? null : implode(',', $groupby),
        ]));
    }
    return ytds_local($opts, static fn(AdminOps $ops): array => $ops->stats((int)$campaign, $from, $to, $columns, $groupby));
}

/** @param array<string, string|true|array<int, string>> $opts */
function ytds_cmd_clicks(array $opts): int
{
    $view = isset($opts['view']) ? (string)$opts['view'] : 'allowed';
    $campaign = $opts['campaign'] ?? null;
    if ($view !== 'trafficback' && (!is_string($campaign) || !ctype_digit($campaign))) {
        return ytds_fail('INVALID_ARG', 'clicks requires --campaign <id> unless --view trafficback', 'ytds clicks --campaign 1 [--view allowed|blocked|leads|trafficback] [--limit N] [--page N] [--sort field] [--dir asc|desc] [--filter field:op:value] [--param key] [--search term]', 2);
    }
    if (isset($opts['limit']) && !ctype_digit((string)$opts['limit'])) {
        return ytds_fail('INVALID_ARG', '--limit must be a positive integer', '', 2);
    }
    if (isset($opts['page']) && !ctype_digit((string)$opts['page'])) {
        return ytds_fail('INVALID_ARG', '--page must be a positive integer', '', 2);
    }
    $from = isset($opts['from']) ? (string)$opts['from'] : null;
    $to = isset($opts['to']) ? (string)$opts['to'] : null;
    $limit = isset($opts['limit']) ? (int)$opts['limit'] : 50;
    $full = isset($opts['full']);
    $filterOpts = ytds_click_filter_opts($opts);

    if (($env = ytds_env($opts)) !== 'local') {
        $params = ytds_compact([
            'campaign' => is_string($campaign) ? $campaign : null, 'view' => $view,
            'from' => $from, 'to' => $to, 'limit' => (string)$limit, 'full' => $full ? '1' : null,
            'page' => $filterOpts['page'] !== '1' ? $filterOpts['page'] : null,
            'sort' => $filterOpts['sort'] !== 'time' ? $filterOpts['sort'] : null,
            'dir' => $filterOpts['dir'] !== 'desc' ? $filterOpts['dir'] : null,
            'search' => $filterOpts['search'] !== '' ? $filterOpts['search'] : null,
            'filter-cond' => isset($opts['filter-cond']) ? (string)$opts['filter-cond'] : null,
        ]);
        if ($filterOpts['filter'] !== []) {
            $params['filter'] = $filterOpts['filter'];
        }
        if ($filterOpts['param'] !== []) {
            $params['param'] = $filterOpts['param'];
        }
        return ytds_remote($env, 'clicks', $params);
    }
    $campId = $view === 'trafficback' ? 0 : (int)$campaign;
    return ytds_local($opts, static fn(AdminOps $ops): array => $ops->clicks($campId, $view, $from, $to, $limit, $full, $filterOpts));
}

/**
 * Normalizes clicks filtering flags into the shape AdminOps::clicks reads. --filter and --param are
 * repeatable; the rest are scalars with defaults.
 * @param array<string, string|true|array<int, string>> $opts
 * @return array<string, mixed>
 */
function ytds_click_filter_opts(array $opts): array
{
    $asList = static function (mixed $v): array {
        $items = is_array($v) ? $v : (is_string($v) && $v !== '' ? [$v] : []);
        return array_values(array_filter(array_map('strval', $items), static fn(string $s): bool => $s !== ''));
    };
    return [
        'page' => isset($opts['page']) ? (string)$opts['page'] : '1',
        'sort' => isset($opts['sort']) ? (string)$opts['sort'] : 'time',
        'dir' => isset($opts['dir']) ? (string)$opts['dir'] : 'desc',
        'search' => isset($opts['search']) ? (string)$opts['search'] : '',
        'filter-cond' => isset($opts['filter-cond']) ? (string)$opts['filter-cond'] : 'AND',
        'filter' => $asList($opts['filter'] ?? []),
        'param' => $asList($opts['param'] ?? []),
    ];
}

/**
 * landing <list|upload|duplicate|delete> — landing-folder library under caching/landings.
 * @param array<int, string> $pos
 * @param array<string, string|true> $opts
 */
function ytds_cmd_landing(array $pos, array $opts): int
{
    $verb = $pos[1] ?? 'list';
    $env = ytds_env($opts);
    $commit = isset($opts['yes']);
    switch ($verb) {
        case 'list':
            if ($env !== 'local') {
                return ytds_remote($env, 'landing.list', []);
            }
            $dir = ytds_local_landings_dir();
            return ytds_local($opts, static fn(AdminOps $o): array => $o->landings($dir));
        case 'upload':
            $name = $pos[2] ?? '';
            $zip = isset($opts['zip']) ? (string)$opts['zip'] : '';
            if ($name === '' || $zip === '') {
                return ytds_fail('INVALID_ARG', 'landing upload needs a name and --zip <file>', 'ytds landing upload <name> --zip page.zip', 2);
            }
            if (!is_file($zip)) {
                return ytds_fail('INVALID_ARG', 'zip file not found: ' . $zip, 'ytds landing upload <name> --zip page.zip', 2);
            }
            if ($env !== 'local') {
                $bytes = file_get_contents($zip);
                if ($bytes === false) {
                    return ytds_fail('INVALID_ARG', 'cannot read zip file: ' . $zip, '', 2);
                }
                return ytds_remote($env, 'landing.upload', ytds_yes(['name' => $name], $commit), 'POST', $bytes);
            }
            $dir = ytds_local_landings_dir();
            return ytds_local($opts, static fn(AdminOps $o): array => $o->landingUpload($dir, $name, $zip, $commit));
        case 'duplicate':
            $from = $pos[2] ?? '';
            $to = $pos[3] ?? '';
            if ($from === '' || $to === '') {
                return ytds_fail('INVALID_ARG', 'landing duplicate needs <from> <to>', 'ytds landing duplicate old new', 2);
            }
            if ($env !== 'local') {
                return ytds_remote($env, 'landing.duplicate', ytds_yes(['from' => $from, 'to' => $to], $commit), 'POST');
            }
            $dir = ytds_local_landings_dir();
            return ytds_local($opts, static fn(AdminOps $o): array => $o->landingDuplicate($dir, $from, $to, $commit));
        case 'delete':
            $name = $pos[2] ?? '';
            if ($name === '') {
                return ytds_fail('INVALID_ARG', 'landing delete needs a name', 'ytds landing delete <name>', 2);
            }
            if ($env !== 'local') {
                return ytds_remote($env, 'landing.delete', ytds_yes(['name' => $name], $commit), 'POST');
            }
            $dir = ytds_local_landings_dir();
            return ytds_local($opts, static fn(AdminOps $o): array => $o->landingDelete($dir, $name, $commit));
        default:
            return ytds_fail('USAGE', 'unknown landing verb: ' . $verb, 'ytds landing <list|upload|duplicate|delete>', 2);
    }
}

/** @param array<string, string|true> $opts */
function ytds_yes(array $params, bool $commit): array
{
    if ($commit) {
        $params['commit'] = '1';
    }
    return $params;
}

/**
 * networks <list|add|update|delete> — global Networks library (common.settings).
 * @param array<int, string> $pos
 * @param array<string, string|true> $opts
 */
function ytds_cmd_networks(array $pos, array $opts): int
{
    $verb = $pos[1] ?? '';
    $env = ytds_env($opts);
    $commit = isset($opts['yes']);
    switch ($verb) {
        case 'list':
            if ($env !== 'local') {
                return ytds_remote($env, 'networks.list', []);
            }
            return ytds_local($opts, static fn(AdminOps $o): array => $o->networksList());
        case 'add':
            $name = isset($opts['name']) ? (string)$opts['name'] : '';
            if ($name === '') {
                return ytds_fail('INVALID_ARG', 'networks add requires --name', 'ytds networks add --name X --params "subid={clickid}"', 2);
            }
            $params = isset($opts['params']) ? (string)$opts['params'] : '';
            if ($env !== 'local') {
                return ytds_remote($env, 'networks.add', ytds_yes(['name' => $name, 'params' => $params], $commit), 'POST');
            }
            return ytds_local($opts, static fn(AdminOps $o): array => $o->networkAdd($name, $params, $commit));
        case 'update':
            $id = $pos[2] ?? '';
            if ($id === '') {
                return ytds_fail('INVALID_ARG', 'networks update needs an id', 'ytds networks update <id> [--name X] [--params Y]', 2);
            }
            $name = array_key_exists('name', $opts) ? (string)$opts['name'] : null;
            $params = array_key_exists('params', $opts) ? (string)$opts['params'] : null;
            if ($env !== 'local') {
                $p = ['id' => $id];
                if ($name !== null) {
                    $p['name'] = $name;
                }
                if ($params !== null) {
                    $p['params'] = $params;
                }
                return ytds_remote($env, 'networks.update', ytds_yes($p, $commit), 'POST');
            }
            return ytds_local($opts, static fn(AdminOps $o): array => $o->networkUpdate($id, $name, $params, $commit));
        case 'delete':
            $id = $pos[2] ?? '';
            if ($id === '') {
                return ytds_fail('INVALID_ARG', 'networks delete needs an id', 'ytds networks delete <id>', 2);
            }
            if ($env !== 'local') {
                return ytds_remote($env, 'networks.delete', ytds_yes(['id' => $id], $commit), 'POST');
            }
            return ytds_local($opts, static fn(AdminOps $o): array => $o->networkDelete($id, $commit));
        default:
            return ytds_fail('USAGE', 'unknown networks verb: ' . $verb, 'ytds networks <list|add|update|delete>', 2);
    }
}

/**
 * destinations <list|add|update|delete> — global Destinations library (common.settings).
 * @param array<int, string> $pos
 * @param array<string, string|true> $opts
 */
function ytds_cmd_destinations(array $pos, array $opts): int
{
    $verb = $pos[1] ?? '';
    $env = ytds_env($opts);
    $commit = isset($opts['yes']);
    switch ($verb) {
        case 'list':
            if ($env !== 'local') {
                return ytds_remote($env, 'destinations.list', []);
            }
            return ytds_local($opts, static fn(AdminOps $o): array => $o->destinations());
        case 'add':
            $name = isset($opts['name']) ? (string)$opts['name'] : '';
            $base = isset($opts['base-url']) ? (string)$opts['base-url'] : '';
            if ($name === '' || $base === '') {
                return ytds_fail('INVALID_ARG', 'destinations add requires --name and --base-url', 'ytds destinations add --name X --base-url checkout.com/a [--network <id>]', 2);
            }
            $network = isset($opts['network']) ? (string)$opts['network'] : null;
            if ($env !== 'local') {
                $p = ['name' => $name, 'base_url' => $base];
                if ($network !== null) {
                    $p['network_id'] = $network;
                }
                return ytds_remote($env, 'destinations.add', ytds_yes($p, $commit), 'POST');
            }
            return ytds_local($opts, static fn(AdminOps $o): array => $o->destinationAdd($name, $base, $network, $commit));
        case 'update':
            $id = $pos[2] ?? '';
            if ($id === '') {
                return ytds_fail('INVALID_ARG', 'destinations update needs an id', 'ytds destinations update <id> [--name][--base-url][--network]', 2);
            }
            $name = array_key_exists('name', $opts) ? (string)$opts['name'] : null;
            $base = array_key_exists('base-url', $opts) ? (string)$opts['base-url'] : null;
            $network = array_key_exists('network', $opts) ? (string)$opts['network'] : null;
            if ($env !== 'local') {
                $p = ['id' => $id];
                if ($name !== null) {
                    $p['name'] = $name;
                }
                if ($base !== null) {
                    $p['base_url'] = $base;
                }
                if ($network !== null) {
                    $p['network_id'] = $network;
                }
                return ytds_remote($env, 'destinations.update', ytds_yes($p, $commit), 'POST');
            }
            return ytds_local($opts, static fn(AdminOps $o): array => $o->destinationUpdate($id, $name, $base, $network, $commit));
        case 'delete':
            $id = $pos[2] ?? '';
            if ($id === '') {
                return ytds_fail('INVALID_ARG', 'destinations delete needs an id', 'ytds destinations delete <id>', 2);
            }
            if ($env !== 'local') {
                return ytds_remote($env, 'destinations.delete', ytds_yes(['id' => $id], $commit), 'POST');
            }
            return ytds_local($opts, static fn(AdminOps $o): array => $o->destinationDelete($id, $commit));
        default:
            return ytds_fail('USAGE', 'unknown destinations verb: ' . $verb, 'ytds destinations <list|add|update|delete>', 2);
    }
}

/** @param array<string, string|true> $opts */
function ytds_cmd_version(array $opts): int
{
    if (($env = ytds_env($opts)) !== 'local') {
        return ytds_remote($env, 'version', []);
    }
    // get_admin_dir() resolves the (possibly renamed) admin segment from settings; loaded by ytds_open_ops.
    return ytds_local($opts, static fn(AdminOps $ops): array => $ops->version(get_admin_dir() . '/version.txt'));
}

/** @param array<string, string|true> $opts */
function ytds_cmd_doctor(array $opts): int
{
    if (ytds_env($opts) !== 'local') {
        return ytds_fail('INVALID_ARG', 'doctor inspects the local environment only', 'omit --env or use --env local', 2);
    }
    $resolved = ytds_resolve_db(isset($opts['db']) ? (string)$opts['db'] : null);
    $checks = ytds_doctor(ytds_repo_root(), $resolved['path'], $resolved['exists']);
    $ok = !in_array('fail', array_column($checks, 'status'), true);
    ytds_emit(['ok' => $ok, 'checks' => $checks]);
    return $ok ? 0 : 1;
}

// ── Routing helpers ───────────────────────────────────────────────────────────

/** @param array<string, string|true> $opts */
function ytds_env(array $opts): string
{
    return isset($opts['env']) ? (string)$opts['env'] : 'local';
}

/**
 * Runs a read op against the local instance database and emits its payload.
 * @param array<string, string|true> $opts
 * @param callable(AdminOps): array<string, mixed> $fn
 */
function ytds_local(array $opts, callable $fn): int
{
    $resolved = ytds_resolve_db(isset($opts['db']) ? (string)$opts['db'] : null);
    if (!$resolved['exists']) {
        return ytds_db_not_found($resolved['path']);
    }
    $ops = ytds_open_ops($resolved['path']);
    try {
        $payload = $fn($ops);
    } catch (YtdsOpError $e) {
        return ytds_fail($e->errorCode, $e->getMessage(), $e->hint, ytds_exit_for_code($e->errorCode));
    }
    ytds_emit($payload);
    return 0;
}

/**
 * Proxies an action to a remote instance's admin API and mirrors its response.
 * @param array<string, string> $params
 */
function ytds_remote(string $env, string $action, array $params, string $method = 'GET', ?string $payload = null): int
{
    $cfg = ytds_env_config($env);
    if (isset($cfg['error'])) {
        [$code, $message, $hint] = $cfg['error'];
        return ytds_fail($code, $message, $hint, ytds_exit_for_code($code));
    }
    $resp = ytds_http_request($cfg['url'], $cfg['token'], array_merge(['action' => $action], $params), $method, $payload);
    if (isset($resp['transport'])) {
        return ytds_fail('TRANSPORT_ERROR', 'request to ' . $env . ' failed: ' . $resp['transport'], 'check the env url and connectivity', 1);
    }
    $decoded = json_decode($resp['body'], true);
    if ($resp['status'] === 200) {
        is_array($decoded) ? ytds_emit($decoded) : fwrite(STDOUT, rtrim($resp['body'], "\n") . "\n");
        return 0;
    }
    $code = is_array($decoded) && isset($decoded['code']) ? (string)$decoded['code'] : 'HTTP_' . $resp['status'];
    $message = is_array($decoded) && isset($decoded['message']) ? (string)$decoded['message'] : trim($resp['body']);
    $hint = is_array($decoded) && isset($decoded['hint']) ? (string)$decoded['hint'] : '';
    return ytds_fail($code, $message, $hint, ytds_exit_for_code($code, $resp['status']));
}

function ytds_exit_for_code(string $code, int $httpStatus = 0): int
{
    static $map = [
        'AUTH_INVALID' => 4, 'API_DISABLED' => 4, 'AUTH_MISSING' => 4, 'CONFIG_MISSING' => 4, 'CONFIG_INVALID' => 4,
        'CAMPAIGN_NOT_FOUND' => 3, 'SECTION_NOT_FOUND' => 3, 'DB_NOT_FOUND' => 3,
        'INVALID_ARG' => 2, 'UNKNOWN_ACTION' => 2, 'USAGE' => 2, 'METHOD_NOT_ALLOWED' => 2, 'VALIDATION' => 2,
        'DOMAIN_CONFLICT' => 5,
        'NETWORK_NOT_FOUND' => 3, 'DESTINATION_NOT_FOUND' => 3, 'LANDING_NOT_FOUND' => 3,
        'SETTINGS_CORRUPT' => 1, 'INTERNAL' => 1, 'TRANSPORT_ERROR' => 1, 'WRITE_FAILED' => 1,
    ];
    if (isset($map[$code])) {
        return $map[$code];
    }
    return match (true) {
        $httpStatus === 401 || $httpStatus === 403 => 4,
        $httpStatus === 404 => 3,
        $httpStatus >= 400 && $httpStatus < 500 => 2,
        default => 1,
    };
}

/**
 * @param mixed $raw
 * @return array<int, string>
 */
function ytds_csv(mixed $raw): array
{
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn(string $s): bool => $s !== ''));
}

/**
 * Drops null values so optional query params are omitted rather than sent empty.
 * @param array<string, string|null> $params
 * @return array<string, string>
 */
function ytds_compact(array $params): array
{
    return array_filter($params, static fn(?string $v): bool => $v !== null);
}

function ytds_local_landings_dir(): string
{
    $dir = realpath(ytds_repo_root() . '/code/' . get_cache_path('landings'));
    return $dir === false ? ytds_repo_root() . '/code/' . get_cache_path('landings') : $dir;
}

// ── Output ─────────────────────────────────────────────────────────────────

/** @param array<string, mixed> $payload */
function ytds_emit(array $payload): void
{
    fwrite(STDOUT, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}

function ytds_fail(string $code, string $message, string $hint, int $exit): int
{
    fwrite(STDERR, json_encode(
        ['code' => $code, 'message' => $message, 'hint' => $hint],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ) . "\n");
    return $exit;
}

function ytds_db_not_found(string $path): int
{
    return ytds_fail(
        'DB_NOT_FOUND',
        'database not found: ' . $path,
        'pass --db <path> or create the local db by opening the panel once (AGENTS.md §5)',
        3
    );
}
