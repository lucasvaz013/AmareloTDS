<?php

require_once __DIR__ . '/requestfunc.php';
require_once __DIR__ . '/integrations.php';

/** The subdomain every managed domain gets, pointed at this server. */
const YTDS_HOSTNAME_PREFIX = 'ytds';

/** One step of a multi-stage operation, so the UI can show what worked and what did not. */
final class DomainStep implements JsonSerializable
{
    public function __construct(
        public string $key,
        public bool $ok,
        public string $message,
        public array $details = [],
    ) {
    }

    public function jsonSerialize(): array
    {
        return ['key' => $this->key, 'ok' => $this->ok, 'message' => $this->message, 'details' => $this->details];
    }
}

/** Where a domain sits on the way to being usable. */
final class DomainStatus
{
    /** Work still pending — the panel shows a spinner and keeps re-checking. */
    public const CHECKING = 'checking';
    /** Everything done and the record resolves. */
    public const READY = 'ready';
    /** Stuck on something a person has to fix, such as a token permission. */
    public const ERROR = 'error';
}

final class DomainOutcome implements JsonSerializable
{
    /** @var list<DomainStep> */
    public array $steps = [];
    public bool $ok = false;
    public string $hostname = '';
    public string $message = '';

    /**
     * Set the moment Namecheap confirms the purchase. The caller stores the domain on
     * the strength of this alone: money has changed hands, so the domain must appear in
     * the list even if every later step fails.
     */
    public bool $registered = false;
    public string $zoneId = '';
    public string $status = DomainStatus::CHECKING;

    public function add(DomainStep $step): DomainStep
    {
        $this->steps[] = $step;
        return $step;
    }

    public function fail(string $message, string $status = DomainStatus::ERROR): DomainOutcome
    {
        $this->ok = false;
        $this->message = $message;
        $this->status = $status;
        return $this;
    }

    public function succeed(string $hostname, string $message, string $status = DomainStatus::READY): DomainOutcome
    {
        $this->ok = true;
        $this->hostname = $hostname;
        $this->message = $message;
        $this->status = $status;
        return $this;
    }

    public function jsonSerialize(): array
    {
        return [
            'ok' => $this->ok,
            'hostname' => $this->hostname,
            'message' => $this->message,
            'status' => $this->status,
            'registered' => $this->registered,
            'zone_id' => $this->zoneId,
            'steps' => $this->steps,
        ];
    }
}

final class DomainName
{
    /** Accepts a bare registrable domain; rejects anything carrying a scheme or path. */
    public static function normalize(string $input): string
    {
        $value = strtolower(trim($input));
        $value = preg_replace('#^https?://#', '', $value) ?? '';
        $value = explode('/', $value)[0];
        $value = explode('?', $value)[0];
        $value = rtrim($value, '.');
        return trim($value);
    }

    public static function isValid(string $domain): bool
    {
        return preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) === 1;
    }

    /** @return array{0: string, 1: string} SLD and TLD as Namecheap wants them. */
    public static function split(string $domain): array
    {
        $dot = strpos($domain, '.');
        return $dot === false ? [$domain, ''] : [substr($domain, 0, $dot), substr($domain, $dot + 1)];
    }

    public static function hostname(string $domain): string
    {
        return YTDS_HOSTNAME_PREFIX . '.' . $domain;
    }
}

final class NamecheapDomains
{
    /** @param array<string, mixed> $settings @return array<string, mixed> */
    private static function call(array $settings, string $command, array $params, string $clientIp): array
    {
        $url = !empty($settings['namecheapSandbox'])
            ? NamecheapIntegration::SANDBOX_URL
            : NamecheapIntegration::PRODUCTION_URL;

        $response = HttpClient::send(new HttpRequest(
            id: 'namecheap-' . $command,
            url: $url,
            method: 'POST',
            body: [
                'ApiUser' => trim((string)($settings['namecheapApiUser'] ?? '')),
                'ApiKey' => trim((string)($settings['namecheapApiKey'] ?? '')),
                'UserName' => trim((string)($settings['namecheapUsername'] ?? '')) ?: trim((string)($settings['namecheapApiUser'] ?? '')),
                'ClientIp' => $clientIp,
                'Command' => $command,
            ] + $params,
            timeout: 60,
            connectTimeout: 8,
            verifyPeer: true,
            verifyHost: 2,
            userAgent: 'AmareloTDS Domains',
            forceIpv4: true,
        ));

        return self::parse((string)($response->content === false ? '' : $response->content), $response->error);
    }

