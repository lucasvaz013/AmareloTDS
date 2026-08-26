<?php

/**
 * A read-only or mutating campaign operation failure with a stable machine code, an HTTP status
 * (for the admin API) and a CLI hint. Both ports — the ytds CLI and code/api/admin.php — map
 * these to the same {code, message, hint} envelope. Defined here (the base campaign file) so the
 * service, AdminOps, and the API all share one type.
 */
final class YtdsOpError extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message,
        public readonly string $hint = ''
    ) {
        parent::__construct($message);
    }
}

/**
 * Campaign access + safe mutation shared by the ytds CLI, the admin API, and (via the same Db
 * primitives) the panel. Pure PHP: no $_REQUEST, no echo, no exit.
 *
 * Reads mask secrets (get() → redact()). WRITES read raw settings (get_campaign_runtime_rows,
 * never the redacted get()) so a mutation can never persist "<redacted>" over a real apikey or
 * token. Every mutation takes $commit: false previews (dry-run), true writes.
 */
class CampaignService
{
    /** Settings paths whose values are masked in every output. */
    private const REDACTED_PATHS = [
        ['apikey'],
        ['capi', 'access_token'],
        ['postback', 'pbkey', 'keys'],
    ];

    public const REDACTED_VALUE = '<redacted>';

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Narrow rows for machine consumption, ordered by id ASC (stable for automation).
     *
     * @return array<int, array{id: int, name: string, domains: array<int, string>, flows: int}>
     * @throws RuntimeException when a campaign row carries invalid settings JSON
     */
    public function list(): array
    {
        $rows = [];
        foreach ($this->db->get_campaign_runtime_rows() as $row) {
            $rows[] = $this->summary($row['id'], $row['name'], $row['settings']);
        }
        return $rows;
    }

    /**
     * One campaign with full decoded settings, secrets redacted.
     *
     * @return array{id: int, name: string, settings: array<string, mixed>}|null null when the id does not exist
     * @throws RuntimeException when a campaign row carries invalid settings JSON
     */
    public function get(int $id): ?array
    {
        foreach ($this->db->get_campaign_runtime_rows() as $row) {
            if ($row['id'] === $id) {
                return [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'settings' => self::redact($row['settings']),
                ];
            }
        }
        return null;
    }

    /**
     * Narrow projection of one campaign. Tolerates minimal settings the same
     * way the engine does: absent keys mean empty (`?? default`).
     *
     * @param array<string, mixed> $settings
     * @return array{id: int, name: string, domains: array<int, string>, flows: int}
     */
    public function summary(int $id, string $name, array $settings): array
    {
        $domains = $settings['domains'] ?? [];
        $flows = $settings['black']['flows'] ?? [];
        return [
            'id' => $id,
            'name' => $name,
            'domains' => is_array($domains) ? array_values($domains) : [],
            'flows' => is_array($flows) ? count($flows) : 0,
        ];
    }

    /**
     * Masks configured secret values; empty or absent secrets stay as-is so the
     * output still shows what is (not) configured. Non-secret keys untouched.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public static function redact(array $settings): array
    {
        foreach (self::REDACTED_PATHS as $path) {
            $node = &$settings;
            $last = array_pop($path);
            foreach ($path as $key) {
                if (!isset($node[$key]) || !is_array($node[$key])) {
                    continue 2;
                }
                $node = &$node[$key];
            }
            if (!isset($node[$last])) {
                continue;
            }
            $value = $node[$last];
            if (is_string($value) && $value !== '') {
                $node[$last] = self::REDACTED_VALUE;
            } elseif (is_array($value) && $value !== []) {
                $node[$last] = array_fill(0, count($value), self::REDACTED_VALUE);
            }
        }
        return $settings;
    }

    /**
     * Raw (unredacted, JSON-validated) campaign row by id, or null. Mutations MUST use this, never
     * the redacted get(), so a write never persists a masked secret.
     * @return array{id: int, name: string, settings: array<string, mixed>}|null
     */
    private function rawCampaign(int $id): ?array
    {
        try {
            foreach ($this->db->get_campaign_runtime_rows() as $row) {
                if ($row['id'] === $id) {
                    return $row;
                }
            }
        } catch (RuntimeException $e) {
            throw new YtdsOpError('SETTINGS_CORRUPT', 500, $e->getMessage(), 'inspect campaigns.settings JSON');
        }
        return null;
    }

