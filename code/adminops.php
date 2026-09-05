<?php

require_once __DIR__ . '/campaignservice.php';
require_once __DIR__ . '/destinations.php';
require_once __DIR__ . '/networks.php';
require_once __DIR__ . '/landings.php';
require_once __DIR__ . '/costimport.php';

/**
 * Loads a versioned campaign template (code/templates/<name>.json) as a settings array. Templates
 * are the safe, git-tracked alternative to the author's default.json. Name is charset-guarded so it
 * can never escape the templates directory.
 *
 * @return array<string, mixed>
 */
function ytds_load_campaign_template(string $codeDir, string $name): array
{
    if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name) !== 1) {
        throw new YtdsOpError('INVALID_ARG', 400, 'invalid template name: ' . $name, 'use letters, digits, _ or -');
    }
    $path = $codeDir . '/templates/' . $name . '.json';
    $raw = is_file($path) ? file_get_contents($path) : false;
    if ($raw === false) {
        throw new YtdsOpError('INVALID_ARG', 400, 'unknown template: ' . $name, 'available templates live in code/templates/');
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new YtdsOpError('INTERNAL', 500, 'template is not valid JSON: ' . $name, '');
    }
    return $decoded;
}

/**
 * Read-only operations shared by the ytds CLI (local mode) and code/api/admin.php
 * (remote mode). Every method returns the FINAL response payload — the same JSON both
 * ports emit — or throws YtdsOpError. No echo, no exit, no $_REQUEST reads (except the
 * $_GET bridge for the legacy Dates helper, isolated in resolveRange()).
 *
 * Secrets are never returned: campaign settings pass through CampaignService::redact();
 * operational data (clicks, destinations) is returned to the authenticated operator by
 * design.
 */
final class AdminOps
{
    /** Default aggregate metrics for `stats` when the caller does not override them. */
    public const DEFAULT_STATS_COLUMNS = ['clicks', 'uniques', 'conversion', 'revenue', 'costs', 'profit', 'roi', 'epc'];

    /** Narrow click projection; --full returns the whole row. */
    public const CLICK_NARROW_COLUMNS = ['time', 'clickid', 'country', 'device', 'network_id', 'network', 'status', 'payout'];

    public const CLICK_VIEWS = ['allowed', 'blocked', 'leads', 'trafficback'];

    private CampaignService $campaigns;

    public function __construct(private readonly Db $db)
    {
        $this->campaigns = new CampaignService($db);
    }

    /** @return array{version: string, php: string} */
    public function version(string $versionFile): array
    {
        $raw = @file_get_contents($versionFile);
        return [
            'version' => $raw === false ? '' : trim($raw),
            'php' => PHP_VERSION,
        ];
    }

    /** @return array{campaigns: array<int, array<string, mixed>>, count: int} */
    public function campaignsList(): array
    {
        try {
            $rows = $this->campaigns->list();
        } catch (RuntimeException $e) {
            throw new YtdsOpError('SETTINGS_CORRUPT', 500, $e->getMessage(), 'inspect campaigns.settings JSON');
        }
        return ['campaigns' => $rows, 'count' => count($rows)];
    }

    /**
     * Narrow projection by default; --section drills a dot-path; --full dumps redacted settings.
     * @return array<string, mixed>
     */
    public function campaignGet(int $id, ?string $section, bool $full): array
    {
        $campaign = $this->safeGet($id);
        if ($campaign === null) {
            throw new YtdsOpError('CAMPAIGN_NOT_FOUND', 404, 'campaign ' . $id . ' not found', 'ytds campaigns list');
        }
        if ($section !== null && $section !== '') {
            $value = $campaign['settings'];
            foreach (explode('.', $section) as $key) {
                if (!is_array($value) || !array_key_exists($key, $value)) {
                    throw new YtdsOpError(
                        'SECTION_NOT_FOUND',
                        404,
                        'section not found: ' . $section,
                        'top-level sections: ' . implode(', ', array_keys($campaign['settings']))
                    );
                }
                $value = $value[$key];
            }
            return ['id' => $campaign['id'], 'section' => $section, 'value' => $value];
        }
        if ($full) {
            return $campaign;
        }
        return $this->campaigns->summary($campaign['id'], $campaign['name'], $campaign['settings']);
    }

    /**
     * Aggregate statistics for one campaign over a date window (campaign timezone).
     * @param array<int, string> $columns  metric field names; [] uses DEFAULT_STATS_COLUMNS
     * @param array<int, string> $groupby   group-by fields; [] returns a single total row
     * @return array<string, mixed>
     */
    public function stats(int $campId, ?string $from, ?string $to, array $columns, array $groupby): array
    {
        $settings = $this->requireCampaignSettings($campId);
        $tz = $this->campaignTimezone($settings);
        [$start, $end] = $this->resolveRange($tz, $from, $to);
        $cols = $columns === [] ? self::DEFAULT_STATS_COLUMNS : array_values($columns);

        $rows = $this->db->get_statistics($cols, array_values($groupby), $campId, (string)$start, (string)$end, $tz);

        return [
            'campaign' => $campId,
            'timezone' => $tz,
            'from' => $start,
            'to' => $end,
            'columns' => $cols,
            'groupby' => array_values($groupby),
            'rows' => $rows,
        ];
    }

