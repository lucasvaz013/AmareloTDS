<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/../../code/adminops.php';

/** Networks & Destinations (common.settings) and Landings (filesystem) library CRUD through AdminOps. */
final class LibraryOpsTest extends TestCase
{
    private TestDb $db;
    private string $dbPath;
    private AdminOps $ops;
    private string $landingsDir;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/amarelotds_lib_' . uniqid() . '.db';
        $this->db = new TestDb($this->dbPath);
        $this->db->initSchema();
        $this->ops = new AdminOps($this->db);
        $this->landingsDir = sys_get_temp_dir() . '/amarelotds_land_' . uniqid();
        mkdir($this->landingsDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->db->cleanup();
        if (is_dir($this->landingsDir)) {
            exec('rm -rf ' . escapeshellarg($this->landingsDir));
        }
    }

    public function testNetworkAddAssignsIdAndLists(): void
    {
        $res = $this->ops->networkAdd('BuyGoods', '?subid={clickid}', true);
        $this->assertFalse($res['dry_run']);
        $id = $res['network']['id'];
        $this->assertNotSame('', $id);
        $this->assertSame('subid={clickid}', $res['network']['params'], 'leading ? stripped');

        $list = $this->ops->networksList();
        $this->assertSame(1, $list['count']);
        $this->assertSame($id, $list['networks'][0]['id']);
    }

    public function testNetworkAddDryRunDoesNotWrite(): void
    {
        $this->ops->networkAdd('Ghost', '', false);
        $this->assertSame(0, $this->ops->networksList()['count']);
    }

    public function testNetworkAddRequiresName(): void
    {
        try {
            $this->ops->networkAdd('  ', 'x=1', true);
            $this->fail('expected YtdsOpError');
        } catch (YtdsOpError $e) {
            $this->assertSame('INVALID_ARG', $e->errorCode);
        }
    }

    public function testNetworkUpdateAndDelete(): void
    {
        $id = $this->ops->networkAdd('N', 'a=1', true)['network']['id'];
        $this->ops->networkUpdate($id, 'N2', null, true);
        $this->assertSame('N2', $this->ops->networksList()['networks'][0]['name']);
        $this->assertSame('a=1', $this->ops->networksList()['networks'][0]['params'], 'unset param unchanged');

        $this->ops->networkDelete($id, true);
        $this->assertSame(0, $this->ops->networksList()['count']);
    }

    public function testNetworkUpdateUnknownIsNotFound(): void
    {
        $this->expectException(YtdsOpError::class);
        $this->ops->networkUpdate('nope', 'x', null, false);
    }

    public function testDestinationLinkedToNetworkComposesEffectiveUrl(): void
    {
        $nid = $this->ops->networkAdd('BuyGoods', 'subid={clickid}', true)['network']['id'];
        $did = $this->ops->destinationAdd('Checkout', 'checkout.example.com/a', $nid, true)['destination']['id'];

        $dest = $this->ops->destinations()['destinations'][0];
        $this->assertSame($did, $dest['id']);
        $this->assertSame('https://checkout.example.com/a', $dest['base_url'], 'scheme added');
        $this->assertSame('https://checkout.example.com/a?subid={clickid}', $dest['effective_url']);
        $this->assertFalse($dest['network_missing']);
    }

    public function testDeletingNetworkDegradesLinkedDestination(): void
    {
        $nid = $this->ops->networkAdd('N', 'a=1', true)['network']['id'];
        $this->ops->destinationAdd('D', 'https://x.example.com', $nid, true);
        $this->ops->networkDelete($nid, true);

        $dest = $this->ops->destinations()['destinations'][0];
        $this->assertTrue($dest['network_missing']);
        $this->assertSame('https://x.example.com', $dest['effective_url'], 'degrades to base only');
    }

    public function testDestinationAddRequiresNameAndBase(): void
    {
        $this->expectException(YtdsOpError::class);
        $this->ops->destinationAdd('OnlyName', '', null, true);
    }

    public function testDestinationUpdateAndDelete(): void
    {
        $did = $this->ops->destinationAdd('D', 'https://a.example.com', null, true)['destination']['id'];
        $this->ops->destinationUpdate($did, null, 'https://b.example.com', null, true);
        $this->assertSame('https://b.example.com', $this->ops->destinations()['destinations'][0]['base_url']);

        $this->ops->destinationDelete($did, true);
        $this->assertSame(0, $this->ops->destinations()['count']);
    }