    /** @return array{ok: bool, message: string, code: string, xml: ?SimpleXMLElement} */
    public static function parse(string $xml, string $transportError = ''): array
    {
        if ($transportError !== '') {
            return ['ok' => false, 'message' => 'Could not reach Namecheap: ' . $transportError, 'code' => 'transport_error', 'xml' => null];
        }
        if (trim($xml) === '') {
            return ['ok' => false, 'message' => 'Namecheap returned an empty response.', 'code' => 'bad_response', 'xml' => null];
        }

        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($doc === false) {
            return ['ok' => false, 'message' => 'Namecheap returned a response that could not be read.', 'code' => 'bad_response', 'xml' => null];
        }

        // Authentication and business errors both arrive as HTTP 200.
        if (strtoupper(trim((string)($doc['Status'] ?? ''))) === 'OK') {
            return ['ok' => true, 'message' => '', 'code' => 'ok', 'xml' => $doc];
        }

        $doc->registerXPathNamespace('nc', NamecheapIntegration::XML_NAMESPACE);
        foreach (['//nc:Errors/nc:Error', '//Errors/Error'] as $path) {
            $nodes = $doc->xpath($path);
            if (is_array($nodes) && $nodes !== []) {
                $code = trim((string)($nodes[0]['Number'] ?? ''));
                $text = trim((string)$nodes[0]);
                return ['ok' => false, 'message' => $text !== '' ? $text : 'Namecheap rejected the request.', 'code' => 'nc_' . $code, 'xml' => $doc];
            }
        }
        return ['ok' => false, 'message' => 'Namecheap rejected the request.', 'code' => 'nc_unknown', 'xml' => $doc];
    }

    /** Free call — no side effect, no charge. */
    public static function isAvailable(array $settings, string $domain, string $clientIp): DomainStep
    {
        $result = self::call($settings, 'namecheap.domains.check', ['DomainList' => $domain], $clientIp);
        if (!$result['ok']) {
            return new DomainStep('availability', false, $result['message'], ['code' => $result['code']]);
        }

        $doc = $result['xml'];
        $doc->registerXPathNamespace('nc', NamecheapIntegration::XML_NAMESPACE);
        foreach (['//nc:DomainCheckResult', '//DomainCheckResult'] as $path) {
            $nodes = $doc->xpath($path);
            if (is_array($nodes) && $nodes !== []) {
                $available = strtolower(trim((string)($nodes[0]['Available'] ?? 'false'))) === 'true';
                return new DomainStep(
                    'availability',
                    $available,
                    $available ? $domain . ' is available.' : $domain . ' is already taken.',
                    ['available' => $available]
                );
            }
        }
        return new DomainStep('availability', false, 'Namecheap did not report availability for ' . $domain . '.');
    }

    /**
     * Spends money. Called only after an explicit confirmation in the UI, and never
     * retried automatically — a retry after a partial failure could buy twice.
     */
    public static function register(array $settings, string $domain, int $years, string $clientIp): DomainStep
    {
        $resolved = self::resolveProfile($settings, $clientIp);
        if (!$resolved['ok']) {
            return new DomainStep('register', false, $resolved['message']);
        }
        $profile = $resolved['profile'];

        $params = ['DomainName' => $domain, 'Years' => max(1, min(10, $years))];
        // Namecheap wants the same block repeated for all four contact roles.
        foreach (['Registrant', 'Admin', 'Tech', 'AuxBilling'] as $role) {
            foreach ($profile as $field => $value) {
                $params[$role . $field] = (string)$value;
            }
        }

        $result = self::call($settings, 'namecheap.domains.create', $params, $clientIp);
        return new DomainStep('register', $result['ok'], $result['ok'] ? 'Domain registered at Namecheap.' : $result['message'], ['code' => $result['code']]);
    }

    /** @param list<string> $nameservers */
    public static function setNameservers(array $settings, string $domain, array $nameservers, string $clientIp): DomainStep
    {
        [$sld, $tld] = DomainName::split($domain);
        $result = self::call($settings, 'namecheap.domains.dns.setCustom', [
            'SLD' => $sld,
            'TLD' => $tld,
            'Nameservers' => implode(',', $nameservers),
        ], $clientIp);

        return new DomainStep(
            'nameservers',
            $result['ok'],
            $result['ok'] ? 'Nameservers pointed at Cloudflare.' : $result['message'],
            ['nameservers' => $nameservers, 'code' => $result['code']]
        );
    }

