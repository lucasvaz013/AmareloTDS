<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/../../code/adminops.php';

final class AdminOpsTest extends TestCase
{
    private TestDb $db;
    private string $dbPath;
    private AdminOps $ops;

    /** noon UTC 14.11.2023 — inside the 14.11.23 day in Europe/Moscow (+3). */
    private const SEED_TIME = 1699963200;
    private const SEED_DATE = '14.11.23';

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/amarelotds_adminops_' . uniqid() . '.db';
        $this->db = new TestDb($this->dbPath);
        $this->db->initSchema();
        $this->db->seedCampaign(1, 'alpha', [
            'statistics' => ['timezone' => 'Europe/Moscow'],
            'domains' => ['a.example.com'],
            'apikey' => 'SECRET_KEY',
        ]);
        $this->ops = new AdminOps($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->cleanup();
    }

    public function testEngineOpsHaveNoFixedAdminDirectoryDependency(): void
    {
        // admin/ is renamed to a hex path on the instance, so deployed engine/API code must not
        // require anything from admin/ by fixed path — it would 500 in production, and the local
        // suite cannot see the rename (AGENTS.md §7/§8). Runtime lookups use get_admin_dir().
        $files = ['adminops.php', 'api/admin.php', 'campaignservice.php', 'destinations.php', 'networks.php', 'landings.php'];
        foreach ($files as $file) {
            $src = (string)file_get_contents(__DIR__ . '/../../code/' . $file);
            $this->assertDoesNotMatchRegularExpression(
                '#(?:require|include)(?:_once)?\s+__DIR__\s*\.\s*[\'"][^\'"]*/admin/#',
                $src,
                $file . ' must not require from the renamed admin/ directory'
            );
        }
    }

    private function seedTwoClicks(): void
    {
        // Distinct times so ORDER BY time DESC is deterministic (c1 is the newest) across SQLite builds.
        $this->db->seedClicks([
            ['clickid' => 'c1', 'campaign_id' => 1, 'time' => self::SEED_TIME, 'country' => 'US', 'device' => 'desktop', 'status' => 'Purchase', 'payout' => 10.0, 'flow' => 'Flow 1', 'path' => ['lp'], 'step' => 0],
            ['clickid' => 'c2', 'campaign_id' => 1, 'time' => self::SEED_TIME - 3600, 'country' => 'BR', 'device' => 'mobile', 'flow' => 'Flow 1', 'path' => ['lp'], 'step' => 0],
        ]);
    }

    public function testVersionReadsFile(): void
    {
        $file = sys_get_temp_dir() . '/amarelotds_ver_' . uniqid() . '.txt';
        file_put_contents($file, "09.09.26.2\n");
        $this->assertSame(['version' => '09.09.26.2', 'php' => PHP_VERSION], $this->ops->version($file));
        @unlink($file);
    }

    public function testCampaignsListCountsRows(): void
    {
        $this->db->seedCampaign(2, 'beta', []);
        $result = $this->ops->campaignsList();
        $this->assertSame(2, $result['count']);
        $this->assertSame('alpha', $result['campaigns'][0]['name']);
    }

    public function testCampaignGetNarrowFullSection(): void
    {
        $narrow = $this->ops->campaignGet(1, null, false);
        $this->assertSame(['id', 'name', 'domains', 'flows'], array_keys($narrow));

        $full = $this->ops->campaignGet(1, null, true);
        $this->assertSame('<redacted>', $full['settings']['apikey']);

        $section = $this->ops->campaignGet(1, 'domains', false);
        $this->assertSame(['a.example.com'], $section['value']);
    }

    public function testCampaignGetUnknownIdThrowsNotFound(): void
    {
        try {
            $this->ops->campaignGet(999, null, false);
            $this->fail('expected YtdsOpError');
        } catch (YtdsOpError $e) {
            $this->assertSame('CAMPAIGN_NOT_FOUND', $e->errorCode);
            $this->assertSame(404, $e->httpStatus);
        }
    }

    public function testCampaignGetUnknownSectionThrows(): void
    {
        $this->expectException(YtdsOpError::class);
        $this->ops->campaignGet(1, 'nope.here', false);
    }

