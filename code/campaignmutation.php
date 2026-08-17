<?php

require_once __DIR__ . '/campaign.php'; // MvtSettings::valueCode() used by normalize_mvt_input

/**
 * Pure campaign-settings normalizers + recursive merge, shared by the panel save path
 * (code/admin/campeditor.php) and the CLI/API (CampaignService::patch). Lives in code/ root — not
 * code/admin/ — because the installer renames admin/ to a hex path on the instance, so engine code
 * that must run under both layouts cannot require it from admin/. No $_REQUEST, no echo, no exit.
 */

function normalize_item_weights(array &$items): void {
    if ($items === []) {
        return;
    }
    $weights = array_map(
        static fn(array $item): int => max(0, (int)($item['weight'] ?? 0)),
        $items
    );
    $total = array_sum($weights);
    $count = count($weights);
    if ($total <= 0) {
        $base = intdiv(100, $count);
        $remainder = 100 - $base * $count;
        $result = array_fill(0, $count, $base);
        for ($i = 0; $i < $remainder; $i++) $result[$i]++;
        foreach ($items as $index => &$item) {
            $item['weight'] = $result[$index];
        }
        unset($item);
        return;
    }
    if ($total === 100) {
        foreach ($items as $index => &$item) {
            $item['weight'] = $weights[$index];
        }
        unset($item);
        return;
    }
    $exact = array_map(fn($w) => $w / $total * 100, $weights);
    $floored = array_map('floor', $exact);
    $remainders = [];
    for ($i = 0; $i < $count; $i++) {
        $remainders[$i] = $exact[$i] - $floored[$i];
    }
    $diff = 100 - (int)array_sum($floored);
    arsort($remainders);
    foreach (array_keys($remainders) as $idx) {
        if ($diff <= 0) break;
        $floored[$idx]++;
        $diff--;
    }
    foreach ($items as $index => &$item) {
        $item['weight'] = (int)$floored[$index];
    }
    unset($item);
}

