<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/../../code/campaignservice.php';

final class CampaignMutationTest extends TestCase
{
    private TestDb $db;
    private string $dbPath;
    private CampaignService $svc;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/amarelotds_mut_' . uniqid() . '.db';
        $this->db = new TestDb($this->dbPath);
        $this->db->initSchema();
        $this->db->seedCampaign(1, 'alpha', ['domains' => ['a.example.com'], 'apikey' => 'REALKEY', 'black' => ['flows' => []]]);
        $this->db->seedCampaign(2, 'beta', ['domains' => ['b.example.com']]);
        $this->svc = new CampaignService($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->cleanup();
    }

    private function names(): array
    {
        return array_column($this->svc->list(), 'name');
    }

    public function testCloneDryRunDoesNotCreate(): void
    {
        $res = $this->svc->cloneCampaign(1, 'MyClone', false);
        $this->assertTrue($res['dry_run']);
        $this->assertSame('MyClone', $res['new_name']);
        $this->assertSame(['alpha', 'beta'], $this->names());
    }

    public function testCloneCommitCreatesWithResetDomains(): void
    {
        $res = $this->svc->cloneCampaign(1, 'MyClone', true);
        $this->assertFalse($res['dry_run']);
        $this->assertSame(1, $res['source_id']);
        $clone = $this->svc->get($res['id']);
        $this->assertSame('MyClone', $clone['name']);
        $this->assertSame([], $clone['settings']['domains'], 'clone must reset domains to avoid overlap');
    }

    public function testCloneDefaultNameSuffix(): void
    {
        $res = $this->svc->cloneCampaign(1, null, true);
        $this->assertSame('alpha (Clone)', $res['name']);
    }

    public function testRenameDryRunThenCommit(): void
    {
        $dry = $this->svc->renameCampaign(1, 'renamed', false);
        $this->assertTrue($dry['dry_run']);
        $this->assertSame(['before' => 'alpha', 'after' => 'renamed'], ['before' => $dry['before'], 'after' => $dry['after']]);
        $this->assertSame(['alpha', 'beta'], $this->names());

        $this->svc->renameCampaign(1, 'renamed', true);
        $this->assertContains('renamed', $this->names());
        $this->assertNotContains('alpha', $this->names());
    }

    public function testRenameEmptyIsInvalidArg(): void
    {
        $this->expectException(YtdsOpError::class);
        $this->svc->renameCampaign(1, '   ', false);
    }

    public function testDeleteDryRunThenCommit(): void
    {
        $dry = $this->svc->deleteCampaign(2, false);
        $this->assertTrue($dry['dry_run']);
        $this->assertSame('beta', $dry['name']);
        $this->assertSame(['alpha', 'beta'], $this->names());

        $this->svc->deleteCampaign(2, true);
        $this->assertSame(['alpha'], $this->names());
    }

    public function testSetDomainsDryRunDiffThenCommit(): void
    {
        $dry = $this->svc->setDomains(1, ['x.example.com', 'y.example.com'], false);
        $this->assertTrue($dry['dry_run']);
        $this->assertSame(['a.example.com'], $dry['before']);
        $this->assertSame(['x.example.com', 'y.example.com'], $dry['after']);
        // not written yet
        $this->assertSame(['a.example.com'], $this->svc->get(1)['settings']['domains']);

        $this->svc->setDomains(1, ['x.example.com'], true);
        $this->assertSame(['x.example.com'], $this->svc->get(1)['settings']['domains']);
    }

    public function testSetDomainsPreservesSecretsInRawStorage(): void
    {
        $this->svc->setDomains(1, ['x.example.com'], true);
        // The read path redacts, but the raw stored settings must keep the real apikey.
        $raw = $this->db->get_campaign_settings(1);
        $this->assertSame('REALKEY', $raw['apikey'], 'a domain write must not clobber the apikey');
    }

    public function testSetDomainsOverlapIsDomainConflict(): void
    {
        try {
            $this->svc->setDomains(1, ['b.example.com'], false);
            $this->fail('expected YtdsOpError');
        } catch (YtdsOpError $e) {
            $this->assertSame('DOMAIN_CONFLICT', $e->errorCode);
            $this->assertSame(409, $e->httpStatus);
        }
    }

