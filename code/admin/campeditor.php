<?php
require_once __DIR__ . '/password.php';
require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../campaign.php';
require_once __DIR__ . '/../logging.php';
require_once __DIR__ . '/../campaignservice.php';   // CampaignService + YtdsOpError
require_once __DIR__ . '/../campaignvalidation.php';
require_once __DIR__ . '/../campaignmutation.php';

/**
 * HTTP adapter for campaign mutations. The panel and the ytds CLI are two doors to the SAME
 * CampaignService — this file only parses the request, calls the service (always committing), and
 * formats the panel's {result, error} envelope. All validation/merge/write logic lives in
 * CampaignService + campaignvalidation + campaignmutation, so both doors behave identically.
 *
 * Pure (no echo, no $_REQUEST, no exit) so the panel save path is unit-testable via the NO_RUN guard.
 *
 * @return array{ok: bool, message: string, campId?: int}
 */
function campeditor_handle(Db $db, string $action, string $name, int $campId, string $rawBody): array
{
    $service = new CampaignService($db);
    try {
        switch ($action) {
            case 'add':
                // create-from-template is a later phase; the panel's blank-create keeps the primitive.
                $newId = $db->add_campaign($name);
                return $newId === false
                    ? ['ok' => false, 'message' => 'Error adding new campaign!']
                    : ['ok' => true, 'message' => 'OK', 'campId' => (int)$newId];
            case 'dup':
                if ($name === '') {
                    return ['ok' => false, 'message' => 'Error: campaign name can not be empty!'];
                }
                $result = $service->cloneCampaign($campId, $name, true);
                return ['ok' => true, 'message' => 'OK', 'campId' => (int)$result['id']];
            case 'del':
                $service->deleteCampaign($campId, true);
                return ['ok' => true, 'message' => 'OK'];
            case 'ren':
                $service->renameCampaign($campId, $name, true);
                return ['ok' => true, 'message' => 'OK'];
            case 'save':
                $input = json_decode($rawBody, true);
                if (!is_array($input)) {
                    return ['ok' => false, 'message' => 'Error: invalid JSON body!'];
                }
                $service->patch($campId, $input, true);
                return ['ok' => true, 'message' => 'OK'];
            default:
                return ['ok' => false, 'message' => 'Error: wrong action!'];
        }
    } catch (YtdsOpError $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}

function send_camp_result($msg, $error = false): void
{
    $res = ["result" => $msg];
    if ($error) {
        $res['error'] = true;
    }
    header('Content-type: application/json');
    http_response_code(200);
    echo json_encode($res);
}

if (!defined('AMARELOTDS_CAMPEDITOR_NO_RUN')) {
    if (!check_password(false)) {
        send_camp_result("Error: password check not passed!", true);
        return;
    }
    $action = $_REQUEST['action'] ?? '';
    $name = $_REQUEST['name'] ?? '';
    $name = is_string($name) ? trim($name) : '';
    $campId = (int)($_REQUEST['campId'] ?? -1);
    add_log('trace', 'CampEditor action: ' . $action . ', name: ' . $name . ', campId: ' . $campId);
    $result = campeditor_handle($db, (string)$action, $name, $campId, (string)file_get_contents('php://input'));
    send_camp_result($result['message'], !$result['ok']);
}