    public function testStatsAggregatesSeededClicks(): void
    {
        $this->seedTwoClicks();
        $stats = $this->ops->stats(1, self::SEED_DATE, self::SEED_DATE, [], []);

        $this->assertSame(1, $stats['campaign']);
        $this->assertSame('Europe/Moscow', $stats['timezone']);
        $this->assertSame(AdminOps::DEFAULT_STATS_COLUMNS, $stats['columns']);
        $row = $stats['rows'][0];
        $this->assertSame(2, $row['clicks']);
        $this->assertSame(1, $row['conversion']);
        $this->assertSame(10.0, $row['revenue']);
    }

    public function testStatsUnknownCampaignThrows(): void
    {
        $this->expectException(YtdsOpError::class);
        $this->ops->stats(777, self::SEED_DATE, self::SEED_DATE, [], []);
    }

    public function testClicksNarrowProjectionNewestWindow(): void
    {
        $this->seedTwoClicks();
        $result = $this->ops->clicks(1, 'allowed', self::SEED_DATE, self::SEED_DATE, 50, false);

        $this->assertSame('allowed', $result['view']);
        $this->assertSame(2, $result['count']);
        $this->assertSame(AdminOps::CLICK_NARROW_COLUMNS, array_keys($result['clicks'][0]));
        $this->assertSame('c1', $result['clicks'][0]['clickid']);
    }

    public function testClicksFullReturnsWideRow(): void
    {
        $this->seedTwoClicks();
        $result = $this->ops->clicks(1, 'allowed', self::SEED_DATE, self::SEED_DATE, 50, true);

        $this->assertArrayHasKey('ip', $result['clicks'][0]);
        $this->assertArrayHasKey('ua', $result['clicks'][0]);
    }

    public function testClicksTrafficbackUsesNullCampaign(): void
    {
        $result = $this->ops->clicks(0, 'trafficback', self::SEED_DATE, self::SEED_DATE, 50, false);
        $this->assertNull($result['campaign']);
        $this->assertSame(0, $result['count']);
    }

    public function testClicksUnknownViewThrows(): void
    {
        $this->expectException(YtdsOpError::class);
        $this->ops->clicks(1, 'bogus', null, null, 50, false);
    }

    public function testLandingsListsFoldersWithMetadata(): void
    {
        $dir = sys_get_temp_dir() . '/amarelotds_land_' . uniqid();
        mkdir($dir . '/promo', 0777, true);
        file_put_contents($dir . '/promo/index.html', '<html></html>');
        mkdir($dir . '/empty', 0777, true);

        $result = $this->ops->landings($dir);

        $this->assertSame(2, $result['count']);
        $names = array_column($result['landings'], 'name');
        $this->assertSame(['empty', 'promo'], $names);
        $promo = $result['landings'][array_search('promo', $names, true)];
        $this->assertTrue($promo['hasIndex']);
        $this->assertSame(1, $promo['files']);

        array_map('unlink', glob($dir . '/promo/*'));
        rmdir($dir . '/promo');
        rmdir($dir . '/empty');
        rmdir($dir);
    }

    public function testDestinationsResolveNetworksAndDegrade(): void
    {
        $this->db->set_common_settings([
            'networks' => [['id' => 'n1', 'name' => 'BuyGoods', 'params' => '?subid={clickid}']],
            'destinations' => [
                ['id' => 'd1', 'name' => 'Checkout A', 'base_url' => 'checkout.example.com/a', 'network_id' => 'n1'],
                ['id' => 'd2', 'name' => 'Checkout B', 'base_url' => 'https://checkout.example.com/b?x=1', 'network_id' => 'gone'],
            ],
        ]);

        $result = $this->ops->destinations();

        $this->assertSame(2, $result['count']);
        $this->assertSame('https://checkout.example.com/a?subid={clickid}', $result['destinations'][0]['effective_url']);
        $this->assertSame('BuyGoods', $result['destinations'][0]['network_name']);
        $this->assertFalse($result['destinations'][0]['network_missing']);
        $this->assertTrue($result['destinations'][1]['network_missing']);
        $this->assertNull($result['destinations'][1]['network_name']);
        $this->assertSame('https://checkout.example.com/b?x=1', $result['destinations'][1]['effective_url']);
    }
}