    public function testMutationsOnUnknownCampaignAreNotFound(): void
    {
        foreach ([
            fn() => $this->svc->cloneCampaign(99, null, false),
            fn() => $this->svc->renameCampaign(99, 'x', false),
            fn() => $this->svc->deleteCampaign(99, false),
            fn() => $this->svc->setDomains(99, [], false),
        ] as $call) {
            try {
                $call();
                $this->fail('expected YtdsOpError');
            } catch (YtdsOpError $e) {
                $this->assertSame('CAMPAIGN_NOT_FOUND', $e->errorCode);
            }
        }
    }

    public function testPatchDryRunDiffThenCommit(): void
    {
        $this->db->seedCampaign(3, 'flowy', [
            'apikey' => 'K3',
            'black' => ['flows' => [['name' => 'F1', 'filters' => [], 'steps' => [['action' => 'folder', 'folders' => [['name' => 'lp', 'loadtype' => 'base', 'weight' => 100, 'links' => []]]]]]]],
            'uniqueness' => ['enabled' => false],
        ]);
        $flow = ['name' => 'F1', 'filters' => [], 'steps' => [['action' => 'folder', 'folders' => [['name' => 'lp', 'loadtype' => 'base', 'weight' => 100, 'links' => [['n' => 1, 'url' => 'https://checkout.com/x?sub1={clickid}']]]]]]];

        $dry = $this->svc->patch(3, ['black' => ['flows' => [$flow]]], false);
        $this->assertTrue($dry['dry_run']);
        $this->assertSame(['black'], $dry['changed']);
        $this->assertSame([], $this->svc->get(3)['settings']['black']['flows'][0]['steps'][0]['folders'][0]['links'], 'dry-run must not write');

        $this->svc->patch(3, ['black' => ['flows' => [$flow]]], true);
        $raw = $this->db->get_campaign_settings(3);
        $this->assertSame([['n' => 1, 'url' => 'https://checkout.com/x?sub1={clickid}']], $raw['black']['flows'][0]['steps'][0]['folders'][0]['links']);
        $this->assertSame('K3', $raw['apikey'], 'patch must not clobber the apikey');
    }

    public function testPatchRunsPanelValidators(): void
    {
        $this->db->seedCampaign(3, 'flowy', ['black' => ['flows' => [['name' => 'F1', 'filters' => [], 'steps' => [['action' => 'folder', 'folders' => [['name' => 'lp', 'links' => []]]]]]]], 'uniqueness' => ['enabled' => false]]);
        $badFlow = ['name' => 'F1', 'filters' => [], 'steps' => [['action' => 'folder', 'folders' => [['name' => 'lp', 'links' => [['n' => 0, 'url' => 'https://a.com']]]]]]];
        try {
            $this->svc->patch(3, ['black' => ['flows' => [$badFlow]]], false);
            $this->fail('expected YtdsOpError');
        } catch (YtdsOpError $e) {
            $this->assertSame('VALIDATION', $e->errorCode);
            $this->assertStringContainsString('>= 1', $e->getMessage());
        }
    }

    public function testPatchDomainOverlapIsConflict(): void
    {
        // campaign 2 (beta) claims b.example.com; patching campaign 1 to it must conflict.
        $this->expectException(YtdsOpError::class);
        $this->svc->patch(1, ['domains' => ['b.example.com']], false);
    }

    public function testPatchRejectsWipingFragments(): void
    {
        // {} decodes to [] and a JSON list would both be list-merged into [] — a full settings wipe.
        foreach ([[], [1, 2]] as $bad) {
            try {
                $this->svc->patch(1, $bad, true); // even with commit, must refuse before writing
                $this->fail('expected YtdsOpError for ' . json_encode($bad));
            } catch (YtdsOpError $e) {
                $this->assertSame('INVALID_ARG', $e->errorCode);
            }
        }
        $this->assertSame(['a.example.com'], $this->svc->get(1)['settings']['domains'], 'settings intact after rejected patch');
    }

    /** @return array<string, mixed> */
    private function blankTemplate(): array
    {
        return json_decode((string)file_get_contents(__DIR__ . '/../../code/templates/blank.json'), true);
    }

