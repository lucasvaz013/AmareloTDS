<?php

/**
 * Publishes managed domains on this server: an nginx server block plus an HTTPS
 * certificate for each ytds.<domain>.
 *
 * Runs as ROOT, unlike every other cron here, because writing to /etc/nginx and running
 * certbot need it. It therefore takes no input from the panel — it reads the domain list
 * and reconciles, so a compromised panel cannot steer it into arbitrary commands.
 *
 * The work itself is delegated to install.sh --add-domain, which already writes the
 * server block, tests the config, reloads nginx and calls certbot.
 *
 * Installed by install.sh as /etc/cron.d/amarelotds-provision.
 */

require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/../domains.php';
require_once __DIR__ . '/../logging.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);

if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "provision_domains.php must run as root\n");
    exit(1);
}

$settings = (new SettingsManager($root))->load();
$state = DomainProvisioner::read($root);
$changed = false;

foreach (DomainRegistry::all($settings) as $entry) {
    $domain = (string)($entry['name'] ?? '');
    if (!DomainName::isValid($domain)) {
        continue;
    }
    $hostname = DomainName::hostname($domain);

    $known = is_array($state[$hostname] ?? null) ? $state[$hostname] : [];
    if (($known['ok'] ?? false) === true && is_file('/etc/nginx/sites-enabled/' . $hostname)) {
        continue;
    }
    // Let's Encrypt limits issuance per week; a host that keeps failing is left alone
    // until someone looks at it.
    if ((int)($known['attempts'] ?? 0) >= DomainProvisioner::MAX_ATTEMPTS) {
        continue;
    }

    // certbot validates over HTTP, so provisioning before DNS resolves here only burns
    // an attempt against the rate limit.
    $resolved = DomainVerifier::resolve($hostname);
    $publicIp = integrations_detect_public_ipv4();
    if ($publicIp === '' || !in_array($publicIp, $resolved, true)) {
        $state[$hostname] = [
            'ok' => false,
            'attempts' => (int)($known['attempts'] ?? 0),
            'message' => $hostname . ' does not resolve here yet, so publishing was not attempted.',
            'checked' => time(),
        ];
        $changed = true;
        continue;
    }

    $command = sprintf(
        'AMARELOTDS_APP_DIR=%s AMARELOTDS_DOMAINS=%s bash %s --add-domain 2>&1',
        escapeshellarg($root),
        escapeshellarg($hostname),
        escapeshellarg($root . '/install.sh')
    );

    $output = [];
    $exit = 0;
    exec($command, $output, $exit);
    $tail = trim(implode("\n", array_slice($output, -6)));

    $state[$hostname] = [
        'ok' => $exit === 0,
        'attempts' => $exit === 0 ? 0 : (int)($known['attempts'] ?? 0) + 1,
        'message' => $exit === 0
            ? $hostname . ' published with an HTTPS certificate.'
            : 'Publishing failed: ' . ($tail !== '' ? $tail : 'exit code ' . $exit),
        'checked' => time(),
    ];
    $changed = true;

    ytds_log(
        $exit === 0 ? 'info' : 'error',
        'cron',
        $exit === 0 ? 'Domain published on nginx' : 'Domain publishing failed',
        ['hostname' => $hostname, 'exit' => $exit, 'output' => $tail]
    );
}

if ($changed) {
    DomainProvisioner::write($root, $state);
    // The panel reads this as www-data.
    @chown(DomainProvisioner::statePath($root), 'www-data');
    @chmod(DomainProvisioner::statePath($root), 0664);
}