    public function testDestinationDeleteUnknownIsNotFound(): void
    {
        try {
            $this->ops->destinationDelete('nope', true);
            $this->fail('expected YtdsOpError');
        } catch (YtdsOpError $e) {
            $this->assertSame('DESTINATION_NOT_FOUND', $e->errorCode);
        }
    }

    public function testNetworkDeleteIsBlockedWhenCheckoutRouteUsesIt(): void
    {
        $nid = $this->ops->networkAdd('N', '', true)['network']['id'];
        $did = $this->ops->destinationAdd('D', 'https://example.com', $nid, true)['destination']['id'];
        $this->db->seedCampaign(7, 'CampA', [
            'black' => [
                'flows' => [[
                    'name' => 'F1',
                    'steps' => [[
                        'checkout_routes' => [[
                            'network_id' => $nid,
                            'links' => [['n' => 1, 'destination_id' => $did]],
                        ]],
                    ]],
                ]],
            ],
        ]);

        foreach ([false, true] as $commit) {
            try {
                $this->ops->networkDelete($nid, $commit);
                $this->fail('expected YtdsOpError');
            } catch (YtdsOpError $e) {
                $this->assertSame('RESOURCE_IN_USE', $e->errorCode);
                $this->assertStringContainsString('CampA: F1 — step 1', $e->hint);
            }
        }
        $this->assertSame(1, $this->ops->networksList()['count']);
    }

    public function testDestinationDeleteIsBlockedWhenCheckoutRouteUsesIt(): void
    {
        $nid = $this->ops->networkAdd('N', '', true)['network']['id'];
        $did = $this->ops->destinationAdd('D', 'https://example.com', $nid, true)['destination']['id'];
        $this->db->seedCampaign(7, 'CampA', [
            'black' => [
                'flows' => [[
                    'name' => 'F1',
                    'steps' => [[
                        'checkout_routes' => [[
                            'network_id' => $nid,
                            'links' => [['n' => 1, 'destination_id' => $did]],
                        ]],
                    ]],
                ]],
            ],
        ]);

        foreach ([false, true] as $commit) {
            try {
                $this->ops->destinationDelete($did, $commit);
                $this->fail('expected YtdsOpError');
            } catch (YtdsOpError $e) {
                $this->assertSame('RESOURCE_IN_USE', $e->errorCode);
                $this->assertStringContainsString('CampA: F1 — step 1', $e->hint);
            }
        }
        $this->assertSame(1, $this->ops->destinations()['count']);
    }

    public function testDestinationNetworkReassignmentIsBlockedWhenCheckoutRouteUsesIt(): void
    {
        $networkA = $this->ops->networkAdd('A', '', true)['network']['id'];
        $networkB = $this->ops->networkAdd('B', '', true)['network']['id'];
        $destinationId = $this->ops->destinationAdd('D', 'https://example.com', $networkA, true)['destination']['id'];
        $this->db->seedCampaign(7, 'CampA', [
            'black' => [
                'flows' => [[
                    'name' => 'F1',
                    'steps' => [[
                        'checkout_routes' => [[
                            'network_id' => $networkA,
                            'links' => [['n' => 1, 'destination_id' => $destinationId]],
                        ]],
                    ]],
                ]],
            ],
        ]);

        foreach ([false, true] as $commit) {
            try {
                $this->ops->destinationUpdate($destinationId, null, null, $networkB, $commit);
                $this->fail('expected YtdsOpError');
            } catch (YtdsOpError $e) {
                $this->assertSame('RESOURCE_IN_USE', $e->errorCode);
                $this->assertStringContainsString('CampA: F1 — step 1', $e->hint);
            }
        }

        $this->assertSame($networkA, $this->ops->destinations()['destinations'][0]['network_id']);
    }

    public function testMalformedCheckoutRoutesBlockLibraryDeletion(): void
    {
        $nid = $this->ops->networkAdd('N', '', true)['network']['id'];
        $this->db->seedCampaign(7, 'Broken', [
            'black' => ['flows' => [['name' => 'F1', 'steps' => [['checkout_routes' => 'broken']]]]],
        ]);

        try {
            $this->ops->networkDelete($nid, false);
            $this->fail('expected YtdsOpError');
        } catch (YtdsOpError $e) {
            $this->assertSame('SETTINGS_CORRUPT', $e->errorCode);
            $this->assertStringContainsString('Broken: F1 — step 1', $e->hint);
        }
    }