    /**
     * The contact block used for a registration. The account's own address book comes
     * first — that is what makes registering "just type the domain" — and the profile
     * stored here is only an override for accounts whose address book is unusable.
     *
     * @return array{ok: bool, profile: array<string, string>, source: string, label: string, message: string}
     */
    public static function resolveProfile(array $settings, string $clientIp): array
    {
        $account = self::fetchAccountProfile($settings, $clientIp);
        if ($account['ok']) {
            return ['ok' => true, 'profile' => $account['profile'], 'source' => 'namecheap', 'label' => $account['label'], 'message' => ''];
        }

        $local = is_array($settings['registrantProfile'] ?? null) ? $settings['registrantProfile'] : [];
        if (self::missingProfileFields($local) === []) {
            return ['ok' => true, 'profile' => $local, 'source' => 'local', 'label' => 'saved here', 'message' => ''];
        }

        // Neither source worked: hand back what Namecheap actually said, so the operator
        // can act on it rather than guess.
        return [
            'ok' => false,
            'profile' => [],
            'source' => 'none',
            'label' => '',
            'message' => 'Namecheap did not supply usable contact details (' . $account['message']
                . '). Add a contact profile to your Namecheap account, or fill the registrant profile below as a fallback.',
        ];
    }

    /**
     * Namecheap accounts carry an address book, and its entries hold the same fields a
     * registration needs. Reading the default entry is what lets registration be "just
     * type the domain" without anyone retyping their address here.
     *
     * @return array{ok: bool, profile: array<string, string>, label: string, message: string}
     */
    public static function fetchAccountProfile(array $settings, string $clientIp): array
    {
        $list = self::call($settings, 'namecheap.users.address.getList', [], $clientIp);
        if (!$list['ok']) {
            return ['ok' => false, 'profile' => [], 'label' => '', 'message' => $list['message']];
        }

        $entries = self::rows($list['xml'], 'AddressGetListResult');
        if ($entries === []) {
            return ['ok' => false, 'profile' => [], 'label' => '', 'message' => 'No address profile found in the Namecheap account.'];
        }

        // Prefer the account default; fall back to the first entry.
        $chosen = $entries[0];
        foreach ($entries as $entry) {
            if (strtolower(trim((string)($entry['IsDefault'] ?? $entry['Default_YN'] ?? ''))) === 'true') {
                $chosen = $entry;
                break;
            }
        }

        $addressId = trim((string)($chosen['AddressId'] ?? ''));
        if ($addressId === '') {
            return ['ok' => false, 'profile' => [], 'label' => '', 'message' => 'Namecheap returned an address profile without an id.'];
        }

        $info = self::call($settings, 'namecheap.users.address.getInfo', ['AddressId' => $addressId], $clientIp);
        if (!$info['ok']) {
            return ['ok' => false, 'profile' => [], 'label' => '', 'message' => $info['message']];
        }

        $fields = self::fields($info['xml'], 'GetAddressInfoResult');
        $profile = self::mapAddressToContact($fields);
        $missing = self::missingProfileFields($profile);
        if ($missing !== []) {
            return [
                'ok' => false,
                'profile' => $profile,
                'label' => trim((string)($chosen['AddressName'] ?? '')),
                'message' => 'The Namecheap address profile is missing: ' . implode(', ', $missing) . '.',
            ];
        }

        return ['ok' => true, 'profile' => $profile, 'label' => trim((string)($chosen['AddressName'] ?? 'Namecheap address')), 'message' => ''];
    }

