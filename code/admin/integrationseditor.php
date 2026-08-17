<?php

// JSON endpoints must never leak PHP warnings into the response body.
ini_set('display_errors', '0');

require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/../integrations.php';
require_once __DIR__ . '/../logging.php';
require_once __DIR__ . '/password.php';
require_once __DIR__ . '/accesscontrol.php';

function integrations_send(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Credentials are read from the saved settings, never from the request. That keeps this
 * endpoint from doubling as an authenticated probe for arbitrary tokens.
 *
 * @return array<string, mixed>
 */
function integrations_verify_all(array $settings, string $publicIp): array
{
    return [
        'cloudflare' => CloudflareIntegration::verify($settings)->jsonSerialize(),
        'namecheap' => NamecheapIntegration::verify($settings, $publicIp)->jsonSerialize(),
    ];
}

function integrations_handle_request(): void
{
    global $cloSettings;

    if (!check_password(false)) {
        integrations_send(['error' => 'Not authorized.'], 403);
        return;
    }

    // Endpoints that skip securitycheck.php also skip the domain and IP allowlist, and
    // this one hands out integration state. Apply the same restriction explicitly.
    $accessError = get_admin_access_error($_SERVER, $cloSettings);
    if ($accessError !== null) {
        integrations_send(['error' => 'Not found.'], 404);
        return;
    }

    session_write_close();

    $manager = new SettingsManager(dirname(__DIR__));
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $settings = $manager->load();
        integrations_send([
            'revision' => $manager->revision(),
            'settings' => $manager->adminPayload($settings),
            'public_ip' => integrations_detect_public_ipv4(),
        ]);
        return;
    }

    if ($method !== 'POST') {
        integrations_send(['error' => 'Method not allowed.'], 405);
        return;
    }

    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        integrations_send(['error' => 'Invalid JSON body.'], 422);
        return;
    }

    $action = (string)($input['action'] ?? 'save');

    if ($action === 'verify') {
        $settings = $manager->load();
        // Detected once: each call is an outbound request of its own.
        $publicIp = integrations_detect_public_ipv4();
        integrations_send([
            'revision' => $manager->revision(),
            'settings' => $manager->adminPayload($settings),
            'public_ip' => $publicIp,
            'results' => integrations_verify_all($settings, $publicIp),
        ]);
        return;
    }

    if ($action !== 'save') {
        integrations_send(['error' => 'Unknown action.'], 422);
        return;
    }

    $current = $manager->load();
    $submitted = is_array($input['settings'] ?? null) ? $input['settings'] : [];

    // Only the integration keys may be written here; everything else keeps its value so
    // this page can never disturb admin credentials or paths.
    $allowed = [
        'namecheapApiUser', 'namecheapApiKey', 'namecheapUsername', 'namecheapSandbox',
        'cloudflareApiToken',
    ];
    $next = $current;
    foreach ($allowed as $key) {
        if (array_key_exists($key, $submitted)) {
            $next[$key] = $submitted[$key];
        }
    }

    try {
        $saved = $manager->save($next, (int)($input['revision'] ?? $manager->revision()));
    } catch (SettingsValidationException $e) {
        integrations_send(['error' => 'Some fields are invalid.', 'fields' => $e->errors], 422);
        return;
    } catch (SettingsConflictException) {
        integrations_send(['error' => 'Settings changed in another tab. Reload and try again.'], 409);
        return;
    } catch (Throwable $e) {
        ytds_log('error', 'admin', $e->getMessage(), ['action' => 'integrations-save']);
        integrations_send(['error' => 'Could not save the integration settings.'], 500);
        return;
    }

    $publicIp = integrations_detect_public_ipv4();
    integrations_send([
        'revision' => $saved['revision'],
        'settings' => $manager->adminPayload($saved['settings']),
        'public_ip' => $publicIp,
        'results' => integrations_verify_all($saved['settings'], $publicIp),
    ]);
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    integrations_handle_request();
}