    /**
     * Recent clicks for one campaign (or global trafficback), newest first, narrow columns.
     * @return array<string, mixed>
     */
    public function clicks(int $campId, string $view, ?string $from, ?string $to, int $limit, bool $full, array $filterOpts = []): array
    {
        if (!in_array($view, self::CLICK_VIEWS, true)) {
            throw new YtdsOpError('INVALID_ARG', 400, 'unknown clicks view: ' . $view, 'views: ' . implode(', ', self::CLICK_VIEWS));
        }
        $limit = max(1, min(500, $limit));
        $page = max(1, (int)($filterOpts['page'] ?? 1));
        $sortField = trim((string)($filterOpts['sort'] ?? 'time')) ?: 'time';
        $sortDir = strtolower(trim((string)($filterOpts['dir'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
        $search = (string)($filterOpts['search'] ?? '');
        $paramColumns = self::clickParamColumns((array)($filterOpts['param'] ?? []));
        $filters = self::buildClickFilters((array)($filterOpts['filter'] ?? []), (string)($filterOpts['filter-cond'] ?? 'AND'));

        if ($view === 'trafficback') {
            $tz = $this->campaignTimezone($this->db->get_common_settings());
            $campParam = null;
        } else {
            $tz = $this->campaignTimezone($this->requireCampaignSettings($campId));
            $campParam = $campId;
        }
        [$start, $end] = $this->resolveRange($tz, $from, $to);

        $pageData = $this->db->get_clicks_paginated($view, $start, $end, $campParam, $page, $limit, $sortField, $sortDir, $filters, $paramColumns, $search);
        $data = $pageData['data'] ?? [];
        $rows = $full ? $data : array_map(static fn(array $c): array => self::narrowClick($c, $paramColumns), $data);

        return [
            'campaign' => $campParam,
            'view' => $view,
            'timezone' => $tz,
            'from' => $start,
            'to' => $end,
            'page' => $page,
            'sort' => $sortField,
            'dir' => $sortDir,
            'filters' => $filters === [] ? [] : $filters['rules'],
            'search' => $search,
            'param_columns' => $paramColumns,
            'last_page' => (int)($pageData['last_page'] ?? 1),
            'count' => count($rows),
            'clicks' => $rows,
        ];
    }

    /**
     * Previews or applies campaign/day Meta spend to allowed clicks matched by exact
     * `params.utm_campaign`.
     *
     * @param array{source?: array<string, mixed>, rows?: array<int, array<string, mixed>>} $manifest
     * @return array<string, mixed>
     */
    public function costsImport(array $manifest, bool $commit): array
    {
        $input = $manifest['rows'] ?? null;
        if (!is_array($input) || $input === []) {
            throw new YtdsOpError('INVALID_ARG', 400, 'cost import requires report rows', 'use costs import --file report.csv');
        }
        if (count($input) > MetaCostCsv::MAX_ROWS) {
            throw new YtdsOpError('INVALID_ARG', 400, 'cost import exceeds the 10000-row limit', 'split the report into smaller files');
        }

        $sourceRows = count($input);
        $combined = [];
        foreach ($input as $raw) {
            if (!is_array($raw)) {
                throw new YtdsOpError('INVALID_ARG', 400, 'cost import row must be an object', '');
            }
            $date = $raw['date'] ?? null;
            $utm = $raw['utm_campaign'] ?? null;
            $currency = $raw['currency'] ?? null;
            $rowSpend = $raw['spend_cents'] ?? null;
            $metaClicks = $raw['meta_link_clicks'] ?? null;
            $parsedDate = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
            if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $date) {
                throw new YtdsOpError('INVALID_ARG', 400, 'cost import row has an invalid date', 'expected YYYY-MM-DD');
            }
            if (!is_string($utm) || $utm === '' || strlen($utm) > 512 || preg_match('/[\x00-\x1F\x7F]/', $utm) === 1) {
                throw new YtdsOpError('INVALID_ARG', 400, 'cost import row has an invalid utm_campaign', 'use the exact non-empty Meta campaign name');
            }
            if ($currency !== 'USD') {
                throw new YtdsOpError('INVALID_ARG', 400, 'cost import row currency must be USD', 'export or convert the Meta report to USD');
            }
            if (!is_int($rowSpend) || $rowSpend < 0 || $rowSpend > MetaCostCsv::MAX_SPEND_CENTS) {
                throw new YtdsOpError('INVALID_ARG', 400, 'cost import row has invalid spend_cents', 'use a non-negative integer amount in USD cents');
            }
            if ($metaClicks !== null && (!is_int($metaClicks) || $metaClicks < 0)) {
                throw new YtdsOpError('INVALID_ARG', 400, 'cost import row has invalid meta_link_clicks', 'use a non-negative integer or null');
            }
            $key = $date . "\0" . $utm;
            if (!isset($combined[$key])) {
                $combined[$key] = $raw;
                continue;
            }
            $combinedSpend = (int)($combined[$key]['spend_cents'] ?? 0) + (int)($raw['spend_cents'] ?? 0);
            if ($combinedSpend > MetaCostCsv::MAX_SPEND_CENTS) {
                throw new YtdsOpError('INVALID_ARG', 400, 'combined cost import spend exceeds the safety limit', 'split or verify duplicate campaign/day rows');
            }
            $combined[$key]['spend_cents'] = $combinedSpend;
            $leftClicks = $combined[$key]['meta_link_clicks'] ?? null;
            $rightClicks = $raw['meta_link_clicks'] ?? null;
            $combined[$key]['meta_link_clicks'] = $leftClicks === null || $rightClicks === null
                ? ($leftClicks ?? $rightClicks)
                : (int)$leftClicks + (int)$rightClicks;
            if ((string)($combined[$key]['meta_campaign_id'] ?? '') !== (string)($raw['meta_campaign_id'] ?? '')) {
                $combined[$key]['meta_campaign_id'] = '';
            }
        }
        $input = array_values($combined);

        $campaigns = $this->campaigns->list();
        $rows = [];
        $groups = [];
        $spendCents = 0;
        $matchedClicks = 0;
        foreach ($input as $raw) {
            if (!is_array($raw)) {
                throw new YtdsOpError('INVALID_ARG', 400, 'cost import row must be an object', '');
            }
            $date = (string)($raw['date'] ?? '');
            $utm = (string)($raw['utm_campaign'] ?? '');
            $rowSpend = (int)($raw['spend_cents'] ?? -1);
            $matches = [];
            foreach ($campaigns as $campaign) {
                $settings = $this->requireCampaignSettings((int)$campaign['id']);
                $tz = $this->campaignTimezone($settings);
                $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone($tz));
                if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                    throw new YtdsOpError('INVALID_ARG', 400, 'invalid cost row date: ' . $date, 'expected YYYY-MM-DD');
                }
                $from = $parsed->setTime(0, 0)->getTimestamp();
                $to = $parsed->setTime(23, 59, 59)->getTimestamp();
                $stat = $this->db->get_click_cost_group_summary((int)$campaign['id'], $from, $to, $utm);
                if ($stat['clicks'] > 0) {
                    $matches[] = ['campaign' => $campaign, 'timezone' => $tz, 'stat' => $stat];
                }
            }
            if (count($matches) > 1) {
                throw new YtdsOpError('COST_MATCH_AMBIGUOUS', 409, 'cost row matches multiple TDS campaigns: ' . $utm . ' on ' . $date, 'make utm_campaign unique across TDS campaigns');
            }
            $match = $matches[0] ?? null;
            $clicks = (int)($match['stat']['clicks'] ?? 0);
            $groupIndex = null;
            if ($match !== null) {
                $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone($match['timezone']));
                $groupIndex = count($groups);
                $groups[] = [
                    'campaign_id' => (int)$match['campaign']['id'],
                    'start' => $parsed->setTime(0, 0)->getTimestamp(),
                    'end' => $parsed->setTime(23, 59, 59)->getTimestamp(),
                    'utm_campaign' => $utm,
                    'spend_cents' => $rowSpend,
                ];
            }
            $spendCents += $rowSpend;
            $matchedClicks += $clicks;
            $rows[] = [
                'date' => $date,
                'utm_campaign' => $utm,
                'meta_campaign_id' => (string)($raw['meta_campaign_id'] ?? ''),
                'currency' => 'USD',
                'spend' => self::formatCents($rowSpend),
                'meta_link_clicks' => $raw['meta_link_clicks'] ?? null,
                'tds_campaign_id' => $match === null ? null : (int)$match['campaign']['id'],
                'tds_campaign_name' => $match === null ? null : (string)$match['campaign']['name'],
                'timezone' => $match['timezone'] ?? null,
                'matched_clicks' => $clicks,
                'matched_uniques' => (int)($match['stat']['uniques'] ?? 0),
                'current_cost' => number_format((float)($match['stat']['costs'] ?? 0), 2, '.', ''),
                '_group_index' => $groupIndex,
            ];
        }

        $matchedRows = count(array_filter($rows, static fn(array $row): bool => $row['matched_clicks'] > 0));
        if ($commit && $matchedRows !== count($rows)) {
            throw new YtdsOpError('COST_IMPORT_NOT_READY', 409, 'cost import has unmatched rows; nothing was written', 'run without --yes and fix every unmatched date + utm_campaign');
        }
        $reconciled = $this->db->reconcile_click_cost_groups($groups, $commit);
        $changedClicks = 0;
        $costBefore = 0.0;
        $costAfter = 0.0;
        foreach ($rows as &$row) {
            $index = $row['_group_index'];
            unset($row['_group_index']);
            if ($index === null) {
                $row['changed_clicks'] = 0;
                continue;
            }
            $change = $reconciled[$index];
            $row['changed_clicks'] = $change['changed_clicks'];
            $row['cost_after'] = number_format($change['cost_after'], 2, '.', '');
            $changedClicks += $change['changed_clicks'];
            $costBefore += $change['cost_before'];
            $costAfter += $change['cost_after'];
        }
        unset($row);
        return [
            'dry_run' => !$commit,
            'action' => 'costs.import',
            'ready' => $matchedRows === count($rows),
            'source' => $manifest['source'] ?? [],
            'summary' => [
                'source_rows' => $sourceRows,
                'input_rows' => count($rows),
                'matched_rows' => $matchedRows,
                'unmatched_rows' => count($rows) - $matchedRows,
                'matched_clicks' => $matchedClicks,
                'changed_clicks' => $changedClicks,
                'spend' => self::formatCents($spendCents),
                'cost_before' => number_format($costBefore, 2, '.', ''),
                'cost_after' => number_format($costAfter, 2, '.', ''),
            ],
            'rows' => $rows,
        ];
    }

