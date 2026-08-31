<?php

require_once __DIR__ . '/requestfunc.php';
require_once __DIR__ . '/campaign.php';
require_once __DIR__ . '/logging.php';

final class CapiEventBuilder
{
    /**
     * Bumping this is a deliberate decision, not a configuration knob: the Graph API
     * breaks compatibility between versions.
     */
    public const GRAPH_API_VERSION = 'v25.0';

    /** Meta wants 1 when fbc is built server-side with no _fbc cookie saved. */
    public const SUBDOMAIN_INDEX = 1;

    public const ACTION_SOURCE = 'website';

    /**
     * @param array<string, mixed> $click a row from the clicks table
     * @return array<string, mixed> one entry of the Conversions API `data` array
     */
    public static function build(
        array $click,
        string $eventName,
        int $eventTime,
        string $eventSourceUrl,
        ?float $value = null,
        ?string $currency = null,
        ?string $tid = null
    ): array {
        $clickid = (string)($click['clickid'] ?? '');
        $userData = [];

        $ip = trim((string)($click['ip'] ?? ''));
        if ($ip !== '') {
            $userData['client_ip_address'] = $ip;
        }
        $userAgent = trim((string)($click['ua'] ?? ''));
        if ($userAgent !== '') {
            $userData['client_user_agent'] = $userAgent;
        }
        $fbc = self::buildFbc(self::clickParam($click, 'fbclid'), (int)($click['time'] ?? 0));
        if ($fbc !== null) {
            $userData['fbc'] = $fbc;
        }
        $externalId = self::externalId($click);
        if ($externalId !== null) {
            $userData['external_id'] = $externalId;
        }

        $event = [
            'event_name' => $eventName,
            'event_time' => $eventTime,
            'event_id' => self::eventId($clickid, $eventName, $tid),
            'event_source_url' => $eventSourceUrl,
            'action_source' => self::ACTION_SOURCE,
            'user_data' => $userData,
        ];

        if ($value !== null && $currency !== null && $currency !== '') {
            $event['custom_data'] = [
                // Formatted as a string, the way Meta's own examples show it. Encoding
                // the float directly lets large amounts serialise as 1.0e+21.
                'value' => number_format($value, 2, '.', ''),
                'currency' => strtoupper($currency),
            ];
        }
        if ($tid !== null && $tid !== '') {
            $event['custom_data']['order_id'] = $tid;
        }

        return $event;
    }

    /**
     * `fb.<subdomainIndex>.<creationTime>.<fbclid>` where creationTime is in
     * MILLISECONDS. clicks.time is stored in seconds, so it must be scaled — an fbc
     * carrying seconds is still accepted by Meta but never attributes, silently.
     */
    public static function buildFbc(mixed $fbclid, int $clickTimeSeconds): ?string
    {
        $fbclid = is_string($fbclid) ? trim($fbclid) : '';
        if ($fbclid === '' || $clickTimeSeconds <= 0) {
            return null;
        }
        return 'fb.' . self::SUBDOMAIN_INDEX . '.' . ($clickTimeSeconds * 1000) . '.' . $fbclid;
    }

    /**
     * Stable across retries so Meta deduplicates a re-sent postback. The dedup window
     * on Meta's side is 48 hours.
     */
    public static function eventId(string $clickid, string $eventName, ?string $tid): string
    {
        return sha1($clickid . '|' . $eventName . '|' . (string)$tid);
    }

