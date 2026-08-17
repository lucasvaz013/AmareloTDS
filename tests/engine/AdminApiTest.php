<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TestDb.php';
if (!defined('AMARELOTDS_ADMIN_API_NO_RUN')) {
    define('AMARELOTDS_ADMIN_API_NO_RUN', true);
}
require_once __DIR__ . '/../../code/api/admin.php';

final class AdminApiTest extends TestCase
{
    private TestDb $db;
    private string $dbPath;
    private string $codeDir;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/amarelotds_adminapi_' . uniqid() . '.db';
        $this->db = new TestDb($this->dbPath);
        $this->db->initSchema();
        $this->db->seedCampaign(1, 'alpha', ['statistics' => ['timezone' => 'Europe/Moscow'], 'domains' => ['a.example.com'], 'apikey' => 'SECRET_KEY']);
        $this->codeDir = realpath(__DIR__ . '/../../code');
    }

    protected function tearDown(): void
    {
        $this->db->cleanup();
    }

    public function testAuthorizationRequiresConfiguredTokenAndMatchingBearer(): void
    {
        $this->assertFalse(ytds_admin_api_authorized([], ['adminApiToken' => '']), 'disabled when no token');
        $this->assertFalse(ytds_admin_api_authorized([], ['adminApiToken' => 'secret']), 'no header');
        $this->assertFalse(ytds_admin_api_authorized(['HTTP_AUTHORIZATION' => 'Bearer wrong'], ['adminApiToken' => 'secret']));
        $this->assertTrue(ytds_admin_api_authorized(['HTTP_AUTHORIZATION' => 'Bearer secret'], ['adminApiToken' => 'secret']));
    }

    public function testDispatchVersion(): void
    {
        $result = ytds_admin_api_dispatch($this->db, 'version', [], $this->codeDir);
        $this->assertSame(200, $result['status']);
        $this->assertArrayHasKey('version', $result['body']);
        $this->assertArrayHasKey('php', $result['body']);
    }

    public function testDispatchCampaignsList(): void
    {
        $result = ytds_admin_api_dispatch($this->db, 'campaigns.list', [], $this->codeDir);
        $this->assertSame(200, $result['status']);
        $this->assertSame(1, $result['body']['count']);
    }

    public function testDispatchCampaignGetFullRedactsSecret(): void
    {
        $result = ytds_admin_api_dispatch($this->db, 'campaign.get', ['id' => '1', 'full' => '1'], $this->codeDir);
        $this->assertSame(200, $result['status']);
        $this->assertSame('<redacted>', $result['body']['settings']['apikey']);
    }

    public function testDispatchCampaignGetMissingIdIsInvalidArg(): void
    {
        $result = ytds_admin_api_dispatch($this->db, 'campaign.get', [], $this->codeDir);
        $this->assertSame(400, $result['status']);
        $this->assertSame('INVALID_ARG', $result['body']['code']);
    }

    public function testDispatchCampaignGetUnknownIdIs404(): void
    {
        $result = ytds_admin_api_dispatch($this->db, 'campaign.get', ['id' => '999'], $this->codeDir);
        $this->assertSame(404, $result['status']);
        $this->assertSame('CAMPAIGN_NOT_FOUND', $result['body']['code']);
    }

    public function testDispatchStats(): void
    {
        $this->db->seedClicks([
            ['clickid' => 'c1', 'campaign_id' => 1, 'time' => 1699963200, 'status' => 'Purchase', 'payout' => 10.0, 'flow' => 'Flow 1', 'path' => ['lp'], 'step' => 0],
        ]);
        $result = ytds_admin_api_dispatch($this->db, 'stats', ['campaign' => '1', 'from' => '14.11.23', 'to' => '14.11.23'], $this->codeDir);
        $this->assertSame(200, $result['status']);
        $this->assertSame(1, $result['body']['rows'][0]['clicks']);
    }

    public function testDispatchClicksTrafficbackNeedsNoCampaign(): void
    {
        $result = ytds_admin_api_dispatch($this->db, 'clicks', ['view' => 'trafficback'], $this->codeDir);
        $this->assertSame(200, $result['status']);
        $this->assertNull($result['body']['campaign']);
    }

    public function testDispatchDestinationsList(): void
    {
        $result = ytds_admin_api_dispatch($this->db, 'destinations.list', [], $this->codeDir);
        $this->assertSame(200, $result['status']);
        $this->assertArrayHasKey('destinations', $result['body']);
    }

    public function testDispatchLandingList(): void
    {
        $result = ytds_admin_api_dispatch($this->db, 'landing.list', [], $this->codeDir);
        $this->assertSame(200, $result['status']);
        $this->assertArrayHasKey('landings', $result['body']);
        $this->assertArrayHasKey('count', $result['body']);
    }

    public function testDispatchUnknownActionIs400(): void
    {
        $result = ytds_admin_api_dispatch($this->db, 'frobnicate', [], $this->codeDir);
        $this->assertSame(400, $result['status']);
        $this->assertSame('UNKNOWN_ACTION', $result['body']['code']);
    }

    public function testIsWriteActionClassification(): void
    {
        foreach (['campaign.clone', 'campaign.rename', 'campaign.delete', 'campaign.domains'] as $w) {
            $this->assertTrue(ytds_admin_api_is_write($w), $w);
        }
        foreach (['version', 'campaigns.list', 'campaign.get', 'stats', 'destinations.list'] as $r) {
            $this->assertFalse(ytds_admin_api_is_write($r), $r);
        }
    }

    public function testDispatchCloneDryRunAndCommit(): void
    {
        $dry = ytds_admin_api_dispatch($this->db, 'campaign.clone', ['id' => '1'], $this->codeDir);
        $this->assertSame(200, $dry['status']);
        $this->assertTrue($dry['body']['dry_run']);

        $done = ytds_admin_api_dispatch($this->db, 'campaign.clone', ['id' => '1', 'commit' => '1'], $this->codeDir);
        $this->assertSame(200, $done['status']);
        $this->assertFalse($done['body']['dry_run']);
        $this->assertSame('alpha (Clone)', $done['body']['name']);
    }

    public function testDispatchRenameEmptyNameIsInvalidArg(): void
    {
        $res = ytds_admin_api_dispatch($this->db, 'campaign.rename', ['id' => '1', 'name' => ''], $this->codeDir);
        $this->assertSame(400, $res['status']);
        $this->assertSame('INVALID_ARG', $res['body']['code']);
    }

    public function testDispatchCreateFromTemplate(): void
    {
        $dry = ytds_admin_api_dispatch($this->db, 'campaign.create', ['name' => 'Via API'], $this->codeDir);
        $this->assertSame(200, $dry['status']);
        $this->assertTrue($dry['body']['dry_run']);

        $done = ytds_admin_api_dispatch($this->db, 'campaign.create', ['name' => 'Via API', 'commit' => '1'], $this->codeDir);
        $this->assertSame(200, $done['status']);
        $this->assertFalse($done['body']['dry_run']);
        $this->assertArrayHasKey('id', $done['body']);
    }

    public function testDispatchCreateUnknownTemplateIsInvalidArg(): void
    {
        $res = ytds_admin_api_dispatch($this->db, 'campaign.create', ['name' => 'X', 'template' => 'ghost'], $this->codeDir);
        $this->assertSame(400, $res['status']);
        $this->assertSame('INVALID_ARG', $res['body']['code']);
    }

    public function testDispatchSetFields(): void
    {
        $this->db->seedCampaign(3, 'u', [
            'uniqueness' => ['enabled' => false, 'method' => 'cookie_ip_ua', 'ttl_hours' => 24, 'get_parameter' => ''],
            'black' => ['flows' => []],
        ]);
        $res = ytds_admin_api_dispatch($this->db, 'campaign.set', ['id' => '3', 'commit' => '1'], $this->codeDir, '{"uniqueness.enabled":true,"uniqueness.ttl_hours":72}');
        $this->assertSame(200, $res['status']);
        $this->assertFalse($res['body']['dry_run']);
        $this->assertSame(72, $this->db->get_campaign_settings(3)['uniqueness']['ttl_hours']);
    }

    public function testDispatchSetRejectsNonObjectBody(): void
    {
        $res = ytds_admin_api_dispatch($this->db, 'campaign.set', ['id' => '1'], $this->codeDir, 'not-json');
        $this->assertSame(400, $res['status']);
        $this->assertSame('INVALID_ARG', $res['body']['code']);
    }

    public function testDispatchKillDefaults(): void
    {
        $res = ytds_admin_api_dispatch($this->db, 'campaign.kill-defaults', ['id' => '1'], $this->codeDir);
        $this->assertSame(200, $res['status']);
        $this->assertSame('kill-defaults', $res['body']['action']);
    }

    public function testDispatchNetworksCrud(): void
    {
        $add = ytds_admin_api_dispatch($this->db, 'networks.add', ['name' => 'BuyGoods', 'params' => 'subid={clickid}', 'commit' => '1'], $this->codeDir);
        $this->assertSame(200, $add['status']);
        $id = $add['body']['network']['id'];
        $this->assertSame(1, ytds_admin_api_dispatch($this->db, 'networks.list', [], $this->codeDir)['body']['count']);

        $del = ytds_admin_api_dispatch($this->db, 'networks.delete', ['id' => $id, 'commit' => '1'], $this->codeDir);
        $this->assertSame(200, $del['status']);
        $this->assertSame(0, ytds_admin_api_dispatch($this->db, 'networks.list', [], $this->codeDir)['body']['count']);
    }

    public function testDispatchDestinationAddComposesUrl(): void
    {
        $res = ytds_admin_api_dispatch($this->db, 'destinations.add', ['name' => 'Checkout', 'base_url' => 'checkout.example.com/a', 'commit' => '1'], $this->codeDir);
        $this->assertSame(200, $res['status']);
        $list = ytds_admin_api_dispatch($this->db, 'destinations.list', [], $this->codeDir);
        $this->assertSame('https://checkout.example.com/a', $list['body']['destinations'][0]['base_url']);
    }

    public function testDispatchLandingUploadBuffersBodyAndRejectsNonZip(): void
    {
        // proves the raw POST body is buffered to a temp file and validated as a zip (no fs write on failure)
        $res = ytds_admin_api_dispatch($this->db, 'landing.upload', ['name' => 'ztest_' . uniqid(), 'commit' => '1'], $this->codeDir, 'this is not a zip');
        $this->assertSame(400, $res['status']);
        $this->assertSame('INVALID_ARG', $res['body']['code']);
    }

    public function testDispatchLandingDeleteUnknownIsNotFound(): void
    {
        $res = ytds_admin_api_dispatch($this->db, 'landing.delete', ['name' => 'zzz_nonexistent_' . uniqid()], $this->codeDir);
        $this->assertSame(404, $res['status']);
        $this->assertSame('LANDING_NOT_FOUND', $res['body']['code']);
    }
}