    /** @return array{landings: array<int, array<string, mixed>>, count: int} */
    public function landings(string $landingsDir): array
    {
        $rows = (new LandingLibrary($landingsDir))->all();
        return ['landings' => $rows, 'count' => count($rows)];
    }

    /**
     * Global destinations with their network resolved to an effective URL (base-only when the
     * network reference is missing — the same graceful degrade the step editor uses).
     * @return array{destinations: array<int, array<string, mixed>>, count: int}
     */
    public function destinations(): array
    {
        $common = $this->db->get_common_settings();
        $rawDest = is_array($common['destinations'] ?? null) ? $common['destinations'] : [];
        $rawNet = is_array($common['networks'] ?? null) ? $common['networks'] : [];
        $networksById = DestinationLibrary::indexNetworks($rawNet);

        $out = [];
        foreach ($rawDest as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $dest = Destination::fromArray($raw);
            $network = $networksById[$dest->networkId] ?? null;
            $out[] = [
                'id' => $dest->id,
                'name' => $dest->name,
                'base_url' => $dest->baseUrl,
                'network_id' => $dest->networkId,
                'network_name' => $network?->name,
                'network_missing' => $dest->networkId !== '' && $network === null,
                'effective_url' => DestinationLibrary::effectiveUrl($dest, $networksById),
            ];
        }
        return ['destinations' => $out, 'count' => count($out)];
    }