    public function testCreateFromTemplateProducesSafeCampaign(): void
    {
        $before = count($this->svc->list());
        $res = $this->svc->create('My Offer', $this->blankTemplate(), true);
        $this->assertFalse($res['dry_run']);
        $this->assertCount($before + 1, $this->svc->list());

        $raw = $this->db->get_campaign_settings((int)$res['id']);
        $this->assertSame([], $raw['domains'], 'new campaign starts with no domains');
        $this->assertNotSame('', (string)($raw['apikey'] ?? ''), 'a fresh apikey is generated');
        $this->assertStringNotContainsString('rolltrk', strtolower(json_encode($raw)));
        $this->assertStringNotContainsString('roerads', strtolower(json_encode($raw)));
    }

    public function testCreateDryRunDoesNotWrite(): void
    {
        $before = count($this->svc->list());
        $res = $this->svc->create('Preview', $this->blankTemplate(), false);
        $this->assertTrue($res['dry_run']);
        $this->assertCount($before, $this->svc->list());
    }

    public function testCreateRejectsEmptyName(): void
    {
        $this->expectException(YtdsOpError::class);
        $this->svc->create('   ', $this->blankTemplate(), false);
    }

    public function testCreateRejectsListTemplate(): void
    {
        try {
            $this->svc->create('X', [1, 2], false);
            $this->fail('expected YtdsOpError');
        } catch (YtdsOpError $e) {
            $this->assertSame('INVALID_ARG', $e->errorCode);
        }
    }

    public function testCreateRejectsTemplateCarryingAuthorDefault(): void
    {
        // A template whose flow redirects to the author's tracker must be refused (Guardrail #9).
        $tpl = $this->blankTemplate();
        $tpl['black']['flows'] = [[
            'name' => 'F1', 'filters' => [], 'distribution' => 'equal', 'optimize_for' => 'Lead', 'optimize_mode' => 'funnels',
            'steps' => [['action' => 'redirect', 'folders' => [], 'redirect' => ['type' => 302, 'urls' => [['url' => 'https://www.rolltrk.com/x', 'weight' => 100]]]]],
        ]];
        try {
            $this->svc->create('X', $tpl, false);
            $this->fail('expected YtdsOpError');
        } catch (YtdsOpError $e) {
            $this->assertSame('VALIDATION', $e->errorCode);
            $this->assertStringContainsString('rolltrk', $e->getMessage());
        }
    }

    public function testSetFieldsUpdatesFieldsKeepingSection(): void
    {
        $this->db->seedCampaign(5, 'u', [
            'uniqueness' => ['enabled' => false, 'method' => 'cookie_ip_ua', 'ttl_hours' => 24, 'get_parameter' => ''],
            'apikey' => 'K5', 'black' => ['flows' => []],
        ]);
        $this->svc->setFields(5, ['uniqueness.enabled' => true, 'uniqueness.ttl_hours' => 48, 'saveuserflow' => true], true);
        $raw = $this->db->get_campaign_settings(5);
        $this->assertTrue($raw['uniqueness']['enabled']);
        $this->assertSame(48, $raw['uniqueness']['ttl_hours']);
        $this->assertSame('cookie_ip_ua', $raw['uniqueness']['method'], 'untouched field in the section survives');
        $this->assertTrue($raw['saveuserflow']);
        $this->assertSame('K5', $raw['apikey']);
    }

    public function testSetFieldsRejectsEmpty(): void
    {
        $this->expectException(YtdsOpError::class);
        $this->svc->setFields(1, [], false);
    }

    public function testKillAuthorDefaultsNeutralizesAllThree(): void
    {
        $def = json_decode((string)file_get_contents(__DIR__ . '/../../code/db/default.json'), true);
        $def['domains'] = [];
        $this->db->seedCampaign(5, 'authored', $def);

        $dry = $this->svc->killAuthorDefaults(5, false);
        $this->assertTrue($dry['dry_run']);
        $this->assertCount(3, $dry['removed']);

        $this->svc->killAuthorDefaults(5, true);
        $raw = json_encode($this->db->get_campaign_settings(5));
        $this->assertStringNotContainsStringIgnoringCase('rolltrk', $raw);
        $this->assertStringNotContainsStringIgnoringCase('roerads', $raw);

        // idempotent: nothing left to remove
        $this->assertSame([], $this->svc->killAuthorDefaults(5, false)['removed']);
    }
}