    /**
     * The address book calls the postcode `Zip`, while registration wants `PostalCode`.
     *
     * @param array<string, string> $fields
     * @return array<string, string>
     */
    public static function mapAddressToContact(array $fields): array
    {
        $get = static fn(string ...$names): string => (static function () use ($names, $fields): string {
            foreach ($names as $name) {
                $value = trim((string)($fields[$name] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
            return '';
        })();

        return [
            'FirstName' => $get('FirstName'),
            'LastName' => $get('LastName'),
            'Address1' => $get('Address1'),
            'City' => $get('City'),
            'StateProvince' => $get('StateProvince'),
            'PostalCode' => $get('PostalCode', 'Zip'),
            'Country' => $get('Country'),
            'Phone' => $get('Phone'),
            'EmailAddress' => $get('EmailAddress', 'Email'),
        ];
    }

    /** @return list<array<string, string>> attributes of each repeated row */
    public static function rows(?SimpleXMLElement $doc, string $container): array
    {
        if ($doc === null) {
            return [];
        }
        $doc->registerXPathNamespace('nc', NamecheapIntegration::XML_NAMESPACE);
        foreach (['//nc:' . $container . '/*', '//' . $container . '/*'] as $path) {
            $nodes = $doc->xpath($path);
            if (is_array($nodes) && $nodes !== []) {
                return array_map(static function (SimpleXMLElement $node): array {
                    $row = [];
                    foreach ($node->attributes() ?? [] as $key => $value) {
                        $row[(string)$key] = (string)$value;
                    }
                    return $row;
                }, $nodes);
            }
        }
        return [];
    }

    /** @return array<string, string> child elements and attributes of a single result node */
    public static function fields(?SimpleXMLElement $doc, string $container): array
    {
        if ($doc === null) {
            return [];
        }
        $doc->registerXPathNamespace('nc', NamecheapIntegration::XML_NAMESPACE);
        foreach (['//nc:' . $container, '//' . $container] as $path) {
            $nodes = $doc->xpath($path);
            if (is_array($nodes) && $nodes !== []) {
                $fields = [];
                foreach ($nodes[0]->attributes() ?? [] as $key => $value) {
                    $fields[(string)$key] = (string)$value;
                }
                foreach ($nodes[0]->children() as $child) {
                    $fields[$child->getName()] = trim((string)$child);
                }
                return $fields;
            }
        }
        return [];
    }

    /** @return list<string> */
    public static function missingProfileFields(array $profile): array
    {
        $missing = [];
        foreach (['FirstName', 'LastName', 'Address1', 'City', 'StateProvince', 'PostalCode', 'Country', 'Phone', 'EmailAddress'] as $field) {
            if (trim((string)($profile[$field] ?? '')) === '') {
                $missing[] = $field;
            }
        }
        return $missing;
    }
}

final class CloudflareDomains
{
    public const API_BASE = 'https://api.cloudflare.com/client/v4';

    private static function call(array $settings, string $method, string $path, ?array $body = null): array
    {
        $token = trim((string)($settings['cloudflareApiToken'] ?? ''));
        $response = HttpClient::send(new HttpRequest(
            id: 'cloudflare-' . strtolower($method),
            url: self::API_BASE . $path,
            method: $method,
            body: $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES),
            headers: array_filter([
                'Authorization: Bearer ' . $token,
                $body === null ? null : 'Content-Type: application/json',
            ]),
            timeout: 20,
            connectTimeout: 6,
            verifyPeer: true,
            verifyHost: 2,
            userAgent: 'AmareloTDS Domains',
        ));

        return self::parse((string)($response->content === false ? '' : $response->content), $response->error);
    }

    /** @return array{ok: bool, message: string, result: mixed} */
    public static function parse(string $body, string $transportError = ''): array
    {
        if ($transportError !== '') {
            return ['ok' => false, 'message' => 'Could not reach Cloudflare: ' . $transportError, 'result' => null];
        }
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return ['ok' => false, 'message' => 'Cloudflare returned a response that could not be read.', 'result' => null];
        }
        if (($payload['success'] ?? false) !== true) {
            $error = is_array($payload['errors'] ?? null) ? ($payload['errors'][0] ?? []) : [];
            $message = trim((string)($error['message'] ?? ''));
            return ['ok' => false, 'message' => $message !== '' ? $message : 'Cloudflare rejected the request.', 'result' => null];
        }
        return ['ok' => true, 'message' => '', 'result' => $payload['result'] ?? null];
    }

    /**
     * A token without the account scope does not error on /accounts — it answers
     * success with an empty list, which reads as "no account exists". Every zone
     * carries its own account, so that is the second place to look before giving up.
     */
    public static function accountId(array $settings): string
    {
        $result = self::call($settings, 'GET', '/accounts?per_page=1');
        if ($result['ok'] && is_array($result['result']) && $result['result'] !== []) {
            $id = (string)($result['result'][0]['id'] ?? '');
            if ($id !== '') {
                return $id;
            }
        }

        $zones = self::call($settings, 'GET', '/zones?per_page=1');
        if ($zones['ok'] && is_array($zones['result']) && $zones['result'] !== []) {
            return (string)($zones['result'][0]['account']['id'] ?? '');
        }

        return '';
    }

    /** @return array{ok: bool, message: string, zone: ?array} */
    public static function findZone(array $settings, string $domain): array
    {
        $result = self::call($settings, 'GET', '/zones?name=' . rawurlencode($domain));
        if (!$result['ok']) {
            return ['ok' => false, 'message' => $result['message'], 'zone' => null];
        }
        $zones = is_array($result['result']) ? $result['result'] : [];
        return ['ok' => true, 'message' => '', 'zone' => $zones[0] ?? null];
    }

    public static function createZone(array $settings, string $domain, string $accountId): DomainStep
    {
        $body = ['name' => $domain, 'type' => 'full'];
        if ($accountId !== '') {
            $body['account'] = ['id' => $accountId];
        }
        $result = self::call($settings, 'POST', '/zones', $body);
        if (!$result['ok']) {
            return new DomainStep('zone', false, $result['message']);
        }

        $zone = is_array($result['result']) ? $result['result'] : [];
        return new DomainStep('zone', true, 'Zone created at Cloudflare.', [
            'zone_id' => (string)($zone['id'] ?? ''),
            'status' => (string)($zone['status'] ?? ''),
            'name_servers' => array_values(array_filter((array)($zone['name_servers'] ?? []))),
        ]);
    }

    /**
     * Proxy stays off on purpose: a proxied record answers with Cloudflare addresses,
     * and the TDS needs visitors to reach this server directly so it sees their real IP.
     */
    public static function upsertYtdsRecord(array $settings, string $zoneId, string $domain, string $ip): DomainStep
    {
        $hostname = DomainName::hostname($domain);
        $existing = self::call($settings, 'GET', '/zones/' . rawurlencode($zoneId) . '/dns_records?type=A&name=' . rawurlencode($hostname));
        $body = ['type' => 'A', 'name' => $hostname, 'content' => $ip, 'ttl' => 1, 'proxied' => false];

        $records = ($existing['ok'] && is_array($existing['result'])) ? $existing['result'] : [];
        if ($records !== []) {
            $recordId = (string)($records[0]['id'] ?? '');
            $result = self::call($settings, 'PUT', '/zones/' . rawurlencode($zoneId) . '/dns_records/' . rawurlencode($recordId), $body);
            return new DomainStep('record', $result['ok'], $result['ok'] ? $hostname . ' updated to point here.' : $result['message'], ['hostname' => $hostname]);
        }

        $result = self::call($settings, 'POST', '/zones/' . rawurlencode($zoneId) . '/dns_records', $body);
        return new DomainStep('record', $result['ok'], $result['ok'] ? $hostname . ' created and pointed here.' : $result['message'], ['hostname' => $hostname]);
    }

    /** @return array{ok:bool,message:string,records:list<array<string,mixed>>} */
    public static function listDnsRecords(array $settings, string $zoneId, string $hostname): array
    {
        $result = self::call(
            $settings,
            'GET',
            '/zones/' . rawurlencode($zoneId) . '/dns_records?name=' . rawurlencode($hostname) . '&per_page=100'
        );
        return [
            'ok' => $result['ok'],
            'message' => $result['message'],
            'records' => $result['ok'] && is_array($result['result']) ? array_values($result['result']) : [],
        ];
    }

    /** @param array<string,mixed> $body */
    public static function writeDnsRecord(array $settings, string $zoneId, string $recordId, array $body): DomainStep
    {
        $path = '/zones/' . rawurlencode($zoneId) . '/dns_records';
        $method = 'POST';
        if ($recordId !== '') {
            $path .= '/' . rawurlencode($recordId);
            $method = 'PUT';
        }
        $result = self::call($settings, $method, $path, $body);
        return new DomainStep(
            'record',
            $result['ok'],
            $result['ok'] ? (string)($body['name'] ?? 'DNS record') . ' points directly to this server.' : $result['message']
        );
    }

    public static function deleteDnsRecord(array $settings, string $zoneId, string $recordId): DomainStep
    {
        $result = self::call(
            $settings,
            'DELETE',
            '/zones/' . rawurlencode($zoneId) . '/dns_records/' . rawurlencode($recordId)
        );
        return new DomainStep('record_cleanup', $result['ok'], $result['ok'] ? 'Conflicting address record removed.' : $result['message']);
    }
}

final class DomainVerifier
{
    /** Cloudflare's published proxy ranges start in these blocks. */
    private const CLOUDFLARE_PREFIXES = ['104.16.', '104.17.', '104.18.', '104.19.', '104.20.', '104.21.', '104.22.', '104.23.', '104.24.', '104.25.', '104.26.', '104.27.', '172.64.', '172.65.', '172.66.', '172.67.', '162.158.', '162.159.', '198.41.'];