    public function testPanelCatalogReplaceBlocksRemovingIdsUsedByCheckoutRoutes(): void
    {
        $nid = $this->ops->networkAdd('N', '', true)['network']['id'];
        $did = $this->ops->destinationAdd('D', 'https://example.com', $nid, true)['destination']['id'];
        $this->db->seedCampaign(7, 'CampA', [
            'black' => [
                'flows' => [[
                    'name' => 'F1',
                    'steps' => [[
                        'checkout_routes' => [[
                            'network_id' => $nid,
                            'links' => [['n' => 1, 'destination_id' => $did]],
                        ]],
                    ]],
                ]],
            ],
        ]);

        $common = $this->db->get_common_settings();
        try {
            $this->ops->assertRemovedLibraryIdsUnused('destination', $common['destinations'], []);
            $this->fail('expected YtdsOpError');
        } catch (YtdsOpError $e) {
            $this->assertSame('RESOURCE_IN_USE', $e->errorCode);
            $this->assertStringContainsString('CampA: F1 — step 1', $e->hint);
        }
        try {
            $this->ops->assertRemovedLibraryIdsUnused('network', $common['networks'], []);
            $this->fail('expected YtdsOpError');
        } catch (YtdsOpError $e) {
            $this->assertSame('RESOURCE_IN_USE', $e->errorCode);
        }

        $this->ops->assertRemovedLibraryIdsUnused('destination', $common['destinations'], $common['destinations']);
        $this->ops->assertRemovedLibraryIdsUnused('network', $common['networks'], $common['networks']);
    }

