<?php

require_once __DIR__ . '/domains.php';

/** Persistent postback-only hostnames. They never participate in campaign routing. */
final class PostbackGatewayRegistry
{
    /** @param array<string, mixed> $settings @return list<array<string, mixed>> */
    public static function all(array $settings): array
    {
        $gateway = $settings['postbackGateway'] ?? [];
        if (!is_array($gateway) || (int)($gateway['version'] ?? 0) !== 1) {
            return [];
        }
        $domains = $gateway['domains'] ?? [];
        return is_array($domains) ? array_values(array_filter($domains, 'is_array')) : [];
    }

    /**
     * @param list<array<string, mixed>> $current
     * @return list<array<string, mixed>>
     */
    public static function put(
        array $current,
        string $domain,
        string $source,
        string $zoneId,
        int $now,
        string $status = DomainStatus::CHECKING,
        string $detail = ''
    ): array {
        $domain = DomainName::normalize($domain);
        $entry = [
            'name' => $domain,
            'url' => 'https://' . $domain . '/api/postback.php',
            'source' => $source,
            'zone_id' => $zoneId,
            'added' => $now,
            'checked' => $now,
            'status' => $status,
            'detail' => $detail,
        ];

        foreach ($current as $index => $existing) {
            if (strcasecmp((string)($existing['name'] ?? ''), $domain) !== 0) {
                continue;
            }
            $entry['added'] = (int)($existing['added'] ?? $now);
            if ($entry['zone_id'] === '') {
                $entry['zone_id'] = (string)($existing['zone_id'] ?? '');
            }
            $current[$index] = $entry;
            return array_values($current);
        }

        $current[] = $entry;
        return array_values($current);
    }

    /** @param list<array<string, mixed>> $current @return list<array<string, mixed>> */
    public static function remove(array $current, string $domain): array
    {
        return array_values(array_filter(
            $current,
            static fn(array $entry): bool => strcasecmp((string)($entry['name'] ?? ''), $domain) !== 0
        ));
    }

    /** @param list<array<string, mixed>> $domains @return array{version:int,domains:list<array<string,mixed>>} */
    public static function settings(array $domains): array
    {
        return ['version' => 1, 'domains' => array_values($domains)];
    }
}

final class PostbackGatewayDns
{
    /**
     * Produces an idempotent mutation plan while leaving MX/TXT and unrelated records alone.
     * @param list<array<string, mixed>> $records
     * @return array{keep_id:string,update_id:string,delete_ids:list<string>,create:bool,body:array<string,mixed>}
     */
    public static function plan(array $records, string $domain, string $ip): array
    {
        $address = array_values(array_filter($records, static function (array $record) use ($domain): bool {
            return strcasecmp((string)($record['name'] ?? ''), $domain) === 0
                && in_array(strtoupper((string)($record['type'] ?? '')), ['A', 'AAAA', 'CNAME'], true);
        }));
        $aRecords = array_values(array_filter(
            $address,
            static fn(array $record): bool => strtoupper((string)($record['type'] ?? '')) === 'A'
        ));

        $keep = null;
        foreach ($aRecords as $record) {
            if ((string)($record['content'] ?? '') === $ip && ($record['proxied'] ?? false) === false) {
                $keep = $record;
                break;
            }
        }
        $update = $keep === null ? ($aRecords[0] ?? null) : null;
        $survivorId = (string)(($keep ?? $update)['id'] ?? '');
        $deleteIds = [];
        foreach ($address as $record) {
            $id = (string)($record['id'] ?? '');
            if ($id !== '' && $id !== $survivorId) {
                $deleteIds[] = $id;
            }
        }

        return [
            'keep_id' => (string)($keep['id'] ?? ''),
            'update_id' => (string)($update['id'] ?? ''),
            'delete_ids' => $deleteIds,
            'create' => $keep === null && $update === null,
            'body' => ['type' => 'A', 'name' => $domain, 'content' => $ip, 'ttl' => 1, 'proxied' => false],
        ];
    }

    /** @param list<string> $aRecords @param list<string> $aaaaRecords */
    public static function judge(array $aRecords, array $aaaaRecords, string $expectedIp, string $domain): DomainStep
    {
        $aRecords = array_values(array_unique(array_filter($aRecords, 'is_string')));
        $aaaaRecords = array_values(array_unique(array_filter($aaaaRecords, 'is_string')));
        if ($aaaaRecords !== []) {
            return new DomainStep('dns', false, $domain . ' still has AAAA records; remove IPv6 before publishing the gateway.', ['aaaa' => $aaaaRecords]);
        }
        if ($aRecords === []) {
            return new DomainStep('dns', false, $domain . ' does not resolve to any IPv4 address yet.', ['resolved' => []]);
        }
        if (!in_array($expectedIp, $aRecords, true)) {
            return new DomainStep('dns', false, $domain . ' does not point to this server.', ['resolved' => $aRecords]);
        }
        if (count($aRecords) !== 1) {
            return new DomainStep('dns', false, $domain . ' has extra A records; the gateway requires exactly one origin.', ['resolved' => $aRecords]);
        }
        return new DomainStep('dns', true, $domain . ' points only to this server.', ['resolved' => $aRecords]);
    }