    // ── Networks & Destinations libraries (global, in common.settings) ──

    /** @return array{networks: array<int, array<string, mixed>>, count: int} */
    public function networksList(): array
    {
        $common = $this->db->get_common_settings();
        $rows = is_array($common['networks'] ?? null) ? array_values($common['networks']) : [];
        return ['networks' => $rows, 'count' => count($rows)];
    }

    /** @return array<string, mixed> */
    public function networkAdd(string $name, string $params, bool $commit): array
    {
        if (trim($name) === '') {
            throw new YtdsOpError('INVALID_ARG', 400, 'network name is required', '');
        }
        $common = $this->db->get_common_settings();
        $raw = is_array($common['networks'] ?? null) ? $common['networks'] : [];
        $raw[] = ['name' => $name, 'params' => $params];
        $clean = NetworkLibrary::sanitize($raw, $this->idGen());
        if ($commit) {
            $common['networks'] = $clean;
            $this->saveCommon($common, 'networks');
        }
        return ['dry_run' => !$commit, 'action' => 'network.add', 'network' => end($clean) ?: [], 'count' => count($clean)];
    }

    /** @return array<string, mixed> */
    public function networkUpdate(string $id, ?string $name, ?string $params, bool $commit): array
    {
        $common = $this->db->get_common_settings();
        $raw = is_array($common['networks'] ?? null) ? $common['networks'] : [];
        $found = false;
        foreach ($raw as &$n) {
            if (is_array($n) && (string)($n['id'] ?? '') === $id) {
                if ($name !== null) {
                    $n['name'] = $name;
                }
                if ($params !== null) {
                    $n['params'] = $params;
                }
                $found = true;
                break;
            }
        }
        unset($n);
        if (!$found) {
            throw new YtdsOpError('NETWORK_NOT_FOUND', 404, 'network not found: ' . $id, 'ytds networks list');
        }
        $clean = NetworkLibrary::sanitize($raw, $this->idGen());
        if ($commit) {
            $common['networks'] = $clean;
            $this->saveCommon($common, 'networks');
        }
        return ['dry_run' => !$commit, 'action' => 'network.update', 'network' => $this->findById($clean, $id)];
    }

    /** @return array<string, mixed> */
    public function networkDelete(string $id, bool $commit): array
    {
        $common = $this->db->get_common_settings();
        $raw = is_array($common['networks'] ?? null) ? $common['networks'] : [];
        $kept = array_values(array_filter($raw, static fn($n): bool => !(is_array($n) && (string)($n['id'] ?? '') === $id)));
        if (count($kept) === count($raw)) {
            throw new YtdsOpError('NETWORK_NOT_FOUND', 404, 'network not found: ' . $id, 'ytds networks list');
        }
        $this->assertRemovedLibraryIdsUnused('network', $raw, $kept);
        $clean = NetworkLibrary::sanitize($kept, $this->idGen());
        if ($commit) {
            $common['networks'] = $clean;
            $this->saveCommon($common, 'networks');
        }
        return ['dry_run' => !$commit, 'action' => 'network.delete', 'id' => $id, 'count' => count($clean)];
    }

    /** @return array<string, mixed> */
    public function destinationAdd(string $name, string $baseUrl, ?string $networkId, bool $commit): array
    {
        if (trim($name) === '' || trim($baseUrl) === '') {
            throw new YtdsOpError('INVALID_ARG', 400, 'destination name and base URL are required', '');
        }
        $common = $this->db->get_common_settings();
        $raw = is_array($common['destinations'] ?? null) ? $common['destinations'] : [];
        $raw[] = ['name' => $name, 'base_url' => $baseUrl, 'network_id' => $networkId ?? ''];
        $clean = DestinationLibrary::sanitize($raw, $this->idGen());
        if ($commit) {
            $common['destinations'] = $clean;
            $this->saveCommon($common, 'destinations');
        }
        return ['dry_run' => !$commit, 'action' => 'destination.add', 'destination' => end($clean) ?: [], 'count' => count($clean)];
    }

