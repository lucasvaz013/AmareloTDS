<?php

require_once __DIR__ . '/securitycheck.php';
require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/../landings.php';
require_once __DIR__ . '/../db/db.php';

header('Content-Type: application/json; charset=utf-8');

function le_error(string $msg, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['error' => true, 'result' => $msg]);
    exit;
}

$baseRel = get_cache_path('landings');
$baseAbs = realpath(__DIR__ . '/../' . $baseRel);
if ($baseAbs === false) {
    @mkdir(__DIR__ . '/../' . $baseRel, 0755, true);
    $baseAbs = realpath(__DIR__ . '/../' . $baseRel);
}
if ($baseAbs === false) {
    le_error('Landings directory does not exist and could not be created', 500);
}

$library = new LandingLibrary($baseAbs);
$action = $_REQUEST['action'] ?? 'list';

switch ($action) {
    case 'list':
        echo json_encode(['error' => false, 'landings' => $library->all()]);
        break;

    case 'usage':
        $folder = trim((string)($_REQUEST['folder'] ?? ''));
        if (!LandingName::isValid($folder)) {
            le_error('Invalid folder name');
        }
        echo json_encode(['error' => false, 'folder' => $folder, 'usages' => collect_landing_usages($folder)]);
        break;

    case 'delete':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            le_error('Only POST allowed', 405);
        }
        $folder = trim((string)($_POST['folder'] ?? ''));
        if (!LandingName::isValid($folder)) {
            le_error('Invalid folder name');
        }
        if (!$library->exists($folder)) {
            le_error('Landing "' . $folder . '" does not exist', 404);
        }
        if (!$library->delete($folder)) {
            le_error('Could not delete landing "' . $folder . '"', 500);
        }
        echo json_encode(['error' => false, 'result' => 'OK']);
        break;

    case 'duplicate':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            le_error('Only POST allowed', 405);
        }
        $from = trim((string)($_POST['folder'] ?? ''));
        $to = trim((string)($_POST['newName'] ?? ''));
        if (!LandingName::isValid($from) || !LandingName::isValid($to)) {
            le_error('Invalid folder name. Use only letters, numbers, hyphens, underscores, dots.');
        }
        if (!$library->exists($from)) {
            le_error('Landing "' . $from . '" does not exist', 404);
        }
        if ($library->exists($to)) {
            le_error('Landing "' . $to . '" already exists. Choose a different name.');
        }
        if (!$library->duplicate($from, $to)) {
            le_error('Could not duplicate landing', 500);
        }
        echo json_encode(['error' => false, 'result' => 'OK', 'folder' => $to, 'landing' => $library->describe($to)]);
        break;

    default:
        le_error('Unknown action');
}

/**
 * Walks every campaign looking for references to $folder. Reads settings one campaign at a time
 * (rather than the runtime-rows helper) so a single campaign with malformed JSON degrades to
 * "not found" instead of aborting the whole scan.
 *
 * @return list<array{campaign:string,where:list<string>}>
 */
function collect_landing_usages(string $folder): array
{
    global $db;
    $out = [];
    foreach ($db->get_campaigns_list() as $camp) {
        $settings = $db->get_campaign_settings((int)($camp['id'] ?? 0));
        if (!is_array($settings)) {
            continue;
        }
        $where = LandingUsage::scan($settings, $folder);
        if ($where !== []) {
            $out[] = ['campaign' => (string)($camp['name'] ?? ''), 'where' => $where];
        }
    }
    return $out;
}