    /** @return list<string> */
    public static function resolve(string $domain, int $type): array
    {
        $records = @dns_get_record($domain, $type);
        if (!is_array($records)) {
            return [];
        }
        $field = $type === DNS_AAAA ? 'ipv6' : 'ip';
        return array_values(array_unique(array_filter(array_map(
            static fn(array $record): string => (string)($record[$field] ?? ''),
            $records
        ))));
    }
}

final class PostbackGatewayProvisioner
{
    public const MAX_ATTEMPTS = 3;
    public const STATE_FILE = 'tmp/postback-gateway-nginx.json';
    public const NGINX_MARKER = '# amarelotds-postback-gateway v1';

    public static function isManagedConfig(string $contents): bool
    {
        return str_starts_with($contents, self::NGINX_MARKER . "\n");
    }

    public static function statePath(string $root): string
    {
        return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::STATE_FILE;
    }

    /** @return array<string, array<string, mixed>> */
    public static function read(string $root): array
    {
        $contents = @file_get_contents(self::statePath($root));
        $decoded = $contents === false ? null : json_decode($contents, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, array<string, mixed>> $state */
    public static function write(string $root, array $state): void
    {
        $path = self::statePath($root);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create postback gateway state directory.');
        }
        $temp = $path . '.tmp.' . bin2hex(random_bytes(4));
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($temp, $json . "\n", LOCK_EX) === false || !rename($temp, $path)) {
            @unlink($temp);
            throw new RuntimeException('Could not write postback gateway state.');
        }
    }

    public static function statusFor(array $state, string $domain): DomainStep
    {
        $entry = is_array($state[$domain] ?? null) ? $state[$domain] : [];
        if (($entry['ok'] ?? false) === true) {
            return new DomainStep('nginx', true, $domain . ' serves only the HTTPS postback endpoint.', ['checked' => $entry['checked'] ?? 0]);
        }
        $attempts = (int)($entry['attempts'] ?? 0);
        $message = trim((string)($entry['message'] ?? 'Waiting for the server to publish the postback gateway.'));
        if ($attempts >= self::MAX_ATTEMPTS) {
            return new DomainStep('nginx', false, $message . ' Giving up after ' . $attempts . ' attempts — fix the cause and use Check now.', ['exhausted' => true]);
        }
        return new DomainStep('nginx', false, $message . ' Retrying automatically.', ['attempts' => $attempts]);
    }
}

/** Reconciles Cloudflare address records for a postback gateway. */
function postback_gateway_sync_cloudflare(array $settings, string $domain, string $vpsIp, string $appRoot = __DIR__): DomainOutcome
{
    $outcome = new DomainOutcome();
    if (filter_var($vpsIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return $outcome->fail('A valid public IPv4 address is required before configuring the postback gateway.');
    }
    $lookup = CloudflareDomains::findZone($settings, $domain);
    if (!$lookup['ok'] || !is_array($lookup['zone'])) {
        return $outcome->fail($lookup['message'] !== '' ? $lookup['message'] : 'Cloudflare zone not found.');
    }
    $outcome->zoneId = (string)($lookup['zone']['id'] ?? '');
    $listed = CloudflareDomains::listDnsRecords($settings, $outcome->zoneId, $domain);
    if (!$listed['ok']) {
        return $outcome->fail($listed['message']);
    }
    $plan = PostbackGatewayDns::plan($listed['records'], $domain, $vpsIp);
    foreach ($plan['delete_ids'] as $recordId) {
        $deleted = CloudflareDomains::deleteDnsRecord($settings, $outcome->zoneId, $recordId);
        if (!$deleted->ok) {
            return $outcome->fail($deleted->message);
        }
        $outcome->add($deleted);
    }
    if ($plan['update_id'] !== '') {
        $written = CloudflareDomains::writeDnsRecord($settings, $outcome->zoneId, $plan['update_id'], $plan['body']);
        if (!$written->ok) {
            return $outcome->fail($written->message);
        }
        $outcome->add($written);
    } elseif ($plan['create']) {
        $written = CloudflareDomains::writeDnsRecord($settings, $outcome->zoneId, '', $plan['body']);
        if (!$written->ok) {
            return $outcome->fail($written->message);
        }
        $outcome->add($written);
    }
    return postback_gateway_finalize($outcome, $domain, $vpsIp, $appRoot);
}

function postback_gateway_finalize(DomainOutcome $outcome, string $domain, string $vpsIp, string $appRoot): DomainOutcome
{
    $dns = $outcome->add(PostbackGatewayDns::judge(
        PostbackGatewayDns::resolve($domain, DNS_A),
        PostbackGatewayDns::resolve($domain, DNS_AAAA),
        $vpsIp,
        $domain
    ));
    if (!$dns->ok) {
        return $outcome->fail($dns->message, DomainStatus::CHECKING);
    }
    $nginx = $outcome->add(PostbackGatewayProvisioner::statusFor(PostbackGatewayProvisioner::read($appRoot), $domain));
    if (!$nginx->ok) {
        return $outcome->fail($nginx->message, ($nginx->details['exhausted'] ?? false) ? DomainStatus::ERROR : DomainStatus::CHECKING);
    }
    return $outcome->succeed($domain, $domain . ' is ready as a postback-only gateway.');
}
