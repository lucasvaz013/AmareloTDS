<?php

// JSON endpoints must never leak PHP warnings into the response body.
ini_set('display_errors', '0');

require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/../domains.php';
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../logging.php';
require_once __DIR__ . '/password.php';
require_once __DIR__ . '/accesscontrol.php';

function domains_send(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Everything the page needs to decide which controls are usable. The buttons are
 * disabled from this, so it reports capability rather than raw credentials.
 *
 * @return array<string, mixed>
 */
/**
 * A domain only routes traffic once it sits in a campaign's own domain list — that list
 * is what runtimeconfig indexes, and it is separate from the one this page manages.
 * Attaching writes through save_campaign_settings so normalisation, the cross-campaign
 * uniqueness check and the runtime cache rebuild all happen as they would in the editor.
 */
function domains_attach_to_campaign(Db $db, int $campaignId, string $hostname): string
{
    try {
        $settings = $db->get_campaign_settings($campaignId);
    } catch (Throwable) {
        return 'That campaign no longer exists.';
    }
    if (!is_array($settings)) {
        return 'That campaign no longer exists.';
    }

    $current = array_values(array_filter((array)($settings['domains'] ?? []), 'is_string'));
    foreach ($current as $existing) {
        if (strcasecmp(trim($existing), $hostname) === 0) {
            return '';
        }
    }

    $current[] = $hostname;
    $settings['domains'] = $current;

    try {
        $db->save_campaign_settings($campaignId, $settings);
    } catch (Throwable $e) {
        // Most often the domain already belongs to another campaign.
        return $e->getMessage();
    }
    return '';
}

function domains_detach_from_campaign(Db $db, int $campaignId, string $hostname): void
{
    try {
        $settings = $db->get_campaign_settings($campaignId);
    } catch (Throwable) {
        return;
    }
    if (!is_array($settings)) {
        return;
    }
    $kept = array_values(array_filter(
        (array)($settings['domains'] ?? []),
        static fn($d): bool => is_string($d) && strcasecmp(trim($d), $hostname) !== 0
    ));
    if (count($kept) === count((array)($settings['domains'] ?? []))) {
        return;
    }
    $settings['domains'] = $kept;
    try {
        $db->save_campaign_settings($campaignId, $settings);
    } catch (Throwable) {
        // Detaching is best effort: the entry leaves this list either way.
    }
}

/** @return list<array{id:int,name:string}> */
function domains_campaign_options(Db $db): array
{
    $options = [];
    foreach ($db->get_campaigns_list() as $row) {
        $options[] = ['id' => (int)($row['id'] ?? 0), 'name' => (string)($row['name'] ?? '')];
    }
    return $options;
}

function domains_state(SettingsManager $manager, array $settings, string $publicIp): array
{
    $cloudflare = CloudflareIntegration::verify($settings);
    $namecheapConfigured = trim((string)($settings['namecheapApiUser'] ?? '')) !== ''
        && trim((string)($settings['namecheapApiKey'] ?? '')) !== '';
    $profile = is_array($settings['registrantProfile'] ?? null) ? $settings['registrantProfile'] : [];

    // Where the contact block will come from. Probed only when Namecheap is connected,
    // so an unconfigured panel does not pay for a pointless round trip.
    $registrantSource = 'none';
    $registrantLabel = '';
    $registrantMessage = '';
    if ($namecheapConfigured) {
        $resolved = NamecheapDomains::resolveProfile($settings, $publicIp);
        $registrantSource = $resolved['source'];
        $registrantLabel = $resolved['label'];
        $registrantMessage = $resolved['message'];
    } elseif (NamecheapDomains::missingProfileFields($profile) === []) {
        $registrantSource = 'local';
        $registrantLabel = 'saved here';
    }

    return [
        'revision' => $manager->revision(),
        'public_ip' => $publicIp,
        'hostname_prefix' => YTDS_HOSTNAME_PREFIX,
        'cloudflare_ready' => $cloudflare->ok,
        'cloudflare_message' => $cloudflare->message,
        'namecheap_ready' => $namecheapConfigured,
        'registrant_source' => $registrantSource,
        'registrant_label' => $registrantLabel,
        'registrant_message' => $registrantMessage,
        'registrant' => $profile,
        'can_register' => $cloudflare->ok && $namecheapConfigured && $registrantSource !== 'none',
        'domains' => DomainRegistry::all($settings),
        'campaigns' => domains_campaign_options(new Db()),
    ];
}

function domains_persist(SettingsManager $manager, array $settings, array $list, int $revision): array
{
    $settings['managedDomains'] = $list;
    return $manager->save($settings, $revision);
}

function domains_handle_request(): void
{
    global $cloSettings;

    if (!check_password(false)) {
        domains_send(['error' => 'Not authorized.'], 403);
        return;
    }
    // AJAX endpoints skip securitycheck.php and therefore the domain and IP allowlist.
    // This one can spend money, so the restriction is applied explicitly.
    if (get_admin_access_error($_SERVER, $cloSettings) !== null) {
        domains_send(['error' => 'Not found.'], 404);
        return;
    }

    session_write_close();

    $manager = new SettingsManager(dirname(__DIR__));
    $settings = $manager->load();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        domains_send(domains_state($manager, $settings, integrations_detect_public_ipv4()));
        return;
    }
    if ($method !== 'POST') {
        domains_send(['error' => 'Method not allowed.'], 405);
        return;
    }

    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        domains_send(['error' => 'Invalid JSON body.'], 422);
        return;
    }

    $action = (string)($input['action'] ?? '');
    $domain = DomainName::normalize((string)($input['domain'] ?? ''));
    $revision = (int)($input['revision'] ?? $manager->revision());

    if (in_array($action, ['check-availability', 'register', 'cloudflare-sync', 'manual-check', 'remove', 'resume', 'assign'], true)
        && !DomainName::isValid($domain)) {
        domains_send(['error' => 'Type a domain like lifeisgoodhere.online.'], 422);
        return;
    }

    try {
        switch ($action) {
            case 'save-registrant':
                $submitted = is_array($input['registrant'] ?? null) ? $input['registrant'] : [];
                $profile = is_array($settings['registrantProfile'] ?? null) ? $settings['registrantProfile'] : [];
                foreach (array_keys($profile) as $field) {
                    if (array_key_exists($field, $submitted)) {
                        $profile[$field] = trim((string)$submitted[$field]);
                    }
                }
                $settings['registrantProfile'] = $profile;
                $saved = $manager->save($settings, $revision);
                domains_send(domains_state($manager, $saved['settings'], integrations_detect_public_ipv4()) + [
                    'message' => 'Registrant profile saved.',
                ]);
                return;

            case 'check-availability':
                $publicIp = integrations_detect_public_ipv4();
                $step = NamecheapDomains::isAvailable($settings, $domain, $publicIp);
                domains_send(['step' => $step->jsonSerialize()] + domains_state($manager, $settings, $publicIp));
                return;

            case 'register':
                // Spending money requires the caller to have seen the price and said so.
                if (($input['confirm'] ?? false) !== true) {
                    domains_send(['error' => 'Registration must be confirmed.'], 422);
                    return;
                }
                $publicIp = integrations_detect_public_ipv4();
                $outcome = domains_register($settings, $domain, (int)($input['years'] ?? 1), $publicIp, $publicIp);
                // Stored on `registered`, not on `ok`: the purchase is real even when a
                // later step fails, and a domain paid for must never vanish from the list.
                if ($outcome->registered) {
                    $saved = domains_persist(
                        $manager,
                        $settings,
                        DomainRegistry::put(DomainRegistry::all($settings), $domain, 'registered', $outcome->zoneId, time(), $outcome->status, $outcome->message),
                        $revision
                    );
                    $settings = $saved['settings'];
                }
                domains_send(['outcome' => $outcome->jsonSerialize()] + domains_state($manager, $settings, $publicIp));
                return;

            case 'resume':
                $publicIp = integrations_detect_public_ipv4();
                $entry = null;
                foreach (DomainRegistry::all($settings) as $candidate) {
                    if (strcasecmp((string)($candidate['name'] ?? ''), $domain) === 0) {
                        $entry = $candidate;
                    }
                }
                $outcome = domains_advance($settings, $domain, $publicIp, $publicIp, (string)($entry['zone_id'] ?? ''));
                $saved = domains_persist(
                    $manager,
                    $settings,
                    DomainRegistry::put(DomainRegistry::all($settings), $domain, (string)($entry['source'] ?? 'registered'), $outcome->zoneId, time(), $outcome->status, $outcome->message),
                    $revision
                );
                domains_send(['outcome' => $outcome->jsonSerialize()] + domains_state($manager, $saved['settings'], $publicIp));
                return;

            case 'resume-pending':
                $publicIp = integrations_detect_public_ipv4();
                $list = DomainRegistry::all($settings);
                $touched = 0;
                foreach ($list as $candidate) {
                    if ((string)($candidate['status'] ?? '') === DomainStatus::READY) {
                        continue;
                    }
                    $name = (string)($candidate['name'] ?? '');
                    if (!DomainName::isValid($name)) {
                        continue;
                    }
                    $outcome = domains_advance($settings, $name, $publicIp, $publicIp, (string)($candidate['zone_id'] ?? ''));
                    $list = DomainRegistry::put($list, $name, (string)($candidate['source'] ?? 'registered'), $outcome->zoneId, time(), $outcome->status, $outcome->message);
                    $touched++;
                }
                $saved = $touched > 0 ? domains_persist($manager, $settings, $list, $revision) : ['settings' => $settings];
                domains_send(['checked' => $touched] + domains_state($manager, $saved['settings'], $publicIp));
                return;

            case 'cloudflare-sync':
                $publicIp = integrations_detect_public_ipv4();
                $outcome = domains_cloudflare_sync($settings, $domain, $publicIp);
                if ($outcome->ok) {
                    $zoneId = '';
                    foreach ($outcome->steps as $step) {
                        $zoneId = (string)($step->details['zone_id'] ?? $zoneId);
                    }
                    $saved = domains_persist($manager, $settings, DomainRegistry::put(DomainRegistry::all($settings), $domain, 'cloudflare', $zoneId, time(), $outcome->status, $outcome->message), $revision);
                    $settings = $saved['settings'];
                }
                domains_send(['outcome' => $outcome->jsonSerialize()] + domains_state($manager, $settings, $publicIp));
                return;

            case 'manual-check':
                $publicIp = integrations_detect_public_ipv4();
                $outcome = domains_manual_check(DomainName::hostname($domain), $publicIp);
                if ($outcome->ok) {
                    $saved = domains_persist($manager, $settings, DomainRegistry::put(DomainRegistry::all($settings), $domain, 'manual', '', time(), $outcome->status, $outcome->message), $revision);
                    $settings = $saved['settings'];
                }
                domains_send(['outcome' => $outcome->jsonSerialize()] + domains_state($manager, $settings, $publicIp));
                return;

            case 'assign':
                $campaignId = (int)($input['campaign_id'] ?? 0);
                $hostname = DomainName::hostname($domain);
                $db = new Db();
                $list = DomainRegistry::all($settings);

                $previous = 0;
                foreach ($list as $candidate) {
                    if (strcasecmp((string)($candidate['name'] ?? ''), $domain) === 0) {
                        $previous = (int)($candidate['campaign_id'] ?? 0);
                    }
                }
                if ($previous > 0 && $previous !== $campaignId) {
                    domains_detach_from_campaign($db, $previous, $hostname);
                }

                if ($campaignId > 0) {
                    $error = domains_attach_to_campaign($db, $campaignId, $hostname);
                    if ($error !== '') {
                        domains_send(['error' => $error], 422);
                        return;
                    }
                }

                foreach ($list as $index => $candidate) {
                    if (strcasecmp((string)($candidate['name'] ?? ''), $domain) === 0) {
                        $list[$index]['campaign_id'] = $campaignId;
                    }
                }
                $saved = domains_persist($manager, $settings, $list, $revision);
                domains_send(domains_state($manager, $saved['settings'], integrations_detect_public_ipv4()) + [
                    'message' => $campaignId > 0
                        ? $hostname . ' now routes to that campaign.'
                        : $hostname . ' is no longer attached to a campaign.',
                ]);
                return;

            case 'remove':
                foreach (DomainRegistry::all($settings) as $candidate) {
                    if (strcasecmp((string)($candidate['name'] ?? ''), $domain) === 0
                        && (int)($candidate['campaign_id'] ?? 0) > 0) {
                        domains_detach_from_campaign(new Db(), (int)$candidate['campaign_id'], DomainName::hostname($domain));
                    }
                }
                $saved = domains_persist($manager, $settings, DomainRegistry::remove(DomainRegistry::all($settings), $domain), $revision);
                domains_send(domains_state($manager, $saved['settings'], integrations_detect_public_ipv4()) + [
                    'message' => $domain . ' removed from the list. The DNS record was left untouched.',
                ]);
                return;

            default:
                domains_send(['error' => 'Unknown action.'], 422);
                return;
        }
    } catch (SettingsValidationException $e) {
        domains_send(['error' => 'Some fields are invalid.', 'fields' => $e->errors], 422);
    } catch (SettingsConflictException) {
        domains_send(['error' => 'Settings changed in another tab. Reload and try again.'], 409);
    } catch (Throwable $e) {
        ytds_log('error', 'admin', $e->getMessage(), ['action' => 'domains-' . $action]);
        domains_send(['error' => 'The operation failed. Check the server log for details.'], 500);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    domains_handle_request();
}
