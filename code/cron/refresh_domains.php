<?php

/**
 * Advances every domain that is not finished yet.
 *
 * Cloudflare activates a zone minutes to hours after the nameservers change, so a
 * registration cannot complete inside the request that started it. This runs on a
 * schedule and finishes the job with nobody watching.
 *
 * Installed by install.sh as /etc/cron.d/amarelotds-domains.
 */

require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/../domains.php';
require_once __DIR__ . '/../logging.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$manager = new SettingsManager($root);
$settings = $manager->load();

$list = DomainRegistry::all($settings);
$pending = array_values(array_filter(
    $list,
    static fn(array $entry): bool => (string)($entry['status'] ?? '') !== DomainStatus::READY
        && DomainName::isValid((string)($entry['name'] ?? ''))
));

if ($pending === []) {
    exit(0);
}

$publicIp = integrations_detect_public_ipv4();
if ($publicIp === '') {
    ytds_log('warning', 'cron', 'Domain refresh skipped: no public IPv4 detected.', ['pending' => count($pending)]);
    exit(0);
}

$changed = 0;
foreach ($pending as $entry) {
    $name = (string)$entry['name'];
    $previous = (string)($entry['status'] ?? '');

    try {
        $outcome = domains_advance($settings, $name, $publicIp, $publicIp, (string)($entry['zone_id'] ?? ''));
    } catch (Throwable $e) {
        ytds_log('error', 'cron', 'Domain refresh failed: ' . $e->getMessage(), ['domain' => $name]);
        continue;
    }

    $list = DomainRegistry::put(
        $list,
        $name,
        (string)($entry['source'] ?? 'registered'),
        $outcome->zoneId,
        time(),
        $outcome->status,
        $outcome->message
    );
    $changed++;

    if ($outcome->status !== $previous) {
        ytds_log('info', 'cron', 'Domain status changed to ' . $outcome->status, [
            'domain' => $name,
            'from' => $previous,
            'message' => $outcome->message,
        ]);
    }
}

if ($changed === 0) {
    exit(0);
}

// The sweep takes seconds of network time, so the copy loaded at the top is stale by
// now. Saving it wholesale would silently undo anything changed in the panel meanwhile
// — an API token, for instance. Re-read and touch only the domain list.
try {
    $fresh = $manager->load();
    $fresh['managedDomains'] = $list;
    $manager->save($fresh, $manager->revision());
} catch (Throwable $e) {
    ytds_log('warning', 'cron', 'Domain refresh could not save: ' . $e->getMessage(), ['checked' => $changed]);
}