    private function requireCampaign(int $id): array
    {
        $row = $this->rawCampaign($id);
        if ($row === null) {
            throw new YtdsOpError('CAMPAIGN_NOT_FOUND', 404, 'campaign ' . $id . ' not found', 'ytds campaigns list');
        }
        return $row;
    }

    /**
     * Duplicates a campaign. Db::clone_campaign resets domains + white domainfilter, so the clone
     * never overlaps the source. $commit=false previews.
     * @return array<string, mixed>
     */
    public function cloneCampaign(int $id, ?string $name, bool $commit): array
    {
        $src = $this->requireCampaign($id);
        $newName = ($name !== null && trim($name) !== '') ? trim($name) : $src['name'] . ' (Clone)';
        if (!$commit) {
            return ['dry_run' => true, 'action' => 'clone', 'source_id' => $id, 'source_name' => $src['name'], 'new_name' => $newName];
        }
        $newId = $this->db->clone_campaign($id);
        if (!is_int($newId)) {
            throw new YtdsOpError('WRITE_FAILED', 500, 'clone failed for campaign ' . $id, '');
        }
        if ($name !== null && trim($name) !== '') {
            $this->db->rename_campaign($newId, $newName);
        }
        return ['dry_run' => false, 'action' => 'clone', 'source_id' => $id, 'id' => $newId, 'name' => $newName];
    }