    /**
     * @param list<string> $resolved
     * Kept free of DNS I/O so the verdict logic is testable without a resolver.
     */
    public static function judge(array $resolved, string $expectedIp, string $hostname): DomainStep
    {
        if ($resolved === []) {
            return new DomainStep('dns', false, $hostname . ' does not resolve to any IPv4 address yet. DNS can take a few minutes.', ['resolved' => []]);
        }
        if (in_array($expectedIp, $resolved, true)) {
            return new DomainStep('dns', true, $hostname . ' points here.', ['resolved' => $resolved]);
        }
        foreach ($resolved as $ip) {
            foreach (self::CLOUDFLARE_PREFIXES as $prefix) {
                if (str_starts_with($ip, $prefix)) {
                    return new DomainStep('dns', false, $hostname . ' resolves to Cloudflare, which means the proxy (orange cloud) is on. Turn it off so traffic reaches this server directly.', ['resolved' => $resolved]);
                }
            }
        }
        return new DomainStep('dns', false, $hostname . ' resolves to ' . implode(', ', $resolved) . ', not to ' . $expectedIp . '.', ['resolved' => $resolved]);
    }

    /** @return list<string> */
    public static function resolve(string $hostname): array
    {
        $records = @dns_get_record($hostname, DNS_A);
        if (!is_array($records)) {
            return [];
        }
        $ips = [];
        foreach ($records as $record) {
            $ip = trim((string)($record['ip'] ?? ''));
            if ($ip !== '') {
                $ips[] = $ip;
            }
        }
        return array_values(array_unique($ips));
    }
}

final class DomainRegistry
{
    /** @param array<string, mixed> $settings @return list<array<string, mixed>> */
    public static function all(array $settings): array
    {
        $list = $settings['managedDomains'] ?? [];
        return is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
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
        $entry = [
            'name' => $domain,
            'hostname' => DomainName::hostname($domain),
            'source' => $source,
            'zone_id' => $zoneId,
            'added' => $now,
            'status' => $status,
            'detail' => $detail,
            'checked' => $now,
            'campaign_id' => 0,
        ];

        foreach ($current as $index => $existing) {
            if (strcasecmp((string)($existing['name'] ?? ''), $domain) === 0) {
                // Keep the original date so re-checking a domain does not look like a
                // fresh addition.
                $entry['added'] = (int)($existing['added'] ?? $now);
                // A zone id already known is not thrown away by a later step that has none.
                if ($entry['zone_id'] === '') {
                    $entry['zone_id'] = (string)($existing['zone_id'] ?? '');
                }
                // The campaign a domain routes to is set separately and must survive
                // every status refresh.
                $entry['campaign_id'] = (int)($existing['campaign_id'] ?? 0);
                $current[$index] = $entry;
                return array_values($current);
            }
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
}

/**
 * Buys the domain, moves it to Cloudflare and points ytds.<domain> at this server.
 *
 * Stops at the first failed step and reports what did and did not happen: a partial
 * failure after the purchase must never look like "nothing occurred", or the operator
 * may buy the same domain twice.
 */
/**
 * The single definition of "ready", shared by every path that adds a domain.
 *
 * Three things had drifted apart: registering demanded nginx, while importing from
 * Cloudflare and pointing by hand called a domain ready as soon as DNS resolved — even
 * though the host was still landing on the default nginx site with no certificate.
 */
function domains_finalize(DomainOutcome $outcome, string $domain, string $vpsIp, string $appRoot): DomainOutcome
{
    $hostname = DomainName::hostname($domain);

    $resolved = DomainVerifier::resolve($hostname);
    $dns = $outcome->add(DomainVerifier::judge($resolved, $vpsIp, $hostname));
    if (!$dns->ok) {
        // Not resolving yet is a matter of waiting; resolving elsewhere needs a person.
        return $outcome->fail(
            $dns->message,
            $resolved === [] ? DomainStatus::CHECKING : DomainStatus::ERROR
        );
    }

    $nginx = $outcome->add(DomainProvisioner::statusFor(DomainProvisioner::read($appRoot), $hostname));
    if (!$nginx->ok) {
        return $outcome->fail(
            $nginx->message,
            ($nginx->details['exhausted'] ?? false) === true ? DomainStatus::ERROR : DomainStatus::CHECKING
        );
    }

    return $outcome->succeed($hostname, $hostname . ' is ready to use as a campaign link.');
}

function domains_register(array $settings, string $domain, int $years, string $vpsIp, string $clientIp): DomainOutcome
{
    $outcome = new DomainOutcome();

    $availability = $outcome->add(NamecheapDomains::isAvailable($settings, $domain, $clientIp));
    if (!$availability->ok) {
        return $outcome->fail($availability->message);
    }

    $registration = $outcome->add(NamecheapDomains::register($settings, $domain, $years, $clientIp));
    if (!$registration->ok) {
        return $outcome->fail($registration->message);
    }

    // From here the domain is paid for. Everything that follows can fail without the
    // purchase being forgotten.
    $outcome->registered = true;

    return domains_advance($settings, $domain, $vpsIp, $clientIp, '', $outcome);
}

/**
 * Moves a domain along whatever is still missing: create the zone, point the
 * nameservers, create the record, confirm activation. Safe to call repeatedly — each
 * step checks the current state before acting, so a re-run costs nothing when there is
 * nothing to do.
 */
function domains_advance(
    array $settings,
    string $domain,
    string $vpsIp,
    string $clientIp,
    string $knownZoneId = '',
    ?DomainOutcome $outcome = null
): DomainOutcome {
    $outcome = $outcome ?? new DomainOutcome();

    $lookup = CloudflareDomains::findZone($settings, $domain);
    if (!$lookup['ok']) {
        $outcome->add(new DomainStep('zone', false, $lookup['message']));
        return $outcome->fail('Cloudflare lookup failed: ' . $lookup['message']);
    }

    $zone = $lookup['zone'];
    if ($zone === null) {
        $created = $outcome->add(CloudflareDomains::createZone($settings, $domain, CloudflareDomains::accountId($settings)));
        if (!$created->ok) {
            $outcome->zoneId = $knownZoneId;
            return $outcome->fail(
                'The domain is registered, but the Cloudflare zone could not be created: ' . $created->message,
                DomainStatus::ERROR
            );
        }
        $zone = [
            'id' => (string)($created->details['zone_id'] ?? ''),
            'status' => (string)($created->details['status'] ?? ''),
            'name_servers' => (array)($created->details['name_servers'] ?? []),
        ];
    } else {
        $outcome->add(new DomainStep('zone', true, 'Zone present at Cloudflare (' . (string)($zone['status'] ?? '?') . ').', [
            'zone_id' => (string)($zone['id'] ?? ''),
            'status' => (string)($zone['status'] ?? ''),
        ]));
    }

    $outcome->zoneId = (string)($zone['id'] ?? $knownZoneId);
    $zoneStatus = strtolower((string)($zone['status'] ?? ''));

    // Only worth pointing the nameservers while the zone has not activated; once it is
    // active they are already correct and Namecheap would just repeat the work.
    $nameservers = array_values(array_filter((array)($zone['name_servers'] ?? [])));
    if ($zoneStatus !== 'active' && $nameservers !== [] && !domains_nameservers_match($domain, $nameservers)) {
        $ns = $outcome->add(NamecheapDomains::setNameservers($settings, $domain, $nameservers, $clientIp));
        if (!$ns->ok) {
            return $outcome->fail('Zone exists, but pointing the nameservers failed: ' . $ns->message);
        }
    }

    $record = $outcome->add(CloudflareDomains::upsertYtdsRecord($settings, $outcome->zoneId, $domain, $vpsIp));
    if (!$record->ok) {
        return $outcome->fail('Zone exists, but the DNS record failed: ' . $record->message);
    }

    $hostname = DomainName::hostname($domain);
    if ($zoneStatus !== 'active') {
        return $outcome->fail(
            $hostname . ' is set up, but Cloudflare has not activated the zone yet. Nameserver changes usually take minutes and can take hours.',
            DomainStatus::CHECKING
        );
    }

    // __DIR__ is the application root in both layouts: code/ in the repository, and the
    // document root on a server, where the installer copies code/'s contents up a level.
    return domains_finalize($outcome, $domain, $vpsIp, __DIR__);
}

/** @param list<string> $expected */
function domains_nameservers_match(string $domain, array $expected): bool
{
    $records = @dns_get_record($domain, DNS_NS);
    if (!is_array($records) || $records === []) {
        return false;
    }
    $current = [];
    foreach ($records as $record) {
        $current[] = strtolower(trim((string)($record['target'] ?? '')));
    }
    foreach ($expected as $ns) {
        if (!in_array(strtolower(trim($ns)), $current, true)) {
            return false;
        }
    }
    return true;
}

/** Points an existing Cloudflare zone at this server. Buys nothing. */
function domains_cloudflare_sync(array $settings, string $domain, string $vpsIp): DomainOutcome
{
    $outcome = new DomainOutcome();

    $lookup = CloudflareDomains::findZone($settings, $domain);
    if (!$lookup['ok']) {
        $outcome->add(new DomainStep('zone', false, $lookup['message']));
        return $outcome->fail($lookup['message']);
    }
    if ($lookup['zone'] === null) {
        $message = $domain . ' is not in this Cloudflare account. Add it there first, or use manual sync.';
        $outcome->add(new DomainStep('zone', false, $message));
        return $outcome->fail($message);
    }

    $zoneId = (string)($lookup['zone']['id'] ?? '');
    $status = (string)($lookup['zone']['status'] ?? '');
    $outcome->add(new DomainStep('zone', true, 'Zone found at Cloudflare (' . ($status !== '' ? $status : 'unknown status') . ').', ['zone_id' => $zoneId, 'status' => $status]));

    $record = $outcome->add(CloudflareDomains::upsertYtdsRecord($settings, $zoneId, $domain, $vpsIp));
    if (!$record->ok) {
        return $outcome->fail($record->message);
    }

    if ($status !== 'active') {
        return $outcome->fail(
            DomainName::hostname($domain) . ' is set, but the zone is ' . $status
                . ' at Cloudflare and will only answer once it activates.',
            DomainStatus::CHECKING
        );
    }

    return domains_finalize($outcome, $domain, $vpsIp, __DIR__);
}

/** Confirms the operator pointed the record by hand. Touches no API. */
function domains_manual_check(string $domain, string $vpsIp): DomainOutcome
{
    return domains_finalize(new DomainOutcome(), $domain, $vpsIp, __DIR__);
}

/**
 * The nginx side of a domain. DNS pointing here is not enough: without a server block
 * the host lands on the default site, and without a certificate HTTPS fails outright.
 *
 * Provisioning needs root, and PHP runs as www-data, so the work happens in a root cron
 * (cron/provision_domains.php) and this class only reads what it recorded.
 */
final class DomainProvisioner
{
    public const STATE_FILE = 'tmp/domain-nginx.json';
    /** Let's Encrypt caps issuance per week, so a failing host is not retried forever. */
    public const MAX_ATTEMPTS = 3;

    public static function statePath(string $root): string
    {
        return $root . DIRECTORY_SEPARATOR . self::STATE_FILE;
    }

    /** @return array<string, array<string, mixed>> keyed by hostname */
    public static function read(string $root): array
    {
        $raw = @file_get_contents(self::statePath($root));
        if ($raw === false) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, array<string, mixed>> $state */
    public static function write(string $root, array $state): bool
    {
        $path = self::statePath($root);
        @mkdir(dirname($path), 0775, true);
        return file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
    }

    /** @param array<string, array<string, mixed>> $state */
    public static function statusFor(array $state, string $hostname): DomainStep
    {
        $entry = $state[$hostname] ?? null;
        if (!is_array($entry)) {
            return new DomainStep('nginx', false, 'Waiting for the server to publish ' . $hostname . ' (runs every few minutes).');
        }
        if (($entry['ok'] ?? false) === true) {
            return new DomainStep('nginx', true, $hostname . ' is served over HTTPS.', ['checked' => $entry['checked'] ?? 0]);
        }

        $attempts = (int)($entry['attempts'] ?? 0);
        $message = trim((string)($entry['message'] ?? 'Could not publish the host.'));
        if ($attempts >= self::MAX_ATTEMPTS) {
            return new DomainStep('nginx', false, $message . ' Giving up after ' . $attempts . ' attempts — fix the cause and use Check now.', ['exhausted' => true]);
        }
        return new DomainStep('nginx', false, $message . ' Retrying automatically.', ['attempts' => $attempts]);
    }
}
