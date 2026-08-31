<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/../../code/campaignservice.php';

final class CampaignServiceTest extends TestCase
{
    private TestDb $db;
    private string $dbPath;
    private CampaignService $service;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/amarelotds_campaignservice_' . uniqid() . '.db';
        $this->db = new TestDb($this->dbPath);
        $this->db->initSchema();
        $this->service = new CampaignService($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->cleanup();
    }

    /** @return array<string, mixed> */
    private static function settingsWith(array $domains, int $flowCount): array
    {
        $flows = [];
        for ($i = 1; $i <= $flowCount; $i++) {
            $flows[] = ['name' => 'Flow ' . $i, 'filters' => [], 'steps' => []];
        }
        return ['domains' => $domains, 'black' => ['flows' => $flows]];
    }

    public function testListReturnsNarrowRowsOrderedById(): void
    {
        $this->db->seedCampaign(3, 'gamma', self::settingsWith(['g.example.com'], 1));
        $this->db->seedCampaign(1, 'alpha', self::settingsWith(['a.example.com', '*.alt.example.com'], 2));
        $this->db->seedCampaign(2, 'beta', self::settingsWith([], 0));

        $this->assertSame([
            ['id' => 1, 'name' => 'alpha', 'domains' => ['a.example.com', '*.alt.example.com'], 'flows' => 2],
            ['id' => 2, 'name' => 'beta', 'domains' => [], 'flows' => 0],
            ['id' => 3, 'name' => 'gamma', 'domains' => ['g.example.com'], 'flows' => 1],
        ], $this->service->list());
    }

    public function testListOnEmptyDatabaseReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->service->list());
    }

    public function testMinimalSettingsYieldZeroFlowsAndNoDomains(): void
    {
        $this->db->seedCampaign(1, 'bare', []);

        $this->assertSame(
            [['id' => 1, 'name' => 'bare', 'domains' => [], 'flows' => 0]],
            $this->service->list()
        );
    }

    public function testGetReturnsNullForUnknownId(): void
    {
        $this->db->seedCampaign(1, 'alpha', self::settingsWith([], 1));

        $this->assertNull($this->service->get(42));
    }

    public function testGetMatchesListSummary(): void
    {
        $this->db->seedCampaign(7, 'alpha', self::settingsWith(['a.example.com'], 2));

        $campaign = $this->service->get(7);

        $this->assertNotNull($campaign);
        $this->assertSame(
            $this->service->list()[0],
            $this->service->summary($campaign['id'], $campaign['name'], $campaign['settings'])
        );
    }

    public function testGetRedactsConfiguredSecrets(): void
    {
        $settings = self::settingsWith(['a.example.com'], 1);
        $settings['apikey'] = 'SEEDED_APIKEY';
        $settings['capi'] = [
            'enabled' => false,
            'pixel_id' => '123',
            'access_token' => 'SEEDED_TOKEN',
            'pixels' => [
                ['pixel_id' => '123', 'access_token' => 'SEEDED_TOKEN', 'test_event_code' => ''],
                ['pixel_id' => '456', 'access_token' => 'SEEDED_TOKEN_2', 'test_event_code' => 'TEST2'],
            ],
        ];
        $settings['postback'] = ['pbkey' => ['enabled' => true, 'keys' => ['SEEDED_K1', 'SEEDED_K2']]];
        $this->db->seedCampaign(1, 'alpha', $settings);

        $got = $this->service->get(1)['settings'];

        $this->assertSame(CampaignService::REDACTED_VALUE, $got['apikey']);
        $this->assertSame(CampaignService::REDACTED_VALUE, $got['capi']['access_token']);
        $this->assertSame(CampaignService::REDACTED_VALUE, $got['capi']['pixels'][0]['access_token']);
        $this->assertSame(CampaignService::REDACTED_VALUE, $got['capi']['pixels'][1]['access_token']);
        $this->assertSame(
            [CampaignService::REDACTED_VALUE, CampaignService::REDACTED_VALUE],
            $got['postback']['pbkey']['keys']
        );
        $this->assertStringNotContainsString('SEEDED_', json_encode($got));
        // Non-secret neighbours untouched.
        $this->assertSame('123', $got['capi']['pixel_id']);
        $this->assertSame('456', $got['capi']['pixels'][1]['pixel_id']);
        $this->assertSame('TEST2', $got['capi']['pixels'][1]['test_event_code']);
        $this->assertTrue($got['postback']['pbkey']['enabled']);
        $this->assertSame(['a.example.com'], $got['domains']);
    }

    public function testRedactLeavesUnsetSecretsUntouched(): void
    {
        $settings = [
            'apikey' => '',
            'capi' => ['enabled' => false],
            'postback' => ['pbkey' => ['enabled' => false, 'keys' => []]],
        ];

        $this->assertSame($settings, CampaignService::redact($settings));
    }

    public function testPatchPersistsCompletePixelListAndLegacyMirror(): void
    {
        $settings = json_decode(
            file_get_contents(__DIR__ . '/../../code/db/default.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $this->db->seedCampaign(1, 'alpha', $settings);
        $capi = [
            'enabled' => true,
            'pixels' => [
                ['pixel_id' => '111', 'access_token' => 'TOKEN_A', 'test_event_code' => ''],
                ['pixel_id' => '222', 'access_token' => 'TOKEN_B', 'test_event_code' => 'TEST_B'],
            ],
            'map' => [['status' => 'Purchase', 'event_name' => 'Purchase']],
        ];

        $preview = $this->service->patch(1, ['capi' => $capi], false);
        self::assertTrue($preview['dry_run']);
        self::assertSame(['capi'], $preview['changed']);

        $this->service->patch(1, ['capi' => $capi], true);
        $stored = $this->db->get_campaign_runtime_rows()[0]['settings']['capi'];

        self::assertCount(2, $stored['pixels']);
        self::assertSame('111', $stored['pixel_id']);
        self::assertSame('TOKEN_A', $stored['access_token']);
        self::assertSame('222', $stored['pixels'][1]['pixel_id']);
        self::assertSame('TOKEN_B', $stored['pixels'][1]['access_token']);
    }

    public function testLegacyCapiSetFieldKeepsPrimaryPixelMirrorInSync(): void
    {
        $settings = json_decode(
            file_get_contents(__DIR__ . '/../../code/db/default.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $settings['capi'] = [
            'enabled' => true,
            'pixel_id' => '111',
            'access_token' => 'TOKEN_OLD',
            'test_event_code' => '',
            'pixels' => [
                ['pixel_id' => '111', 'access_token' => 'TOKEN_OLD', 'test_event_code' => ''],
                ['pixel_id' => '222', 'access_token' => 'TOKEN_B', 'test_event_code' => ''],
            ],
            'map' => [['status' => 'Purchase', 'event_name' => 'Purchase']],
        ];
        $this->db->seedCampaign(1, 'alpha', $settings);

        $this->service->setFields(1, ['capi.access_token' => 'TOKEN_NEW'], true);
        $stored = $this->db->get_campaign_runtime_rows()[0]['settings']['capi'];

        self::assertSame('TOKEN_NEW', $stored['access_token']);
        self::assertSame('TOKEN_NEW', $stored['pixels'][0]['access_token']);
        self::assertSame('TOKEN_B', $stored['pixels'][1]['access_token']);
    }
}
