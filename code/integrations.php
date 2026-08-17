<?php

require_once __DIR__ . '/requestfunc.php';

/**
 * Result of probing one external service. `configured` says whether credentials exist at
 * all, `ok` whether they actually work right now.
 */
final class IntegrationStatus implements JsonSerializable
{
    public function __construct(
        public string $service,
        public bool $configured,
        public bool $ok,
        public string $message,
        public string $code = '',
        public array $details = [],
    ) {
    }

    public static function missing(string $service, string $message): IntegrationStatus
    {
        return new IntegrationStatus($service, false, false, $message, 'not_configured');
    }

    public function jsonSerialize(): array
    {
        return [
            'service' => $this->service,
            'configured' => $this->configured,
            'ok' => $this->ok,
            'message' => $this->message,
            'code' => $this->code,
            'details' => $this->details,
        ];
    }
}

final class CloudflareIntegration
{
    public const VERIFY_URL = 'https://api.cloudflare.com/client/v4/user/tokens/verify';

    /** @param array<string, mixed> $settings */
    public static function verify(array $settings): IntegrationStatus
    {
        $token = trim((string)($settings['cloudflareApiToken'] ?? ''));
        if ($token === '') {
            return IntegrationStatus::missing('cloudflare', 'No API token saved yet.');
        }

        try {
            $response = HttpClient::send(new HttpRequest(
                id: 'cloudflare-verify',
                url: self::VERIFY_URL,
                headers: ['Authorization: Bearer ' . $token],
                timeout: 10,
                connectTimeout: 5,
                verifyPeer: true,
                verifyHost: 2,
                userAgent: 'AmareloTDS Integrations',
            ));
        } catch (Throwable $e) {
            return new IntegrationStatus('cloudflare', true, false, 'Could not reach Cloudflare: ' . $e->getMessage(), 'transport_error');
        }

        return self::interpret($response->httpCode(), (string)($response->content === false ? '' : $response->content), $response->error);
    }

    /** Kept separate from the HTTP call so the response handling is testable offline. */
    public static function interpret(int $httpCode, string $body, string $transportError = ''): IntegrationStatus
    {
        if ($transportError !== '') {
            return new IntegrationStatus('cloudflare', true, false, 'Could not reach Cloudflare: ' . $transportError, 'transport_error');
        }

        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return new IntegrationStatus('cloudflare', true, false, 'Cloudflare returned a response that could not be read.', 'bad_response');
        }

        if (($payload['success'] ?? false) !== true) {
            $error = is_array($payload['errors'] ?? null) ? ($payload['errors'][0] ?? []) : [];
            $code = (string)($error['code'] ?? $httpCode);
            return new IntegrationStatus('cloudflare', true, false, self::describeError($code), 'cf_' . $code);
        }

        // success:true is not enough — a disabled or expired token still answers 200.
        $status = strtolower((string)($payload['result']['status'] ?? ''));
        if ($status !== 'active') {
            return new IntegrationStatus(
                'cloudflare',
                true,
                false,
                $status === 'expired' ? 'The token is valid but has expired.' : 'The token exists but is disabled.',
                'cf_status_' . ($status !== '' ? $status : 'unknown')
            );
        }

        return new IntegrationStatus('cloudflare', true, true, 'Token is active.', 'active');
    }

    private static function describeError(string $code): string
    {
        return match ($code) {
            '1000' => 'Token is invalid or was revoked.',
            '1001' => 'Cloudflare did not receive the authorization header.',
            '6003', '6111' => 'Token format is not accepted by Cloudflare.',
            '9109' => 'Token lacks permission, or the caller IP is blocked by the token rules.',
            '9103', '9106' => 'Cloudflare rejected these credentials.',
            default => 'Cloudflare rejected the token (error ' . $code . ').',
        };
    }
}

final class NamecheapIntegration
{
    public const PRODUCTION_URL = 'https://api.namecheap.com/xml.response';
    public const SANDBOX_URL = 'https://api.sandbox.namecheap.com/xml.response';
    public const XML_NAMESPACE = 'http://api.namecheap.com/xml.response';

    /** Fixed-size response, works on an account with zero domains. */
    public const VERIFY_COMMAND = 'namecheap.users.getBalances';

