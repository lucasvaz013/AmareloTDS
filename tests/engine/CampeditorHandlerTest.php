<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TestDb.php';
if (!defined('AMARELOTDS_CAMPEDITOR_NO_RUN')) {
    define('AMARELOTDS_CAMPEDITOR_NO_RUN', true);
}
require_once __DIR__ . '/../../code/admin/campeditor.php';

/**
 * The panel save path (campeditor_handle) now routes through CampaignService. These tests give it
 * the automated coverage it lacked, so the panel and the ytds CLI provably share one code path.
 */
final class CampeditorHandlerTest extends TestCase
{
    private TestDb $db;
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/amarelotds_campedit_' . uniqid() . '.db';
        $this->db = new TestDb($this->dbPath);
        $this->db->initSchema();
        $this->db->seedCampaign(1, 'alpha', ['domains' => ['a.example.com'], 'apikey' => 'REALKEY', 'black' => ['flows' => []], 'uniqueness' => ['enabled' => false]]);
        $this->db->seedCampaign(2, 'beta', ['domains' => ['b.example.com']]);
    }

    protected function tearDown(): void
    {
        $this->db->cleanup();
    }

    private function names(): array
    {
        return array_map(static fn(array $r): string => (string)$r['name'], $this->db->get_campaigns_list());
    }

    public function testAddCreatesCampaign(): void
    {
        $res = campeditor_handle($this->db, 'add', 'Fresh', -1, '');
        $this->assertTrue($res['ok']);
        $this->assertArrayHasKey('campId', $res);
        $this->assertContains('Fresh', $this->names());
    }

    public function testRenameChangesName(): void
    {
        $res = campeditor_handle($this->db, 'ren', 'Renamed', 1, '');
        $this->assertTrue($res['ok']);
        $this->assertContains('Renamed', $this->names());
        $this->assertNotContains('alpha', $this->names());
    }

    public function testDeleteRemovesCampaign(): void
    {
        $res = campeditor_handle($this->db, 'del', '', 2, '');
        $this->assertTrue($res['ok']);
        $this->assertSame(['alpha'], $this->names());
    }

    public function testDuplicateClonesWithNameAndResetsDomains(): void
    {
        $res = campeditor_handle($this->db, 'dup', 'Fork', 1, '');
        $this->assertTrue($res['ok']);
        $clone = (new CampaignService($this->db))->get((int)$res['campId']);
        $this->assertSame('Fork', $clone['name']);
        $this->assertSame([], $clone['settings']['domains']);
    }

    public function testDuplicateRequiresName(): void
    {
        $res = campeditor_handle($this->db, 'dup', '', 1, '');
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('name can not be empty', $res['message']);
    }

    public function testSaveMergesSettingsAndKeepsSecret(): void
    {
        $res = campeditor_handle($this->db, 'save', '', 1, json_encode(['domains' => ['saved.example.com']]));
        $this->assertTrue($res['ok'], $res['message']);
        $raw = $this->db->get_campaign_settings(1);
        $this->assertSame(['saved.example.com'], $raw['domains']);
        $this->assertSame('REALKEY', $raw['apikey'], 'save must not clobber the apikey');
    }

    public function testSaveRejectsInvalidJsonBody(): void
    {
        $res = campeditor_handle($this->db, 'save', '', 1, 'not-json{');
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('invalid JSON body', $res['message']);
    }

    public function testSaveSurfacesValidatorError(): void
    {
        $body = json_encode(['black' => ['flows' => [['name' => 'F', 'filters' => [], 'steps' => [['action' => 'folder', 'folders' => [['name' => 'lp', 'links' => [['n' => 0, 'url' => 'https://a.com']]]]]]]]]]);
        $res = campeditor_handle($this->db, 'save', '', 1, $body);
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('>= 1', $res['message']);
    }

    public function testSaveSurfacesDomainConflict(): void
    {
        // campaign 2 (beta) claims b.example.com; saving it onto campaign 1 must be rejected.
        $res = campeditor_handle($this->db, 'save', '', 1, json_encode(['domains' => ['b.example.com']]));
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('conflict', strtolower($res['message']));
    }

    public function testUnknownActionIsRejected(): void
    {
        $res = campeditor_handle($this->db, 'frobnicate', '', 1, '');
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('wrong action', $res['message']);
    }
}
