<?php

/**
 * Remote transport for the ytds CLI. Non-local environments (--env stg|prod|<name>) proxy to
 * an instance's /api/admin.php over HTTPS with a Bearer token. Config lives on the operator's
 * machine, never in the repo:
 *
 *   $YTDS_CONFIG or ~/.config/ytds/config.json
 *   { "environments": { "stg": { "url": "https://stg.example/api/admin.php", "token": "..." } } }
 *
 * The token is read from there and sent as `Authorization: Bearer`; it is never printed.
 */

function ytds_config_path(): string
{
    $override = getenv('YTDS_CONFIG');
    if ($override !== false && $override !== '') {
        return $override;
    }
    $home = getenv('HOME');
    return ($home !== false && $home !== '' ? $home : '.') . '/.config/ytds/config.json';
}

/**
 * Resolves one environment's url + token.
 *
 * @return array{url: string, token: string}|array{error: array{0: string, 1: string, 2: string}}
 */
function ytds_env_config(string $env): array
{
    $path = ytds_config_path();
    if (!is_file($path)) {
        return ['error' => ['CONFIG_MISSING', 'no ytds config at ' . $path,
            'create it: {"environments":{"' . $env . '":{"url":"https://HOST/api/admin.php","token":"..."}}}']];
    }
    $raw = json_decode((string)file_get_contents($path), true);
    if (!is_array($raw)) {
        return ['error' => ['CONFIG_INVALID', 'config is not valid JSON: ' . $path, '']];
    }
    $cfg = $raw['environments'][$env] ?? null;
    if (!is_array($cfg)) {
        return ['error' => ['CONFIG_MISSING', 'environment not configured: ' . $env, 'add environments.' . $env . ' to ' . $path]];
    }
    $url = trim((string)($cfg['url'] ?? ''));
    $token = trim((string)($cfg['token'] ?? ''));
    if ($url === '' || $token === '') {
        return ['error' => ['CONFIG_MISSING', 'environment ' . $env . ' is missing url or token', 'edit ' . $path]];
    }
    return ['url' => $url, 'token' => $token];
}

/**
 * Calls the admin API (GET for reads, POST for mutations). Params travel in the query string in
 * both cases; POST just flips the HTTP method so writes are never issued as a GET. Returns the
 * HTTP status and raw body, or a transport error.
 *
 * @param array<string, string> $query
 * @return array{status: int, body: string}|array{transport: string}
 */
function ytds_http_request(string $url, string $token, array $query, string $method = 'GET', ?string $payload = null): array
{
    $full = $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
    $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
    if ($payload !== null) {
        $headers[] = 'Content-Type: ' . (in_array($query['action'] ?? '', ['landing.upload', 'landing.replace'], true)
            ? 'application/zip'
            : 'application/json');
    }
    $ch = curl_init($full);
    $timeout = in_array($query['action'] ?? '', ['landing.upload', 'landing.replace', 'costs.import'], true) ? 120 : 20;
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $payload ?? '';
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['transport' => $err];
    }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => (string)$body];
}

/** Streams a successful ZIP response to disk; error responses stay available as JSON text. */
function ytds_http_download(string $url, string $token, array $query, string $output): array
{
    $full = $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
    $tmp = $output . '.part-' . bin2hex(random_bytes(4));
    $handle = @fopen($tmp, 'wb');
    if ($handle === false) {
        return ['transport' => 'cannot create download file: ' . $tmp];
    }
    $ch = curl_init($full);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $handle,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/zip, application/json'],
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $ok = curl_exec($ch);
    $error = $ok === false ? curl_error($ch) : '';
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($handle);
    if ($ok === false) {
        @unlink($tmp);
        return ['transport' => $error];
    }
    if ($status !== 200) {
        $body = (string)@file_get_contents($tmp);
        @unlink($tmp);
        return ['status' => $status, 'body' => $body];
    }
    if (!@link($tmp, $output)) {
        @unlink($tmp);
        return ['transport' => 'cannot finalize download file: ' . $output];
    }
    @unlink($tmp);
    return ['status' => 200, 'body' => ''];
}