    /** @return array<string, mixed> */
    public function renameCampaign(int $id, string $name, bool $commit): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new YtdsOpError('INVALID_ARG', 400, 'new campaign name cannot be empty', '');
        }
        $src = $this->requireCampaign($id);
        if (!$commit) {
            return ['dry_run' => true, 'action' => 'rename', 'id' => $id, 'before' => $src['name'], 'after' => $name];
        }
        if (!$this->db->rename_campaign($id, $name)) {
            throw new YtdsOpError('WRITE_FAILED', 500, 'rename failed for campaign ' . $id, '');
        }
        return ['dry_run' => false, 'action' => 'rename', 'id' => $id, 'before' => $src['name'], 'after' => $name];
    }

    /** @return array<string, mixed> */
    public function deleteCampaign(int $id, bool $commit): array
    {
        $src = $this->requireCampaign($id);
        $domains = is_array($src['settings']['domains'] ?? null) ? array_values($src['settings']['domains']) : [];
        if (!$commit) {
            return ['dry_run' => true, 'action' => 'delete', 'id' => $id, 'name' => $src['name'], 'domains' => $domains];
        }
        if (!$this->db->delete_campaign($id)) {
            throw new YtdsOpError('WRITE_FAILED', 500, 'delete failed for campaign ' . $id, '');
        }
        return ['dry_run' => false, 'action' => 'delete', 'id' => $id, 'name' => $src['name']];
    }

    /**
     * Replaces settings.domains. Overlap is validated (same RuntimeCampaignCache the panel uses) in
     * BOTH dry-run and commit, so a preview already surfaces a conflict.
     * @param array<int, string> $domains
     * @return array<string, mixed>
     */
    public function setDomains(int $id, array $domains, bool $commit): array
    {
        $src = $this->requireCampaign($id);
        $before = is_array($src['settings']['domains'] ?? null) ? array_values($src['settings']['domains']) : [];
        $next = $src['settings'];
        $next['domains'] = array_values($domains);
        $normalized = RuntimeCampaignCache::normalizeSettingsDomains($next);
        try {
            RuntimeCampaignCache::validateCandidate($this->db, $id, $normalized);
        } catch (CampaignDomainException $e) {
            throw new YtdsOpError('DOMAIN_CONFLICT', 409, $e->getMessage(), 'another campaign already claims one of these domains');
        }
        $after = is_array($normalized['domains'] ?? null) ? array_values($normalized['domains']) : [];
        if (!$commit) {
            return ['dry_run' => true, 'action' => 'domains', 'id' => $id, 'before' => $before, 'after' => $after];
        }
        if (!$this->db->save_campaign_settings($id, $next)) {
            throw new YtdsOpError('WRITE_FAILED', 500, 'domain save failed for campaign ' . $id, '');
        }
        return ['dry_run' => false, 'action' => 'domains', 'id' => $id, 'before' => $before, 'after' => $after];
    }

    /**
     * General settings patch through the SAME validators the panel save runs (uniqueness, event,
     * conversion, postback, capi, flow), then a recursive merge over the current settings and the
     * domain-overlap check. Covers folder/{link:N}/author-default edits: the caller supplies the
     * settings fragment. Dry-run returns a redacted top-level before/after diff.
     *
     * @param array<string, mixed> $input settings fragment to merge (validated first)
     * @return array<string, mixed>
     */
    public function patch(int $id, array $input, bool $commit): array
    {
        require_once __DIR__ . '/campaignmutation.php';  // merge + normalize_flow_input (+ campaign.php)
        require_once __DIR__ . '/campaignvalidation.php'; // normalize_* + find_uniqueness_rule_flows

        // A non-empty JSON object is required. An empty object or a JSON array would be treated as
        // a list by mergeSettingsRecursive and REPLACE all settings with [] — a full wipe. The panel
        // never sends that (it posts complete settings); the CLI/API must reject it.
        if ($input === [] || array_is_list($input)) {
            throw new YtdsOpError('INVALID_ARG', 400, 'patch fragment must be a non-empty JSON object', 'send at least one settings key, e.g. {"domains":["a.example.com"]}');
        }

        $current = $this->requireCampaign($id)['settings'];

        foreach (['normalize_uniqueness_input', 'normalize_event_input', 'normalize_conversion_input', 'normalize_postback_input', 'normalize_capi_input'] as $validator) {
            $error = $validator($input);
            if ($error !== null) {
                throw new YtdsOpError('VALIDATION', 400, $error, '');
            }
        }
        $common = $this->db->get_common_settings();
        $flowError = normalize_flow_input(
            $input,
            $current,
            is_array($common['networks'] ?? null) ? $common['networks'] : [],
            is_array($common['destinations'] ?? null) ? $common['destinations'] : []
        );
        if ($flowError !== null) {
            throw new YtdsOpError('VALIDATION', 400, $flowError, '');
        }

        $merged = mergeSettingsRecursive($current, $input);
        if (empty($merged['uniqueness']['enabled'])) {
            $affected = find_uniqueness_rule_flows($merged['black']['flows'] ?? []);
            if ($affected !== []) {
                throw new YtdsOpError('VALIDATION', 400, 'remove uniqueness rules before disabling uniqueness counting; affected flows: ' . implode(', ', $affected), '');
            }
        }

        try {
            RuntimeCampaignCache::validateCandidate($this->db, $id, RuntimeCampaignCache::normalizeSettingsDomains($merged));
        } catch (CampaignDomainException $e) {
            throw new YtdsOpError('DOMAIN_CONFLICT', 409, $e->getMessage(), 'another campaign already claims one of these domains');
        }

        $diff = $this->settingsDiff($current, $merged);
        if (!$commit) {
            return ['dry_run' => true, 'action' => 'patch', 'id' => $id, 'changed' => array_keys($diff), 'diff' => $diff];
        }
        if (!$this->db->save_campaign_settings($id, $merged)) {
            throw new YtdsOpError('WRITE_FAILED', 500, 'save failed for campaign ' . $id, '');
        }
        return ['dry_run' => false, 'action' => 'patch', 'id' => $id, 'changed' => array_keys($diff)];
    }

    /**
     * Friendly per-field editor. Applies dotted-path assignments onto the current settings — taking
     * each touched top-level section whole from the current settings so the panel validators still
     * see a complete section — then routes the result through the validated patch path.
     *
     * @param array<string, mixed> $sets map of `dot.path` => already-decoded value
     * @return array<string, mixed>
     */
    public function setFields(int $id, array $sets, bool $commit): array
    {
        if ($sets === []) {
            throw new YtdsOpError('INVALID_ARG', 400, 'no fields to set', 'pass one or more --set path=value');
        }
        $current = $this->requireCampaign($id)['settings'];
        $fragment = [];
        foreach ($sets as $path => $value) {
            $segments = explode('.', (string)$path);
            $top = $segments[0];
            if ($top === '') {
                throw new YtdsOpError('INVALID_ARG', 400, 'empty field path', '');
            }
            if (count($segments) === 1) {
                $fragment[$top] = $value;
                continue;
            }
            if (!array_key_exists($top, $fragment)) {
                $fragment[$top] = $current[$top] ?? [];
            }
            if (!is_array($fragment[$top])) {
                throw new YtdsOpError('INVALID_ARG', 400, "cannot set nested path '$path' on non-object field '$top'", '');
            }
            $this->deepSet($fragment[$top], array_slice($segments, 1), $value);
        }
        return $this->patch($id, $fragment, $commit);
    }

    /**
     * @param array<string, mixed> $node modified in place
     * @param array<int, string> $segments
     */
    private function deepSet(array &$node, array $segments, mixed $value): void
    {
        $key = array_shift($segments);
        if ($segments === []) {
            $node[$key] = $value;
            return;
        }
        if (!isset($node[$key]) || !is_array($node[$key])) {
            $node[$key] = [];
        }
        $this->deepSet($node[$key], $segments, $value);
    }

    /**
     * Guardrail #9 for an existing campaign: neutralize the author's three dangerous defaults —
     * the RU,BG,SG country filter, any rolltrk.com step redirect, and any eu.roerads.com S2S
     * postback — then route the cleaned settings through the validated patch path.
     *
     * @return array<string, mixed>
     */
    public function killAuthorDefaults(int $id, bool $commit): array
    {
        $next = $this->requireCampaign($id)['settings'];
        $removed = [];

        $rules = $next['white']['filters']['rules'] ?? null;
        if (is_array($rules)) {
            $kept = array_values(array_filter($rules, static fn($r): bool => !(is_array($r)
                && strtoupper(str_replace(' ', '', (string)($r['value'] ?? ''))) === 'RU,BG,SG')));
            if (count($kept) !== count($rules)) {
                $next['white']['filters']['rules'] = $kept;
                $removed[] = 'white country filter RU,BG,SG';
            }
        }

        foreach (($next['black']['flows'] ?? []) as $fi => $flow) {
            foreach (($flow['steps'] ?? []) as $si => $step) {
                $urls = $step['redirect']['urls'] ?? null;
                if (!is_array($urls)) {
                    continue;
                }
                $kept = array_values(array_filter($urls, static fn($u): bool => !(is_array($u)
                    && stripos((string)($u['url'] ?? ''), 'rolltrk.com') !== false)));
                if (count($kept) !== count($urls)) {
                    $next['black']['flows'][$fi]['steps'][$si]['redirect']['urls'] = $kept;
                    $removed[] = 'rolltrk.com redirect in flow ' . ($fi + 1) . ' step ' . ($si + 1);
                }
            }
        }

        $s2s = $next['postback']['s2s'] ?? null;
        if (is_array($s2s)) {
            $kept = array_values(array_filter($s2s, static fn($r): bool => !(is_array($r)
                && stripos((string)($r['url'] ?? ''), 'eu.roerads.com') !== false)));
            if (count($kept) !== count($s2s)) {
                $next['postback']['s2s'] = $kept;
                $removed[] = 'eu.roerads.com S2S postback';
            }
        }

        if ($removed === []) {
            return ['dry_run' => !$commit, 'action' => 'kill-defaults', 'id' => $id, 'removed' => [], 'note' => 'no author defaults present'];
        }
        $result = $this->patch($id, $next, $commit);
        return ['dry_run' => $result['dry_run'], 'action' => 'kill-defaults', 'id' => $id, 'removed' => $removed];
    }

    /**
     * Redacted top-level diff: only the settings keys whose (masked) value changed.
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<string, array{before: mixed, after: mixed}>
     */
    private function settingsDiff(array $before, array $after): array
    {
        $rb = self::redact($before);
        $ra = self::redact($after);
        $diff = [];
        foreach (array_keys($rb + $ra) as $key) {
            $b = $rb[$key] ?? null;
            $a = $ra[$key] ?? null;
            if ($b !== $a) {
                $diff[$key] = ['before' => $b, 'after' => $a];
            }
        }
        return $diff;
    }

    /**
     * Creates a campaign from a versioned template (not the author's default.json). Guardrail #9:
     * the template is authored clean and create() asserts the author's third parties never appear.
     * New campaigns start with no domains (safe/off). Dry-run validates + previews; commit writes.
     *
     * @param array<string, mixed> $template full campaign settings skeleton
     * @return array<string, mixed>
     */
    public function create(string $name, array $template, bool $commit): array
    {
        require_once __DIR__ . '/campaignmutation.php';
        require_once __DIR__ . '/campaignvalidation.php';

        $name = trim($name);
        if ($name === '') {
            throw new YtdsOpError('INVALID_ARG', 400, 'campaign name is required', 'ytds campaign create --name "My Campaign" --from-template blank');
        }
        if ($template === [] || array_is_list($template)) {
            throw new YtdsOpError('INVALID_ARG', 400, 'template must be a non-empty JSON object', '');
        }

        $settings = $template;
        foreach (['normalize_uniqueness_input', 'normalize_event_input', 'normalize_conversion_input', 'normalize_postback_input', 'normalize_capi_input'] as $validator) {
            $error = $validator($settings);
            if ($error !== null) {
                throw new YtdsOpError('VALIDATION', 400, 'template invalid: ' . $error, '');
            }
        }
        $common = $this->db->get_common_settings();
        $flowError = normalize_flow_input(
            $settings,
            [],
            is_array($common['networks'] ?? null) ? $common['networks'] : [],
            is_array($common['destinations'] ?? null) ? $common['destinations'] : []
        );
        if ($flowError !== null) {
            throw new YtdsOpError('VALIDATION', 400, 'template invalid: ' . $flowError, '');
        }

        $settings['domains'] = []; // a new campaign never inherits domains (no overlap, starts off)
        $this->assertNoAuthorDefaults($settings);

        $flows = is_array($settings['black']['flows'] ?? null) ? count($settings['black']['flows']) : 0;
        if (!$commit) {
            return ['dry_run' => true, 'action' => 'create', 'name' => $name, 'domains' => [], 'flows' => $flows];
        }

        // add_campaign(refreshRuntime:false) generates the apikey/timezone; save then writes the
        // clean template in a single cache rebuild, so the author-default base is never live.
        $newId = $this->db->add_campaign($name, false);
        if (!is_int($newId)) {
            throw new YtdsOpError('WRITE_FAILED', 500, 'create failed for ' . $name, '');
        }
        $generated = $this->db->get_campaign_settings($newId);
        $settings['apikey'] = (string)($generated['apikey'] ?? '');
        $settings['statistics']['timezone'] = (string)($generated['statistics']['timezone'] ?? 'Europe/Moscow');
        if (!$this->db->save_campaign_settings($newId, $settings)) {
            throw new YtdsOpError('WRITE_FAILED', 500, 'create save failed for ' . $name, '');
        }
        return ['dry_run' => false, 'action' => 'create', 'id' => $newId, 'name' => $name];
    }

    /** Guardrail #9 safety net: the author's third parties must never reach a created campaign. */
    private function assertNoAuthorDefaults(array $settings): void
    {
        $blob = strtolower((string)json_encode($settings));
        foreach (['rolltrk.com', 'eu.roerads.com'] as $forbidden) {
            if (str_contains($blob, $forbidden)) {
                throw new YtdsOpError('VALIDATION', 400, 'template still contains an author default: ' . $forbidden, 'use a clean template');
            }
        }
    }
}