    /** @return array<string, mixed> */
    public function destinationUpdate(string $id, ?string $name, ?string $baseUrl, ?string $networkId, bool $commit): array
    {
        $common = $this->db->get_common_settings();
        $raw = is_array($common['destinations'] ?? null) ? $common['destinations'] : [];
        $before = $raw;
        $found = false;
        foreach ($raw as &$d) {
            if (is_array($d) && (string)($d['id'] ?? '') === $id) {
                if ($name !== null) {
                    $d['name'] = $name;
                }
                if ($baseUrl !== null) {
                    $d['base_url'] = $baseUrl;
                }
                if ($networkId !== null) {
                    $d['network_id'] = $networkId;
                }
                $found = true;
                break;
            }
        }
        unset($d);
        if (!$found) {
            throw new YtdsOpError('DESTINATION_NOT_FOUND', 404, 'destination not found: ' . $id, 'ytds destinations list');
        }
        $clean = DestinationLibrary::sanitize($raw, $this->idGen());
        $this->assertRemovedLibraryIdsUnused('destination', $before, $clean);
        if ($commit) {
            $common['destinations'] = $clean;
            $this->saveCommon($common, 'destinations');
        }
        return ['dry_run' => !$commit, 'action' => 'destination.update', 'destination' => $this->findById($clean, $id)];
    }

    /** @return array<string, mixed> */
    public function destinationDelete(string $id, bool $commit): array
    {
        $common = $this->db->get_common_settings();
        $raw = is_array($common['destinations'] ?? null) ? $common['destinations'] : [];
        $kept = array_values(array_filter($raw, static fn($d): bool => !(is_array($d) && (string)($d['id'] ?? '') === $id)));
        if (count($kept) === count($raw)) {
            throw new YtdsOpError('DESTINATION_NOT_FOUND', 404, 'destination not found: ' . $id, 'ytds destinations list');
        }
        $this->assertRemovedLibraryIdsUnused('destination', $raw, $kept);
        $clean = DestinationLibrary::sanitize($kept, $this->idGen());
        if ($commit) {
            $common['destinations'] = $clean;
            $this->saveCommon($common, 'destinations');
        }
        return ['dry_run' => !$commit, 'action' => 'destination.delete', 'id' => $id, 'count' => count($clean)];
    }

    private function idGen(): callable
    {
        return static fn(): string => bin2hex(random_bytes(6));
    }

    /** @param array<string, mixed> $common */
    private function saveCommon(array $common, string $what): void
    {
        if (!$this->db->set_common_settings($common)) {
            throw new YtdsOpError('WRITE_FAILED', 500, 'could not save the ' . $what . ' library', '');
        }
    }

    /**
     * @param array<int, mixed> $list
     * @return array<string, mixed>
     */
    private function findById(array $list, string $id): array
    {
        foreach ($list as $entry) {
            if (is_array($entry) && (string)($entry['id'] ?? '') === $id) {
                return $entry;
            }
        }
        return [];
    }

    /**
     * Panel library pages replace the whole catalog. Refuse dropping any id still referenced
     * by Checkout Routes or moving a referenced Destination to another Network, using the same
     * RESOURCE_IN_USE contract as CLI/API delete.
     *
     * @param array<int, mixed> $before
     * @param array<int, mixed> $after
     */
    public function assertRemovedLibraryIdsUnused(string $resourceType, array $before, array $after): void
    {
        $beforeIds = $this->libraryIds($before);
        $afterIds = $this->libraryIds($after);
        foreach (array_diff($beforeIds, $afterIds) as $id) {
            $usedBy = $this->checkoutRouteUsage($resourceType, $id);
            if ($usedBy === []) {
                continue;
            }
            throw new YtdsOpError(
                'RESOURCE_IN_USE',
                409,
                $resourceType . ' is used by Checkout Routes: ' . $id,
                'remove the Checkout Route reference first; used by ' . implode(', ', $usedBy)
            );
        }
        if ($resourceType !== 'destination') {
            return;
        }

        $beforeNetworks = $this->destinationNetworkIds($before);
        $afterNetworks = $this->destinationNetworkIds($after);
        foreach (array_intersect(array_keys($beforeNetworks), array_keys($afterNetworks)) as $id) {
            if ($beforeNetworks[$id] === $afterNetworks[$id]) {
                continue;
            }
            $usedBy = $this->checkoutRouteUsage('destination', $id);
            if ($usedBy === []) {
                continue;
            }
            throw new YtdsOpError(
                'RESOURCE_IN_USE',
                409,
                'destination Network cannot change while used by Checkout Routes: ' . $id,
                'remove the Checkout Route reference first; used by ' . implode(', ', $usedBy)
            );
        }
    }