function normalize_flow_input(array &$input, array $current): ?string
{
    if (!array_key_exists('black', $input)) {
        return null;
    }
    if (!is_array($input['black'])) {
        return 'Black settings must be an object.';
    }
    if (!array_key_exists('flows', $input['black'])) {
        return null;
    }
    $flows = &$input['black']['flows'];
    if (!is_array($flows) || !array_is_list($flows)) {
        return 'Flows must be a list.';
    }
    $currentFlows = is_array($current['black']['flows'] ?? null)
        ? $current['black']['flows']
        : [];

    foreach ($flows as $flowIndex => &$flow) {
        if (!is_array($flow)) {
            return 'Flow #' . ($flowIndex + 1) . ' must be an object.';
        }
        $steps = &$flow['steps'];
        if (!is_array($steps) || !array_is_list($steps)) {
            return 'Flow #' . ($flowIndex + 1) . ' steps must be a list.';
        }
        foreach ($steps as $stepIndex => &$step) {
            if (!is_array($step)) {
                return 'Step #' . ($stepIndex + 1) . ' must be an object.';
            }
            $action = (string)($step['action'] ?? 'folder');
            if (!in_array($action, ['folder', 'redirect'], true)) {
                return 'Invalid action in Step #' . ($stepIndex + 1) . '.';
            }
            $step['action'] = $action;

            if ($action === 'folder') {
                $folders = &$step['folders'];
                if (!is_array($folders) || !array_is_list($folders)) {
                    return 'Folders in Step #' . ($stepIndex + 1) . ' must be a list.';
                }
                $seenNames = [];
                foreach ($folders as $folderIndex => &$folder) {
                    if (!is_array($folder)) {
                        return 'Folder #' . ($folderIndex + 1) . ' must be an object.';
                    }
                    $name = trim((string)($folder['name'] ?? ''));
                    if ($name === '') {
                        return 'Folder name cannot be empty.';
                    }
                    if (isset($seenNames[$name])) {
                        return "Folder '{$name}' cannot be added twice to the same Step.";
                    }
                    $seenNames[$name] = true;
                    $folder['name'] = $name;
                    $loadtype = (string)($folder['loadtype'] ?? 'base');
                    $folder['loadtype'] = in_array($loadtype, ['base', 'direct'], true) ? $loadtype : 'base';
                    $folder['weight'] = max(0, (int)($folder['weight'] ?? 0));

                    $currentFolder = null;
                    foreach (
                        ($currentFlows[$flowIndex]['steps'][$stepIndex]['folders'] ?? [])
                        as $candidate
                    ) {
                        if (is_array($candidate) && ($candidate['name'] ?? '') === $name) {
                            $currentFolder = $candidate;
                            break;
                        }
                    }
                    $mvtError = normalize_mvt_input(
                        $folder['mvt'],
                        is_array($currentFolder['mvt'] ?? null)
                            ? $currentFolder['mvt']
                            : [],
                        $name
                    );
                    if ($mvtError !== null) {
                        return $mvtError;
                    }

                    $linksError = normalize_links_input($folder['links'], $name);
                    if ($linksError !== null) {
                        return $linksError;
                    }
                }
                unset($folder);
                normalize_item_weights($folders);
                $step['redirect'] = [
                    'urls' => [],
                    'type' => (int)($step['redirect']['type'] ?? 302),
                ];
            } else {
                $redirect = &$step['redirect'];
                if (!is_array($redirect)) {
                    return 'Redirect settings must be an object.';
                }
                $urls = &$redirect['urls'];
                if (!is_array($urls) || !array_is_list($urls)) {
                    return 'Redirect URLs must be a list.';
                }
                foreach ($urls as $urlIndex => &$url) {
                    if (!is_array($url)) {
                        return 'Redirect #' . ($urlIndex + 1) . ' must be an object.';
                    }
                    $rawUrl = trim((string)($url['url'] ?? ''));
                    if (filter_var($rawUrl, FILTER_VALIDATE_URL) === false) {
                        return 'Redirect #' . ($urlIndex + 1) . ' must be a valid URL.';
                    }
                    $host = (string)(parse_url($rawUrl, PHP_URL_HOST) ?: 'redirect');
                    $url = [
                        'url' => $rawUrl,
                        'label' => preg_replace('/^www\./', '', $host),
                        'weight' => max(0, (int)($url['weight'] ?? 0)),
                    ];
                }
                unset($url);
                normalize_item_weights($urls);
                $redirect['type'] = in_array(
                    (int)($redirect['type'] ?? 302),
                    [301, 302, 303, 307],
                    true
                ) ? (int)$redirect['type'] : 302;
                $step['folders'] = [];
            }
        }
        unset($step);
    }
    unset($flow);
    return null;
}

/**
 * Validates and rewrites one landing's {link:N} destinations in place. Tolerant of a missing or
 * malformed value (becomes an empty list), but strict on each entry: n>=1 (mirrors the read
 * layer so nothing accepted here is silently dropped on the next load), no duplicate n, and a
 * real http(s) URL. Empty-url slots are dropped silently, matching how blank folders/redirects
 * are pruned elsewhere.
 *
 * @param mixed $links passed by reference; rewritten to a clean list of {n, url}
 */
function normalize_links_input(&$links, string $folderName): ?string
{
    if (!is_array($links) || !array_is_list($links)) {
        $links = [];
        return null;
    }
    if (count($links) > 20) {
        return "Landing '{$folderName}': at most 20 destinations.";
    }
    $seen = [];
    $clean = [];
    foreach ($links as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $url = trim((string)($entry['url'] ?? ''));
        if ($url === '') {
            continue;
        }
        $n = (int)($entry['n'] ?? 0);
        if ($n < 1) {
            return "Landing '{$folderName}': destination number must be >= 1.";
        }
        if (isset($seen[$n])) {
            return "Landing '{$folderName}': destination {link:{$n}} defined twice.";
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', $url)) {
            return "Landing '{$folderName}': destination {link:{$n}} must be a valid http(s) URL.";
        }
        $seen[$n] = true;
        $clean[] = ['n' => $n, 'url' => $url];
    }
    $links = $clean;
    return null;
}

