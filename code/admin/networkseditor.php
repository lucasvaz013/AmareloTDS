<?php

require_once __DIR__ . '/securitycheck.php';
require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../networks.php';
require_once __DIR__ . '/../adminops.php';

header('Content-Type: application/json; charset=utf-8');

function ne_error(string $msg, int $status = 400): void
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
        echo json_encode(['error' => false, 'networks' => $settings['networks'] ?? []]);
        break;

    case 'save':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            ne_error('Only POST allowed', 405);
        }
        $body = json_decode(file_get_contents('php://input'), true);
        $raw = is_array($body['networks'] ?? null)
            ? $body['networks']
            : (is_array($body) ? $body : null);
        if (!is_array($raw)) {
            ne_error('Invalid JSON body');
        }
        if (count($raw) > 100) {
            ne_error('At most 100 networks.');
        }
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (mb_strlen((string)($entry['name'] ?? '')) > 64) {
                ne_error('Network name too long (max 64).');
            }
            if (mb_strlen((string)($entry['params'] ?? '')) > 1024) {
                ne_error('Network params too long (max 1024).');
            }
        }
        $clean = NetworkLibrary::sanitize($raw, fn(): string => bin2hex(random_bytes(6)));
        $settings = $db->get_common_settings();
        try {
            (new AdminOps($db))->assertRemovedLibraryIdsUnused(
                'network',
                is_array($settings['networks'] ?? null) ? $settings['networks'] : [],
                $clean
            );
        } catch (YtdsOpError $e) {
            ne_error($e->getMessage() . ($e->hint !== '' ? ' (' . $e->hint . ')' : ''), $e->httpStatus);
        }
        $settings['networks'] = $clean;
        if ($db->set_common_settings($settings) === false) {
            ne_error('Could not save networks', 500);
        }
        echo json_encode(['error' => false, 'result' => 'OK', 'networks' => $clean]);
        break;

    default:
        ne_error('Unknown action');
}