    /**
     * @param array<int, mixed> $list
     * @return array<int, string>
     */
    private function libraryIds(array $list): array
    {
        $ids = [];
        foreach ($list as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = trim((string)($entry['id'] ?? ''));
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /** @param array<int, mixed> $list @return array<string, string> */
    private function destinationNetworkIds(array $list): array
    {
        $networkIds = [];
        foreach ($list as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = trim((string)($entry['id'] ?? ''));
            if ($id !== '') {
                $networkIds[$id] = trim((string)($entry['network_id'] ?? ''));
            }
        }
        return $networkIds;
    }

    /** @return array<int, string> */
    private function checkoutRouteUsage(string $resourceType, string $id): array
    {
        $usedBy = [];
        foreach ($this->db->get_campaign_runtime_rows() as $campaign) {
            $flows = $campaign['settings']['black']['flows'] ?? [];
            if (!is_array($flows)) {
                continue;
            }
            foreach ($flows as $flowIndex => $flow) {
                if (!is_array($flow)) {
                    continue;
                }
                $flowName = trim((string)($flow['name'] ?? '')) ?: 'Flow ' . ((int)$flowIndex + 1);
                $steps = $flow['steps'] ?? [];
                if (!is_array($steps)) {
                    continue;
                }
                foreach ($steps as $stepIndex => $step) {
                    if (!is_array($step) || !array_key_exists('checkout_routes', $step)) {
                        continue;
                    }
                    $where = (string)$campaign['name'] . ': ' . $flowName . ' — step ' . ((int)$stepIndex + 1);
                    $routes = $step['checkout_routes'];
                    if (!is_array($routes)) {
                        throw new YtdsOpError('SETTINGS_CORRUPT', 409, 'malformed Checkout Routes prevent safe library deletion', 'inspect ' . $where);
                    }
                    foreach ($routes as $route) {
                        if (!is_array($route) || !isset($route['network_id']) || !is_array($route['links'] ?? null)) {
                            throw new YtdsOpError('SETTINGS_CORRUPT', 409, 'malformed Checkout Routes prevent safe library deletion', 'inspect ' . $where);
                        }
                        if ($resourceType === 'network' && (string)$route['network_id'] === $id) {
                            $usedBy[$where] = true;
                        }
                        foreach ($route['links'] as $link) {
                            if (!is_array($link) || !isset($link['destination_id'])) {
                                throw new YtdsOpError('SETTINGS_CORRUPT', 409, 'malformed Checkout Routes prevent safe library deletion', 'inspect ' . $where);
                            }
                            if ($resourceType === 'destination' && (string)$link['destination_id'] === $id) {
                                $usedBy[$where] = true;
                            }
                        }
                    }
                }
            }
        }
        return array_keys($usedBy);
    }

    // -- Landings library (filesystem folders under caching/landings) --

    /** @return array<string, mixed> */
    public function landingUpload(string $landingsDir, string $name, string $zipPath, bool $commit): array
    {
        $lib = new LandingLibrary($landingsDir);
        $error = $lib->validateUpload($name, $zipPath);
        if ($error !== null) {
            throw new YtdsOpError('INVALID_ARG', 400, $error, 'ytds landing list');
        }
        if (!$commit) {
            return ['dry_run' => true, 'action' => 'landing.upload', 'name' => $name];
        }
        $error = $lib->uploadZip($name, $zipPath);
        if ($error !== null) {
            throw new YtdsOpError('WRITE_FAILED', 500, $error, '');
        }
        return ['dry_run' => false, 'action' => 'landing.upload', 'landing' => $lib->describe($name)];
    }

    /** @return array<string, mixed> */
    public function landingDownload(string $landingsDir, string $name, string $zipPath, bool $overwrite = true): array
    {
        $lib = new LandingLibrary($landingsDir);
        if (!$lib->exists($name)) {
            throw new YtdsOpError('LANDING_NOT_FOUND', 404, 'landing not found: ' . $name, 'ytds landing list');
        }
        try {
            $meta = $overwrite
                ? $lib->archive($name, $zipPath)
                : LandingArchive::pack($landingsDir . DIRECTORY_SEPARATOR . $name, $zipPath);
        } catch (InvalidArgumentException $e) {
            throw new YtdsOpError('INVALID_ARG', 400, $e->getMessage(), '');
        } catch (Throwable $e) {
            throw new YtdsOpError('WRITE_FAILED', 500, $e->getMessage(), '');
        }
        return ['action' => 'landing.download', 'name' => $name] + $meta;
    }

    /**
     * Applies exact, counted substitutions to one existing landing file. This single primitive
     * covers text/script insertion, delay changes, href changes, ytdsEvent(), and ytdsConversion().
     * @param array<int, mixed> $replacements
     * @return array<string, mixed>
     */
    public function landingEdit(string $landingsDir, string $name, string $file, array $replacements, bool $commit): array
    {
        $lib = new LandingLibrary($landingsDir);
        if (!$lib->exists($name)) {
            throw new YtdsOpError('LANDING_NOT_FOUND', 404, 'landing not found: ' . $name, 'ytds landing list');
        }
        if ($replacements === [] || count($replacements) > 100) {
            throw new YtdsOpError('INVALID_ARG', 400, 'replacements must contain between 1 and 100 entries', '');
        }
        try {
            $path = $lib->editableFile($name, $file);
        } catch (InvalidArgumentException $e) {
            throw new YtdsOpError('INVALID_ARG', 400, $e->getMessage(), '');
        }
        $before = file_get_contents($path);
        if ($before === false) {
            throw new YtdsOpError('WRITE_FAILED', 500, 'cannot read landing file: ' . $file, '');
        }
        if (strlen($before) > 20 * 1024 * 1024) {
            throw new YtdsOpError('INVALID_ARG', 400, 'landing file exceeds the 20 MiB edit limit', 'replace the whole landing ZIP instead');
        }
        $after = $before;
        $counts = [];
        foreach ($replacements as $index => $replacement) {
            if (!is_array($replacement) || !array_key_exists('search', $replacement) || !array_key_exists('replace', $replacement)) {
                throw new YtdsOpError('INVALID_ARG', 400, 'replacement ' . ($index + 1) . ' needs search and replace strings', '');
            }
            $search = $replacement['search'];
            $replace = $replacement['replace'];
            $expected = $replacement['expected'] ?? 1;
            if (!is_string($search) || $search === '' || !is_string($replace) || !is_int($expected) || $expected < 1) {
                throw new YtdsOpError('INVALID_ARG', 400, 'replacement ' . ($index + 1) . ' has invalid search, replace, or expected', '');
            }
            $actual = substr_count($after, $search);
            if ($actual !== $expected) {
                throw new YtdsOpError(
                    'REPLACEMENT_COUNT_MISMATCH',
                    409,
                    'replacement ' . ($index + 1) . ' expected ' . $expected . ' matches, found ' . $actual,
                    'download the current landing and update the exact search anchor'
                );
            }
            $after = str_replace($search, $replace, $after);
            $counts[] = ['index' => $index + 1, 'expected' => $expected, 'actual' => $actual];
        }
        $result = [
            'dry_run' => !$commit,
            'action' => 'landing.edit',
            'name' => $name,
            'file' => str_replace('\\', '/', $file),
            'before_sha256' => hash('sha256', $before),
            'after_sha256' => hash('sha256', $after),
            'replacements' => $counts,
        ];
        if ($commit) {
            try {
                $lib->writeFileAtomic($path, $after);
            } catch (Throwable $e) {
                throw new YtdsOpError('WRITE_FAILED', 500, $e->getMessage(), '');
            }
        }
        return $result;
    }

    /** @return array<string, mixed> */
    public function landingReplace(string $landingsDir, string $name, string $zipPath, bool $commit): array
    {
        $lib = new LandingLibrary($landingsDir);
        if (!$lib->exists($name)) {
            throw new YtdsOpError('LANDING_NOT_FOUND', 404, 'landing not found: ' . $name, 'ytds landing list');
        }
        try {
            $archive = LandingArchive::inspect($zipPath);
        } catch (InvalidArgumentException $e) {
            throw new YtdsOpError('INVALID_ARG', 400, $e->getMessage(), 'ytds landing verify --zip ' . $zipPath);
        }
        $before = $lib->describe($name);
        if (!$commit) {
            return ['dry_run' => true, 'action' => 'landing.replace', 'name' => $name, 'before' => $before, 'archive' => $archive];
        }
        try {
            $installed = $lib->replaceZip($name, $zipPath);
        } catch (Throwable $e) {
            throw new YtdsOpError('WRITE_FAILED', 500, $e->getMessage(), '');
        }
        return [
            'dry_run' => false,
            'action' => 'landing.replace',
            'name' => $name,
            'before' => $before,
            'landing' => $lib->describe($name),
            'archive' => $archive,
            'cleanup_pending' => (bool)($installed['cleanup_pending'] ?? false),
        ];
    }

    /** @return array<string, mixed> */
    public function landingDuplicate(string $landingsDir, string $from, string $to, bool $commit): array
    {
        $lib = new LandingLibrary($landingsDir);
        if (!$lib->exists($from)) {
            throw new YtdsOpError('LANDING_NOT_FOUND', 404, 'landing not found: ' . $from, 'ytds landing list');
        }
        if (!LandingName::isValid($to)) {
            throw new YtdsOpError('INVALID_ARG', 400, 'invalid target landing name: ' . $to, '');
        }
        if ($lib->exists($to)) {
            throw new YtdsOpError('INVALID_ARG', 400, 'target landing already exists: ' . $to, '');
        }
        if (!$commit) {
            return ['dry_run' => true, 'action' => 'landing.duplicate', 'from' => $from, 'to' => $to];
        }
        if (!$lib->duplicate($from, $to)) {
            throw new YtdsOpError('WRITE_FAILED', 500, 'landing duplicate failed', '');
        }
        return ['dry_run' => false, 'action' => 'landing.duplicate', 'landing' => $lib->describe($to)];
    }

    /** @return array<string, mixed> */
    public function landingDelete(string $landingsDir, string $name, bool $commit): array
    {
        $lib = new LandingLibrary($landingsDir);
        if (!$lib->exists($name)) {
            throw new YtdsOpError('LANDING_NOT_FOUND', 404, 'landing not found: ' . $name, 'ytds landing list');
        }
        $usedBy = $this->landingUsage($name);
        if (!$commit) {
            return ['dry_run' => true, 'action' => 'landing.delete', 'name' => $name, 'used_by' => $usedBy];
        }
        if (!$lib->delete($name)) {
            throw new YtdsOpError('WRITE_FAILED', 500, 'landing delete failed', '');
        }
        return ['dry_run' => false, 'action' => 'landing.delete', 'name' => $name, 'used_by' => $usedBy];
    }

    /**
     * Campaigns (by name) whose flows/white pages reference a landing folder, so a delete can warn first.
     * @return array<int, string>
     */
    private function landingUsage(string $name): array
    {
        $out = [];
        foreach ($this->db->get_campaign_runtime_rows() as $row) {
            foreach (LandingUsage::scan($row['settings'], $name) as $where) {
                $out[] = $row['name'] . ': ' . $where;
            }
        }
        return $out;
    }

    /** CampaignService::get with corrupt-settings JSON surfaced as a stable op error. */
    private function safeGet(int $id): ?array
    {
        try {
            return $this->campaigns->get($id);
        } catch (RuntimeException $e) {
            throw new YtdsOpError('SETTINGS_CORRUPT', 500, $e->getMessage(), 'inspect campaigns.settings JSON');
        }
    }

    // ── Mutations (phase 2): dry-run unless $commit; delegate to CampaignService ──

    /** @return array<string, mixed> */
    public function cloneCampaign(int $id, ?string $name, bool $commit): array
    {
        return $this->campaigns->cloneCampaign($id, $name, $commit);
    }

    /** @return array<string, mixed> */
    public function renameCampaign(int $id, string $name, bool $commit): array
    {
        return $this->campaigns->renameCampaign($id, $name, $commit);
    }

    /** @return array<string, mixed> */
    public function deleteCampaign(int $id, bool $commit): array
    {
        return $this->campaigns->deleteCampaign($id, $commit);
    }

    /**
     * @param array<int, string> $domains
     * @return array<string, mixed>
     */
    public function setDomains(int $id, array $domains, bool $commit): array
    {
        return $this->campaigns->setDomains($id, $domains, $commit);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function patch(int $id, array $input, bool $commit): array
    {
        return $this->campaigns->patch($id, $input, $commit);
    }

    /**
     * @param array<string, mixed> $template
     * @return array<string, mixed>
     */
    public function create(string $name, array $template, bool $commit): array
    {
        return $this->campaigns->create($name, $template, $commit);
    }

    /**
     * @param array<string, mixed> $sets
     * @return array<string, mixed>
     */
    public function setFields(int $id, array $sets, bool $commit): array
    {
        return $this->campaigns->setFields($id, $sets, $commit);
    }

    /** @return array<string, mixed> */
    public function killAuthorDefaults(int $id, bool $commit): array
    {
        return $this->campaigns->killAuthorDefaults($id, $commit);
    }

    /** @return array<string, mixed> settings of an existing campaign; throws if absent */
    private function requireCampaignSettings(int $campId): array
    {
        $campaign = $this->safeGet($campId);
        if ($campaign === null) {
            throw new YtdsOpError('CAMPAIGN_NOT_FOUND', 404, 'campaign ' . $campId . ' not found', 'ytds campaigns list');
        }
        return $campaign['settings'];
    }

    /** @param array<string, mixed> $settings */
    private function campaignTimezone(array $settings): string
    {
        $tz = $settings['statistics']['timezone'] ?? null;
        if (is_string($tz) && $tz !== '' && in_array($tz, DateTimeZone::listIdentifiers(), true)) {
            return $tz;
        }
        global $cloSettings;
        $global = $cloSettings['timezone'] ?? 'Europe/Moscow';
        return is_string($global) && $global !== '' ? $global : 'Europe/Moscow';
    }

    /**
     * Resolves a from/to window (d.m.y, panel format; defaults to today) to a [startUnix, endUnix]
     * pair in the given timezone. Self-contained on purpose: deployed engine code must never reach
     * into the renamed admin/ directory, so this inlines the date math instead of admin/dates.php.
     *
     * @return array{0: int, 1: int} [startUnix, endUnix]
     */
    private function resolveRange(string $tz, ?string $from, ?string $to): array
    {
        $dtz = new DateTimeZone($tz);
        $start = $this->parseDay($from, $dtz)->setTime(0, 0, 0);
        $end = $this->parseDay($to, $dtz)->setTime(23, 59, 59);
        return [$start->getTimestamp(), $end->getTimestamp()];
    }

    private function parseDay(?string $day, DateTimeZone $dtz): DateTime
    {
        if ($day !== null && $day !== '') {
            $parsed = DateTime::createFromFormat('d.m.y', $day, $dtz);
            if ($parsed !== false) {
                return $parsed;
            }
        }
        return new DateTime('now', $dtz);
    }

    /**
     * @param array<string, mixed> $click
     * @param array<int, string> $paramColumns extra param.* keys to project alongside the narrow set
     * @return array<string, mixed>
     */
    private static function narrowClick(array $click, array $paramColumns = []): array
    {
        $out = [];
        foreach (self::CLICK_NARROW_COLUMNS as $col) {
            $out[$col] = $click[$col] ?? null;
        }
        foreach ($paramColumns as $key) {
            $out["param.$key"] = $click["param.$key"] ?? null;
        }
        return $out;
    }

    /**
     * Turns CLI-style "field:op:value" filter specs into the db rule shape. Unknown fields/operators
     * are dropped by the query builder, so pass-through is safe.
     * @param array<int, mixed> $raw
     * @return array{condition: string, rules: array<int, array{field: string, operator: string, value: string}>}|array{}
     */
    private static function buildClickFilters(array $raw, string $condition): array
    {
        $rules = [];
        foreach ($raw as $spec) {
            if (!is_string($spec) || trim($spec) === '') {
                continue;
            }
            $parts = explode(':', $spec, 3);
            $field = trim($parts[0]);
            if ($field === '') {
                continue;
            }
            $op = isset($parts[1]) && trim($parts[1]) !== '' ? trim($parts[1]) : '=';
            $rules[] = ['field' => $field, 'operator' => $op, 'value' => $parts[2] ?? ''];
        }
        if ($rules === []) {
            return [];
        }
        return ['condition' => strtoupper(trim($condition)) === 'OR' ? 'OR' : 'AND', 'rules' => $rules];
    }

    /**
     * Keeps only safe param keys ([A-Za-z0-9_]) for json_extract projection.
     * @param array<int, mixed> $raw
     * @return array<int, string>
     */
    private static function clickParamColumns(array $raw): array
    {
        $out = [];
        foreach ($raw as $key) {
            if (is_string($key) && preg_match('/^[a-zA-Z0-9_]+$/', $key) === 1) {
                $out[$key] = true;
            }
        }
        return array_keys($out);
    }

    private static function formatCents(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), abs($cents % 100));
    }
}