function normalize_mvt_input(mixed &$raw, array $current, string $folderName): ?string
{
    if (!is_array($raw)) {
        $raw = ['enabled' => false, 'tests' => []];
    }
    $raw['enabled'] = filter_var($raw['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $tests = &$raw['tests'];
    if (!is_array($tests) || !array_is_list($tests)) {
        return "MVT tests for '{$folderName}' must be a list.";
    }
    $currentTests = is_array($current['tests'] ?? null) ? $current['tests'] : [];
    if (count($tests) < count($currentTests)) {
        return "Existing MVT tests for '{$folderName}' cannot be removed.";
    }

    foreach ($tests as $testIndex => &$test) {
        if (!is_array($test)) {
            return 'TEST' . ($testIndex + 1) . " for '{$folderName}' must be an object.";
        }
        $test['active'] = filter_var($test['active'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $values = &$test['values'];
        if (!is_array($values) || !array_is_list($values)) {
            return 'TEST' . ($testIndex + 1) . ' Values must be a list.';
        }
        $currentTest = is_array($currentTests[$testIndex] ?? null)
            ? $currentTests[$testIndex]
            : null;
        if ($currentTest !== null) {
            if (empty($currentTest['active']) && $test['active']) {
                return 'Archived TEST' . ($testIndex + 1) . ' cannot be restored.';
            }
            $currentValues = is_array($currentTest['values'] ?? null)
                ? $currentTest['values']
                : [];
            if (count($values) < count($currentValues)) {
                return 'Existing Values in TEST' . ($testIndex + 1) . ' cannot be removed.';
            }
        } else {
            $currentValues = [];
        }

        $activeValueCount = 0;
        foreach ($values as $valueIndex => &$value) {
            if (!is_array($value)) {
                return 'Value ' . MvtSettings::valueCode($valueIndex)
                    . ' in TEST' . ($testIndex + 1) . ' must be an object.';
            }
            $value['active'] = filter_var($value['active'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $value['value'] = (string)($value['value'] ?? '');
            if ($value['active']) {
                $activeValueCount++;
            }
            $currentValue = is_array($currentValues[$valueIndex] ?? null)
                ? $currentValues[$valueIndex]
                : null;
            if ($currentValue === null) {
                continue;
            }
            if ((string)($currentValue['value'] ?? '') !== $value['value']) {
                return 'Saved Value ' . MvtSettings::valueCode($valueIndex)
                    . ' in TEST' . ($testIndex + 1) . ' cannot be edited.';
            }
            if (empty($currentValue['active']) && $value['active']) {
                return 'Archived Value ' . MvtSettings::valueCode($valueIndex)
                    . ' in TEST' . ($testIndex + 1) . ' cannot be restored.';
            }
        }
        unset($value);
        if ($raw['enabled'] && $test['active'] && $activeValueCount === 0) {
            return 'TEST' . ($testIndex + 1) . ' must have at least one active Value.';
        }
    }
    unset($test);
    return null;
}

function mergeSettingsRecursive($current, $incoming) {
    if (!is_array($incoming)) {
        if ($incoming === 'false' || $incoming === 'true') {
            return filter_var($incoming, FILTER_VALIDATE_BOOLEAN);
        }
        return $incoming;
    }

    if (array_is_list($incoming)) {
        return compactListRecursive($incoming);
    }

    if (!is_array($current) || array_is_list($current)) {
        $current = [];
    }

    foreach ($incoming as $key => $value) {
        $current[$key] = mergeSettingsRecursive($current[$key] ?? null, $value);
    }

    return $current;
}

function compactListRecursive(array $list): array {
    $result = [];
    foreach ($list as $value) {
        if (is_array($value)) {
            $result[] = array_is_list($value)
                ? compactListRecursive($value)
                : mergeSettingsRecursive([], $value);
            continue;
        }

        if ($value === 'false' || $value === 'true') {
            $result[] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            continue;
        }

        $result[] = $value;
    }

    return $result;
}
