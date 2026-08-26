<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/../../code/htmlprocessing.php';

final class CheckoutRoutesRoutingTest extends TestCase
{
    private TestDb $db;
    private string $dbPath;
    private string $runtimePath;
    private mixed $originalCachingDir;
    private mixed $originalDb;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/amarelotds_checkout_routing_' . uniqid('', true) . '.db';
        $this->db = new TestDb($this->dbPath);
        $this->db->initSchema();

        $runtimeName = 'runtime-checkout-routing-' . bin2hex(random_bytes(6));
        $this->runtimePath = __DIR__ . DIRECTORY_SEPARATOR . $runtimeName;
        $landingPath = $this->runtimePath . DIRECTORY_SEPARATOR . 'landings' . DIRECTORY_SEPARATOR . 'landing-a';
        self::assertTrue(mkdir($landingPath, 0755, true));
        file_put_contents(
            $landingPath . DIRECTORY_SEPARATOR . 'index.html',
            '<html><head></head><body><a href="{link:1}">Buy</a></body></html>'
        );

        $this->originalCachingDir = $GLOBALS['cloSettings']['cachingDir'];
        $this->originalDb = $GLOBALS['db'] ?? null;
        $GLOBALS['cloSettings']['cachingDir'] = '../tests/engine/' . $runtimeName;
        $GLOBALS['db'] = $this->db;
    }

    protected function tearDown(): void
    {
        $GLOBALS['cloSettings']['cachingDir'] = $this->originalCachingDir;
        if ($this->originalDb !== null) {
            $GLOBALS['db'] = $this->originalDb;
        } else {
            unset($GLOBALS['db']);
        }
        $this->db->cleanup();
        $this->removeDirectory($this->runtimePath);
    }

    public function testDirectLoadUsesFrozenCheckoutUrlAndIgnoresLaterLibraryLinks(): void
    {
        $frozenUrl = 'https://frozen.example/offer?cid=CLICK-FROZEN';
        $settings = json_decode(
            (string)file_get_contents(__DIR__ . '/../../code/db/default.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $settings['black']['flows'] = [[
            'name' => 'Flow',
            'steps' => [[
                'action' => 'folder',
                'folders' => [[
                    'name' => 'landing-a',
                    'loadtype' => 'direct',
                    'weight' => 100,
                    'mvt' => ['enabled' => false, 'tests' => []],
                    'links' => [['n' => 1, 'url' => 'https://legacy.example/should-not-win']],
                ]],
                'checkout_routes' => [[
                    'network_id' => 'n1',
                    'weight' => 100,
                    'links' => [['n' => 1, 'destination_id' => 'd1']],
                ]],
                'redirect' => ['urls' => [], 'type' => 302],
            ]],
        ]];
        $this->db->seedCampaign(1, 'Camp', $settings);
        $this->db->seedClicks([[
            'campaign_id' => 1,
            'clickid' => 'click-frozen',
            'flow' => 'Flow',
            'path' => ['landing-a'],
            'step' => 0,
            'params' => json_encode([
                '_ytds_network_id' => 'n1',
                '_ytds_network_name' => 'Network 1',
                '_ytds_checkout' => [
                    'step' => 0,
                    'links' => [['n' => 1, 'url' => $frozenUrl]],
                ],
            ]),
        ]]);

        $campaign = new Campaign(1, $this->db->get_campaign_settings(1));
        $html = load_step($campaign, $campaign->black->flows[0], 0, 'landing-a', 'click-frozen', true);

        self::assertStringContainsString($frozenUrl, $html);
        self::assertStringNotContainsString('legacy.example', $html);
        self::assertStringNotContainsString('{link:1}', $html);
        self::assertStringContainsString("<base href='/__dl/click-frozen/0/'>", $html);
    }

    public function testUnreachedDirectLoadStepStaysNotFound(): void
    {
        $this->db->seedCampaign(1, 'Camp', [
            'black' => ['flows' => [[
                'name' => 'Flow',
                'steps' => [
                    [
                        'action' => 'folder',
                        'folders' => [['name' => 'landing-a', 'loadtype' => 'direct', 'weight' => 100]],
                    ],
                    [
                        'action' => 'folder',
                        'folders' => [['name' => 'landing-b', 'loadtype' => 'direct', 'weight' => 100]],
                    ],
                ],
            ]]],
        ]);
        $this->db->seedClicks([[
            'campaign_id' => 1,
            'clickid' => 'click-step0',
            'flow' => 'Flow',
            'path' => ['landing-a', 'landing-b'],
            'step' => 0,
        ]]);

        $click = $this->db->get_click_by_clickid('click-step0');
        self::assertSame(0, (int)$click['step']);
        self::assertNull($this->db->get_click_step_variant('click-step0', 1));
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($full)) {
                $this->removeDirectory($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($path);
    }
}