    /** Reads one of the original query parameters captured with the click. */
    public static function clickParam(array $click, string $name): ?string
    {
        $params = $click['params'] ?? null;
        if (is_string($params)) {
            $params = json_decode($params, true);
        }
        if (!is_array($params)) {
            return null;
        }
        $value = $params[$name] ?? null;
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function externalId(array $click): ?string
    {
        $id = trim((string)($click['userid'] ?? ''));
        if ($id === '') {
            $id = trim((string)($click['clickid'] ?? ''));
        }
        return $id === '' ? null : hash('sha256', $id);
    }
}

final class CapiSender
{
    /** @param list<array<string, mixed>> $events */
    public static function send(CapiSettings $settings, array $events): HttpResponse
    {
        $results = self::sendAll($settings, $events);
        if ($results === []) {
            throw new InvalidArgumentException('No usable Meta pixel configured');
        }
        return $results[0]['response'];
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<HttpRequest>
     */
    public static function buildRequests(CapiSettings $settings, array $events): array
    {
        $requests = [];
        foreach ($settings->usablePixels() as $index => $pixel) {
            $payload = ['data' => $events];
            if ($pixel->testEventCode !== '') {
                $payload['test_event_code'] = $pixel->testEventCode;
            }
            $requests[] = new HttpRequest(
                id: 'capi-conversion-' . $index,
                url: self::endpoint($pixel->pixelId),
                method: 'POST',
                body: json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                headers: [
                    'Content-Type: application/json',
                    // Sent as a header rather than ?access_token= so the token never
                    // reaches the URL we log.
                    'Authorization: Bearer ' . $pixel->accessToken,
                ],
                // Meta recommends 1500ms; CURLOPT_TIMEOUT only takes whole seconds.
                timeout: 2,
                connectTimeout: 2,
                verifyPeer: true,
                verifyHost: 2,
                userAgent: 'AmareloTDS CAPI',
            );
        }
        return $requests;
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array{pixel:CapiPixelSettings,response:HttpResponse}>
     */
    public static function sendAll(CapiSettings $settings, array $events): array
    {
        $pixels = $settings->usablePixels();
        $requests = self::buildRequests($settings, $events);
        $responses = HttpClient::sendParallel($requests);
        $results = [];
        foreach ($requests as $index => $request) {
            if (isset($pixels[$index], $responses[$request->id])) {
                $results[] = ['pixel' => $pixels[$index], 'response' => $responses[$request->id]];
            }
        }
        return $results;
    }

    public static function endpoint(string $pixelId): string
    {
        return 'https://graph.facebook.com/' . CapiEventBuilder::GRAPH_API_VERSION
            . '/' . rawurlencode($pixelId) . '/events';
    }

    public static function responseExcerpt(mixed $content, int $limit = 2048): string
    {
        if (!is_string($content) || $content === '') {
            return '';
        }
        return strlen($content) <= $limit ? $content : substr($content, 0, $limit) . '…';
    }
}

/**
 * Sends one conversion to Meta. Called alongside the URL-based S2S postbacks, with the
 * payout as the sales platform reported it — not the USD-converted figure stored on the
 * click, which would disagree with the currency code travelling next to it.
 *
 * @param array<string, mixed> $click
 */
function process_capi_conversion(
    Campaign $campaign,
    string $status,
    array $click,
    ?float $value,
    string $currency,
    ?string $tid = null,
    ?callable $sendAll = null,
    ?callable $log = null
): void {
    $settings = $campaign->capi;
    if (!$settings->isUsable() || $click === []) {
        return;
    }

    $eventName = $settings->eventNameForStatus($status);
    if ($eventName === null) {
        return;
    }

    $pixels = $settings->usablePixels();
    $clickid = (string)($click['clickid'] ?? '');
    $baseContext = [
        'destination' => 'meta_capi',
        'trigger_type' => 'conversion',
        'campaign_id' => $campaign->campaignId,
        'clickid' => $clickid,
        'status' => $status,
        'event_name' => $eventName,
    ];
    $log ??= 'ytds_log_postback';

    if (in_array($eventName, CapiSettings::EVENTS_REQUIRING_VALUE, true)
        && ($value === null || $value <= 0.0)) {
        foreach ($pixels as $pixel) {
            $log(
                'outgoing',
                'failed',
                'Meta CAPI event skipped',
                $baseContext + [
                    'pixel_id' => $pixel->pixelId,
                    'error' => $eventName . ' requires a payout value; none was reported.',
                ]
            );
        }
        return;
    }

    try {
        $event = CapiEventBuilder::build(
            $click,
            $eventName,
            time(),
            capi_event_source_url($campaign),
            $value,
            $currency,
            $tid
        );
        $sendAll ??= static fn(CapiSettings $capi, array $events): array => CapiSender::sendAll($capi, $events);
        $results = $sendAll($settings, [$event]);
    } catch (Throwable $exception) {
        foreach ($pixels as $pixel) {
            $log(
                'outgoing',
                'failed',
                'Meta CAPI event failed',
                $baseContext + ['pixel_id' => $pixel->pixelId, 'error' => $exception->getMessage()]
            );
        }
        return;
    }

    foreach ($results as $result) {
        $pixel = $result['pixel'] ?? null;
        $response = $result['response'] ?? null;
        if (!$pixel instanceof CapiPixelSettings || !$response instanceof HttpResponse) {
            continue;
        }
        $context = $baseContext + [
            'pixel_id' => $pixel->pixelId,
            // Logged so traffic arriving without an ad click is measurable rather than guessed.
            'has_fbc' => isset($event['user_data']['fbc']),
            'event_id' => $event['event_id'],
            'http_code' => $response->httpCode(),
        ];
        $body = is_string($response->content) ? $response->content : '';
        if ($body !== '') {
            $context['response_body'] = CapiSender::responseExcerpt($body);
        }
        if ($response->error !== '') {
            $context['error'] = $response->error;
        }

        $log(
            'outgoing',
            $response->isOk() ? 'delivered' : 'failed',
            $response->isOk() ? 'Meta CAPI event delivered' : 'Meta CAPI event failed',
            $context
        );
    }
}

function capi_event_source_url(Campaign $campaign): string
{
    $domain = trim((string)($campaign->domains[0] ?? ''));
    if ($domain === '') {
        return get_tds_path();
    }
    if (str_starts_with($domain, 'http://') || str_starts_with($domain, 'https://')) {
        return $domain;
    }
    return 'https://' . ltrim($domain, '*.') . '/';
}
