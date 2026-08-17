<?php

require_once __DIR__ . '/securitycheck.php';
require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../destinations.php';

header('Content-Type: application/json; charset=utf-8');

function de_error(string $msg, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['error' => true, 'result' => $msg]);
    exit;
}

global $db;
$action = $_REQUEST['action'] ?? 'list';

switch ($action) {
    case 'list':
        $settings = $db->get_common_settings();
        echo json_encode(['error' => false, 'destinations' => $settings['destinations'] ?? []]);
        break;

    case 'save':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            de_error('Only POST allowed', 405);
        }
        $body = json_decode(file_get_contents('php://input'), true);
        $raw = is_array($body['destinations'] ?? null)
            ? $body['destinations']
            : (is_array($body) ? $body : null);
        if (!is_array($raw)) {
            de_error('Invalid JSON body');
        }
        if (count($raw) > 200) {
            de_error('At most 200 destinations.');
        }
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = (string)($entry['name'] ?? '');
            if (mb_strlen($name) > 64) {
                de_error('Destination name too long (max 64).');
            }
            $base = Destination::normalizeBaseUrl((string)($entry['base_url'] ?? ''));
            if ($base !== '' && (filter_var($base, FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', $base))) {
                de_error("Destination '" . trim($name) . "': base URL must be a valid http(s) URL.");
            }
        }
        $clean = DestinationLibrary::sanitize($raw, fn(): string => bin2hex(random_bytes(6)));
        $settings = $db->get_common_settings();
        $settings['destinations'] = $clean;
        if ($db->set_common_settings($settings) === false) {
            de_error('Could not save destinations', 500);
        }
        echo json_encode(['error' => false, 'result' => 'OK', 'destinations' => $clean]);
        break;

    default:
        de_error('Unknown action');
}