    /** @param array<string, mixed> $settings */
    public static function verify(array $settings, string $clientIp): IntegrationStatus
    {
        $apiUser = trim((string)($settings['namecheapApiUser'] ?? ''));
        $apiKey = trim((string)($settings['namecheapApiKey'] ?? ''));
        $username = trim((string)($settings['namecheapUsername'] ?? '')) ?: $apiUser;

        if ($apiUser === '' || $apiKey === '') {
            return IntegrationStatus::missing('namecheap', 'API user and key are not saved yet.');
        }
        if (filter_var($clientIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return new IntegrationStatus('namecheap', true, false, 'Could not determine a public IPv4 address for this server.', 'no_ipv4');
        }

        $url = !empty($settings['namecheapSandbox']) ? self::SANDBOX_URL : self::PRODUCTION_URL;

        try {
            $response = HttpClient::send(new HttpRequest(
                id: 'namecheap-verify',
                // POST keeps the key out of the URL, access logs and Referer headers.
                url: $url,
                method: 'POST',
                body: [
                    'ApiUser' => $apiUser,
                    'ApiKey' => $apiKey,
                    'UserName' => $username,
                    'ClientIp' => $clientIp,
                    'Command' => self::VERIFY_COMMAND,
                ],
                timeout: 15,
                connectTimeout: 5,
                verifyPeer: true,
                verifyHost: 2,
                userAgent: 'AmareloTDS Integrations',
                // Namecheap authorises callers by IPv4 only; without this a dual-stack
                // server calls out over IPv6 and is rejected however it was whitelisted.
                forceIpv4: true,
            ));
        } catch (Throwable $e) {
            return new IntegrationStatus('namecheap', true, false, 'Could not reach Namecheap: ' . $e->getMessage(), 'transport_error');
        }

        return self::interpret((string)($response->content === false ? '' : $response->content), $response->error, $clientIp);
    }

    /** Kept separate from the HTTP call so the XML handling is testable offline. */
    public static function interpret(string $xml, string $transportError = '', string $clientIp = ''): IntegrationStatus
    {
        if ($transportError !== '') {
            return new IntegrationStatus('namecheap', true, false, 'Could not reach Namecheap: ' . $transportError, 'transport_error');
        }
        if (trim($xml) === '') {
            return new IntegrationStatus('namecheap', true, false, 'Namecheap returned an empty response.', 'bad_response');
        }

        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($doc === false) {
            return new IntegrationStatus('namecheap', true, false, 'Namecheap returned a response that could not be read.', 'bad_response');
        }

        // Namecheap answers HTTP 200 even for authentication failures, so the verdict
        // lives entirely in this attribute.
        $status = strtoupper(trim((string)($doc['Status'] ?? '')));
        if ($status === 'OK') {
            return new IntegrationStatus('namecheap', true, true, 'Credentials accepted.', 'ok', [
                'client_ip' => $clientIp,
            ]);
        }

        [$code, $text] = self::firstError($doc);

        // A valid credential whose balance is momentarily unavailable is not an auth
        // failure, and reporting it as one sends the operator chasing the wrong thing.
        if ($code === '4022312') {
            return new IntegrationStatus('namecheap', true, true, 'Credentials accepted (balance unavailable right now).', 'ok_no_balance');
        }

        return new IntegrationStatus('namecheap', true, false, self::describeError($code, $text), 'nc_' . ($code !== '' ? $code : 'unknown'), [
            'client_ip' => $clientIp,
        ]);
    }

    /** @return array{0: string, 1: string} */
    private static function firstError(SimpleXMLElement $doc): array
    {
        $doc->registerXPathNamespace('nc', self::XML_NAMESPACE);
        // The live response carries a default namespace the documented examples omit, so
        // try the namespaced path first and fall back for responses without it.
        foreach (['//nc:Errors/nc:Error', '//Errors/Error'] as $path) {
            $nodes = $doc->xpath($path);
            if (is_array($nodes) && $nodes !== []) {
                return [trim((string)($nodes[0]['Number'] ?? '')), trim((string)$nodes[0])];
            }
        }
        return ['', ''];
    }

    private static function describeError(string $code, string $text): string
    {
        return match ($code) {
            '1011150' => 'This server IP is not on the Namecheap whitelist. ' . ($text !== '' ? $text : 'Add it under Profile > Tools > Namecheap API Access.'),
            '1011102' => 'API key is invalid, or API access is switched off on the account.',
            '1017101' => 'The Namecheap account is locked.',
            '' => 'Namecheap rejected the credentials.',
            default => 'Namecheap rejected the request (error ' . $code . ')' . ($text !== '' ? ': ' . $text : '.'),
        };
    }
}

/**
 * The public IPv4 the outside world sees. Namecheap validates the connecting address,
 * so this has to be the egress address, not whatever the admin browser came from.
 */
function integrations_detect_public_ipv4(): string
{
    foreach (['https://api.ipify.org', 'https://ipv4.icanhazip.com'] as $probe) {
        try {
            $response = HttpClient::send(new HttpRequest(
                id: 'ip-probe',
                url: $probe,
                timeout: 8,
                connectTimeout: 4,
                verifyPeer: true,
                verifyHost: 2,
                userAgent: 'AmareloTDS Integrations',
                forceIpv4: true,
            ));
        } catch (Throwable) {
            continue;
        }
        $ip = trim((string)($response->content === false ? '' : $response->content));
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $ip;
        }
    }
    return '';
}