    private function makeZip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ytds_ziptest_') . '.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        return $path;
    }

    public function testLandingUploadExtractsZipAndDescribes(): void
    {
        $zip = $this->makeZip(['index.html' => '<h1>hi</h1>', 'css/app.css' => 'body{}']);
        $dry = $this->ops->landingUpload($this->landingsDir, 'promo', $zip, false);
        $this->assertTrue($dry['dry_run']);
        $this->assertSame(0, $this->ops->landings($this->landingsDir)['count'], 'dry-run does not write');

        $res = $this->ops->landingUpload($this->landingsDir, 'promo', $zip, true);
        $this->assertSame(2, $res['landing']['files']);
        $this->assertTrue($res['landing']['hasIndex']);
        $this->assertSame(1, $this->ops->landings($this->landingsDir)['count']);
    }

    public function testLandingUploadRejectsZipSlip(): void
    {
        $zip = $this->makeZip(['../escape.txt' => 'x']);
        try {
            $this->ops->landingUpload($this->landingsDir, 'evil', $zip, true);
            $this->fail('expected YtdsOpError');
        } catch (YtdsOpError $e) {
            $this->assertSame('INVALID_ARG', $e->errorCode);
        }
        $this->assertSame(0, $this->ops->landings($this->landingsDir)['count']);
    }

    public function testLandingUploadRejectsSymbolicLinkEntry(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ytds_ziptest_') . '.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('index.html', 'x');
        $zip->addFromString('assets-link', '../../outside');
        $zip->setExternalAttributesName('assets-link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $zip->close();

        try {
            $this->ops->landingUpload($this->landingsDir, 'evil-link', $path, true);
            $this->fail('expected YtdsOpError');
        } catch (YtdsOpError $e) {
            $this->assertSame('INVALID_ARG', $e->errorCode);
        }
        $this->assertSame(0, $this->ops->landings($this->landingsDir)['count']);
        @unlink($path);
    }

    public function testLandingUploadRejectsCollision(): void
    {
        $zip = $this->makeZip(['index.html' => 'x']);
        $this->ops->landingUpload($this->landingsDir, 'dup', $zip, true);
        $this->expectException(YtdsOpError::class);
        $this->ops->landingUpload($this->landingsDir, 'dup', $zip, true);
    }

    public function testLandingDuplicateAndDelete(): void
    {
        $zip = $this->makeZip(['index.html' => 'x']);
        $this->ops->landingUpload($this->landingsDir, 'a', $zip, true);
        $this->ops->landingDuplicate($this->landingsDir, 'a', 'b', true);
        $this->assertSame(2, $this->ops->landings($this->landingsDir)['count']);
        $this->ops->landingDelete($this->landingsDir, 'a', true);
        $this->assertSame(1, $this->ops->landings($this->landingsDir)['count']);
    }

    public function testLandingDeleteReportsCampaignUsage(): void
    {
        $this->db->seedCampaign(7, 'CampA', ['black' => ['flows' => [['name' => 'F1', 'steps' => [['folders' => [['name' => 'promo']]]]]]]]);
        $zip = $this->makeZip(['index.html' => 'x']);
        $this->ops->landingUpload($this->landingsDir, 'promo', $zip, true);
        $dry = $this->ops->landingDelete($this->landingsDir, 'promo', false);
        $this->assertContains('CampA: F1 — step 1', $dry['used_by']);
    }

    public function testLandingDeleteUnknownIsNotFound(): void
    {
        try {
            $this->ops->landingDelete($this->landingsDir, 'ghost', true);
            $this->fail('expected YtdsOpError');
        } catch (YtdsOpError $e) {
            $this->assertSame('LANDING_NOT_FOUND', $e->errorCode);
        }
    }

    public function testLandingDownloadCreatesVerifiedZip(): void
    {
        $zip = $this->makeZip(['index.html' => '<h1>download</h1>', 'assets/a.js' => 'a']);
        $this->ops->landingUpload($this->landingsDir, 'downloadable', $zip, true);
        $output = tempnam(sys_get_temp_dir(), 'ytds_download_') . '.zip';

        $result = $this->ops->landingDownload($this->landingsDir, 'downloadable', $output);

        $this->assertFileExists($output);
        $this->assertSame('landing.download', $result['action']);
        $this->assertSame(hash_file('sha256', $output), $result['sha256']);
        $this->assertSame(2, $result['files']);
        @unlink($output);
    }

    public function testLandingEditIsDryRunByDefaultAndExactOnCommit(): void
    {
        $zip = $this->makeZip(['index.html' => '<a href="old">Buy</a><script>delay=10;</script>']);
        $this->ops->landingUpload($this->landingsDir, 'editable', $zip, true);
        $replacements = [
            ['search' => 'href="old"', 'replace' => 'href="{link:1}"', 'expected' => 1],
            ['search' => 'delay=10', 'replace' => 'delay=90', 'expected' => 1],
        ];

        $dry = $this->ops->landingEdit($this->landingsDir, 'editable', 'index.html', $replacements, false);
        $this->assertTrue($dry['dry_run']);
        $this->assertSame('<a href="old">Buy</a><script>delay=10;</script>', file_get_contents($this->landingsDir . '/editable/index.html'));

        $done = $this->ops->landingEdit($this->landingsDir, 'editable', 'index.html', $replacements, true);
        $this->assertFalse($done['dry_run']);
        $this->assertNotSame($done['before_sha256'], $done['after_sha256']);
        $this->assertSame('<a href="{link:1}">Buy</a><script>delay=90;</script>', file_get_contents($this->landingsDir . '/editable/index.html'));
    }

    public function testLandingEditRejectsWrongExpectedCountWithoutWriting(): void
    {
        $zip = $this->makeZip(['index.html' => 'same same']);
        $this->ops->landingUpload($this->landingsDir, 'counts', $zip, true);

        try {
            $this->ops->landingEdit($this->landingsDir, 'counts', 'index.html', [
                ['search' => 'same', 'replace' => 'new', 'expected' => 1],
            ], true);
            $this->fail('expected replacement count mismatch');
        } catch (YtdsOpError $e) {
            $this->assertSame('REPLACEMENT_COUNT_MISMATCH', $e->errorCode);
        }
        $this->assertSame('same same', file_get_contents($this->landingsDir . '/counts/index.html'));
    }

    public function testLandingReplaceIsAtomicAndDryRunSafe(): void
    {
        $old = $this->makeZip(['index.html' => 'old', 'old.txt' => 'old']);
        $new = $this->makeZip(['index.html' => 'new', 'new.txt' => 'new']);
        $this->ops->landingUpload($this->landingsDir, 'replaceable', $old, true);

        $dry = $this->ops->landingReplace($this->landingsDir, 'replaceable', $new, false);
        $this->assertTrue($dry['dry_run']);
        $this->assertSame('old', file_get_contents($this->landingsDir . '/replaceable/index.html'));

        $done = $this->ops->landingReplace($this->landingsDir, 'replaceable', $new, true);
        $this->assertFalse($done['dry_run']);
        $this->assertSame('new', file_get_contents($this->landingsDir . '/replaceable/index.html'));
        $this->assertFileDoesNotExist($this->landingsDir . '/replaceable/old.txt');
        $this->assertFileExists($this->landingsDir . '/replaceable/new.txt');
    }
}
