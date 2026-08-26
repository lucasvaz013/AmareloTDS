<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TestDb.php';

/**
 * Contract tests for bin/ytds, exercised as a real subprocess — exit codes, stdout carrying only
 * result JSON, stderr carrying only {code, message, hint}. Commands run with cwd = temp dir to
 * prove the CLI is cwd-independent, and with YTDS_CONFIG pointed at an isolated (missing) path so
 * they never read the operator's real remote config.
 *
 * Changing any assertion here is a contract break for the agents consuming the CLI; do it
 * deliberately.
 */
final class YtdsCliContractTest extends TestCase
{
    private TestDb $db;
    private string $dbPath;
    private string $isolatedConfig;

    /** noon UTC 14.11.2023 — inside the 14.11.23 day in Europe/Moscow (+3). */
    private const SEED_TIME = 1699963200;
    private const SEED_DATE = '14.11.23';

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/amarelotds_yctl_' . uniqid() . '.db';
        $this->isolatedConfig = sys_get_temp_dir() . '/amarelotds_yctl_noconfig_' . uniqid() . '.json';
        $this->db = new TestDb($this->dbPath);
        $this->db->initSchema();
        $this->db->seedCampaign(1, 'alpha', [
            'statistics' => ['timezone' => 'Europe/Moscow'],
            'domains' => ['ytds.example.com', '*.alt.example.com'],
            'apikey' => 'SECRET_APIKEY_VALUE',
            'black' => ['flows' => [
                ['name' => 'Flow 1', 'filters' => [], 'steps' => []],
                ['name' => 'Flow 2', 'filters' => [], 'steps' => []],
            ]],
            'capi' => ['enabled' => false, 'pixel_id' => '', 'access_token' => 'SECRET_CAPI_TOKEN'],
            'postback' => ['pbkey' => ['enabled' => true, 'keys' => ['SECRET_PBKEY']]],
        ]);
        $this->db->seedCampaign(2, 'beta', []);
    }

    protected function tearDown(): void
    {
        $this->db->cleanup();
        @unlink($this->isolatedConfig);
    }

    /**
     * @param array<int, string> $args
     * @param array<string, string> $envOverrides
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runCli(array $args, array $envOverrides = []): array
    {
        $cmd = array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/bin/ytds'], $args);
        $env = $envOverrides + ['YTDS_CONFIG' => $this->isolatedConfig] + getenv();
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, sys_get_temp_dir(), $env);
        $this->assertIsResource($proc);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit' => proc_close($proc), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** @return array<string, mixed> */
    private function assertCleanJson(array $run): array
    {
        $this->assertSame(0, $run['exit'], 'stderr: ' . $run['stderr']);
        $this->assertSame('', $run['stderr'], 'success must not write to stderr');
        $decoded = json_decode($run['stdout'], true);
        $this->assertIsArray($decoded, 'stdout must be valid JSON, got: ' . $run['stdout']);
        return $decoded;
    }

    /** @return array{code: string, message: string, hint: string} */
    private function assertErrorContract(array $run, int $exit, string $code): array
    {
        $this->assertSame($exit, $run['exit']);
        $this->assertSame('', $run['stdout'], 'errors must not write to stdout');
        $decoded = json_decode($run['stderr'], true);
        $this->assertIsArray($decoded, 'stderr must be valid JSON, got: ' . $run['stderr']);
        $this->assertSame(['code', 'message', 'hint'], array_keys($decoded));
        $this->assertSame($code, $decoded['code']);
        return $decoded;
    }

    private function seedTwoClicks(): void
    {
        $this->db->seedClicks([
            ['clickid' => 'c1', 'campaign_id' => 1, 'time' => self::SEED_TIME, 'country' => 'US', 'device' => 'desktop', 'status' => 'Purchase', 'payout' => 10.0, 'flow' => 'Flow 1', 'path' => ['lp'], 'step' => 0],
            ['clickid' => 'c2', 'campaign_id' => 1, 'time' => self::SEED_TIME, 'country' => 'BR', 'device' => 'mobile', 'flow' => 'Flow 1', 'path' => ['lp'], 'step' => 0],
        ]);
    }

    // ── Phase 0: campaigns / campaign get / doctor (local) ──────────────────

    public function testCampaignsListNarrowContract(): void
    {
        $payload = $this->assertCleanJson($this->runCli(['campaigns', 'list', '--db', $this->dbPath]));

        $this->assertSame([
            'campaigns' => [
                ['id' => 1, 'name' => 'alpha', 'domains' => ['ytds.example.com', '*.alt.example.com'], 'flows' => 2],
                ['id' => 2, 'name' => 'beta', 'domains' => [], 'flows' => 0],
            ],
            'count' => 2,
        ], $payload);
    }

    public function testCampaignGetNarrowByDefault(): void
    {
        $payload = $this->assertCleanJson($this->runCli(['campaign', 'get', '1', '--db', $this->dbPath]));
        $this->assertSame(
            ['id' => 1, 'name' => 'alpha', 'domains' => ['ytds.example.com', '*.alt.example.com'], 'flows' => 2],
            $payload
        );
    }

    public function testCampaignGetFullRedactsSecrets(): void
    {
        $run = $this->runCli(['campaign', 'get', '1', '--full', '--db', $this->dbPath]);
        $payload = $this->assertCleanJson($run);

        $this->assertStringNotContainsString('SECRET_', $run['stdout']);
        $this->assertSame('<redacted>', $payload['settings']['apikey']);
        $this->assertSame('<redacted>', $payload['settings']['capi']['access_token']);
        $this->assertSame(['<redacted>'], $payload['settings']['postback']['pbkey']['keys']);
    }

    public function testCampaignGetSection(): void
    {
        $payload = $this->assertCleanJson(
            $this->runCli(['campaign', 'get', '1', '--section', 'black.flows', '--db', $this->dbPath])
        );
        $this->assertSame('black.flows', $payload['section']);
        $this->assertCount(2, $payload['value']);
    }

    public function testUnknownSectionListsAvailableOnes(): void
    {
        $error = $this->assertErrorContract(
            $this->runCli(['campaign', 'get', '1', '--section', 'nao.existe', '--db', $this->dbPath]),
            3,
            'SECTION_NOT_FOUND'
        );
        $this->assertStringContainsString('black', $error['hint']);
    }

    public function testUnknownCampaignIdIsExit3(): void
    {
        $this->assertErrorContract($this->runCli(['campaign', 'get', '9999', '--db', $this->dbPath]), 3, 'CAMPAIGN_NOT_FOUND');
    }

    public function testNonNumericIdIsExit2(): void
    {
        $this->assertErrorContract($this->runCli(['campaign', 'get', 'abc', '--db', $this->dbPath]), 2, 'INVALID_ARG');
    }

    public function testMissingDatabaseIsExit3AndNeverCreated(): void
    {
        $missing = sys_get_temp_dir() . '/amarelotds_yctl_missing_' . uniqid() . '.db';
        $this->assertErrorContract($this->runCli(['campaigns', 'list', '--db', $missing]), 3, 'DB_NOT_FOUND');
        $this->assertFileDoesNotExist($missing);
    }

    public function testUnknownCommandIsUsage(): void
    {
        $error = $this->assertErrorContract($this->runCli(['frobnicate']), 2, 'USAGE');
        $this->assertStringContainsString('campaigns list', $error['hint']);
    }

    public function testSectionFlagOutsideGetIsRejected(): void
    {
        $this->assertErrorContract(
            $this->runCli(['campaigns', 'list', '--section', 'black', '--db', $this->dbPath]),
            2,
            'INVALID_ARG'
        );
    }

    public function testReadCommandsNeverModifyTheDatabase(): void
    {
        // SQLite creates empty -wal/-shm sidecars even for read-only opens against a WAL-mode
        // database. The invariant is the main database file: its bytes must not change.
        $before = hash_file('sha256', $this->dbPath);

        $this->runCli(['campaigns', 'list', '--db', $this->dbPath]);
        $this->runCli(['campaign', 'get', '1', '--full', '--db', $this->dbPath]);
        $this->runCli(['stats', '--campaign', '1', '--db', $this->dbPath]);
        $this->runCli(['doctor', '--db', $this->dbPath]);

        clearstatcache();
        $this->assertSame($before, hash_file('sha256', $this->dbPath));
    }

    public function testDoctorHealthyDatabase(): void
    {
        $payload = $this->assertCleanJson($this->runCli(['doctor', '--db', $this->dbPath]));
        $this->assertTrue($payload['ok']);
        $byId = array_column($payload['checks'], null, 'id');
        $this->assertSame('7/7 tables', $byId['db.schema']['detail']);
        $this->assertArrayHasKey('admin.api', $byId);
    }

    public function testDoctorRejectsRemoteEnv(): void
    {
        $this->assertErrorContract($this->runCli(['doctor', '--env', 'stg', '--db', $this->dbPath]), 2, 'INVALID_ARG');
    }

    public function testCorruptSettingsJsonIsExit1(): void
    {
        $raw = new SQLite3($this->dbPath, SQLITE3_OPEN_READWRITE);
        $raw->exec("INSERT INTO campaigns (id, name, settings) VALUES (3, 'broken', 'not json{')");
        $raw->close();
        $this->assertErrorContract($this->runCli(['campaigns', 'list', '--db', $this->dbPath]), 1, 'SETTINGS_CORRUPT');
    }

    // ── Phase 1: version / landing / destinations / stats / clicks (local) ──

    public function testVersionLocal(): void
    {
        $payload = $this->assertCleanJson($this->runCli(['version', '--db', $this->dbPath]));
        $this->assertArrayHasKey('version', $payload);
        $this->assertSame(PHP_VERSION, $payload['php']);
    }

    public function testLandingListLocalShape(): void
    {
        $payload = $this->assertCleanJson($this->runCli(['landing', 'list', '--db', $this->dbPath]));
        $this->assertArrayHasKey('landings', $payload);
        $this->assertIsInt($payload['count']);
    }

    public function testDestinationsListLocalResolvesNetwork(): void
    {
        $this->db->set_common_settings([
            'networks' => [['id' => 'n1', 'name' => 'BuyGoods', 'params' => '?subid={clickid}']],
            'destinations' => [['id' => 'd1', 'name' => 'Checkout', 'base_url' => 'checkout.example.com/a', 'network_id' => 'n1']],
        ]);
        $payload = $this->assertCleanJson($this->runCli(['destinations', 'list', '--db', $this->dbPath]));
        $this->assertSame(1, $payload['count']);
        $this->assertSame('https://checkout.example.com/a?subid={clickid}', $payload['destinations'][0]['effective_url']);
    }

    public function testStatsLocalAggregates(): void
    {
        $this->seedTwoClicks();
        $payload = $this->assertCleanJson(
            $this->runCli(['stats', '--campaign', '1', '--from', self::SEED_DATE, '--to', self::SEED_DATE, '--db', $this->dbPath])
        );
        $this->assertSame(1, $payload['campaign']);
        $this->assertSame(2, $payload['rows'][0]['clicks']);
        $this->assertSame(1, $payload['rows'][0]['conversion']);
    }

    public function testStatsRequiresCampaign(): void
    {
        $this->assertErrorContract($this->runCli(['stats', '--db', $this->dbPath]), 2, 'INVALID_ARG');
    }

    public function testClicksLocalNarrowColumns(): void
    {
        $this->seedTwoClicks();
        $payload = $this->assertCleanJson(
            $this->runCli(['clicks', '--campaign', '1', '--from', self::SEED_DATE, '--to', self::SEED_DATE, '--limit', '10', '--db', $this->dbPath])
        );
        $this->assertSame(2, $payload['count']);
        $this->assertSame(['time', 'clickid', 'country', 'device', 'network_id', 'network', 'status', 'payout'], array_keys($payload['clicks'][0]));
    }

    public function testClicksTrafficbackNeedsNoCampaign(): void
    {
        $payload = $this->assertCleanJson(
            $this->runCli(['clicks', '--view', 'trafficback', '--from', self::SEED_DATE, '--to', self::SEED_DATE, '--db', $this->dbPath])
        );
        $this->assertNull($payload['campaign']);
        $this->assertSame('trafficback', $payload['view']);
    }

    public function testClicksRequiresCampaignUnlessTrafficback(): void
    {
        $this->assertErrorContract($this->runCli(['clicks', '--db', $this->dbPath]), 2, 'INVALID_ARG');
    }

    // ── Phase 1: remote routing (--env) ─────────────────────────────────────

    public function testRemoteEnvWithoutConfigIsAuthExit4(): void
    {
        // isolatedConfig does not exist → CONFIG_MISSING, mapped to the auth/config exit code.
        $this->assertErrorContract($this->runCli(['campaigns', 'list', '--env', 'stg']), 4, 'CONFIG_MISSING');
    }

    public function testRemoteTransportErrorIsExit1(): void
    {
        $config = sys_get_temp_dir() . '/amarelotds_yctl_cfg_' . uniqid() . '.json';
        file_put_contents($config, json_encode(['environments' => ['dead' => ['url' => 'http://127.0.0.1:1/api/admin.php', 'token' => 'x']]]));
        $error = $this->assertErrorContract(
            $this->runCli(['version', '--env', 'dead'], ['YTDS_CONFIG' => $config]),
            1,
            'TRANSPORT_ERROR'
        );
        $this->assertStringContainsString('dead', $error['message']);
        @unlink($config);
    }

    // ── Phase 2: safe mutations (dry-run by default, --yes commits) ──

    public function testCloneDryRunDoesNotCreate(): void
    {
        $p = $this->assertCleanJson($this->runCli(['campaign', 'clone', '1', '--db', $this->dbPath]));
        $this->assertTrue($p['dry_run']);
        $this->assertSame(2, $this->assertCleanJson($this->runCli(['campaigns', 'list', '--db', $this->dbPath]))['count']);
    }

    public function testCloneCommitCreates(): void
    {
        $p = $this->assertCleanJson($this->runCli(['campaign', 'clone', '1', '--name', 'Fork', '--yes', '--db', $this->dbPath]));
        $this->assertFalse($p['dry_run']);
        $this->assertSame('Fork', $p['name']);
        $this->assertSame(3, $this->assertCleanJson($this->runCli(['campaigns', 'list', '--db', $this->dbPath]))['count']);
    }

    public function testRenameRequiresName(): void
    {
        $this->assertErrorContract($this->runCli(['campaign', 'rename', '1', '--db', $this->dbPath]), 2, 'INVALID_ARG');
    }

    public function testDeleteDryRunThenCommit(): void
    {
        $dry = $this->assertCleanJson($this->runCli(['campaign', 'delete', '2', '--db', $this->dbPath]));
        $this->assertTrue($dry['dry_run']);
        $this->assertSame(2, $this->assertCleanJson($this->runCli(['campaigns', 'list', '--db', $this->dbPath]))['count']);

        $done = $this->assertCleanJson($this->runCli(['campaign', 'delete', '2', '--yes', '--db', $this->dbPath]));
        $this->assertFalse($done['dry_run']);
        $this->assertSame(1, $this->assertCleanJson($this->runCli(['campaigns', 'list', '--db', $this->dbPath]))['count']);
    }

    public function testDomainsOverlapIsExit5(): void
    {
        // campaign 1 already claims ytds.example.com; pointing campaign 2 at it must conflict.
        $this->assertErrorContract(
            $this->runCli(['campaign', 'domains', '2', '--set', 'ytds.example.com', '--yes', '--db', $this->dbPath]),
            5,
            'DOMAIN_CONFLICT'
        );
    }

    public function testDomainsCommitWrites(): void
    {
        $this->runCli(['campaign', 'domains', '2', '--set', 'new.example.com', '--yes', '--db', $this->dbPath]);
        $sec = $this->assertCleanJson($this->runCli(['campaign', 'get', '2', '--section', 'domains', '--db', $this->dbPath]));
        $this->assertSame(['new.example.com'], $sec['value']);
    }

    public function testDryRunMutationsNeverChangeDatabase(): void
    {
        $before = hash_file('sha256', $this->dbPath);
        $this->runCli(['campaign', 'clone', '1', '--db', $this->dbPath]);
        $this->runCli(['campaign', 'rename', '1', '--name', 'X', '--db', $this->dbPath]);
        $this->runCli(['campaign', 'delete', '2', '--db', $this->dbPath]);
        $this->runCli(['campaign', 'domains', '1', '--set', 'z.example.com', '--db', $this->dbPath]);
        clearstatcache();
        $this->assertSame($before, hash_file('sha256', $this->dbPath), 'dry-run must not write');
    }

    public function testPatchAppliesFragment(): void
    {
        $file = sys_get_temp_dir() . '/yctl_patch_' . uniqid() . '.json';
        file_put_contents($file, json_encode(['domains' => ['patched.example.com']]));

        $dry = $this->assertCleanJson($this->runCli(['campaign', 'patch', '1', '--apply', $file, '--db', $this->dbPath]));
        $this->assertTrue($dry['dry_run']);
        $this->assertContains('domains', $dry['changed']);

        $this->runCli(['campaign', 'patch', '1', '--apply', $file, '--yes', '--db', $this->dbPath]);
        $sec = $this->assertCleanJson($this->runCli(['campaign', 'get', '1', '--section', 'domains', '--db', $this->dbPath]));
        $this->assertSame(['patched.example.com'], $sec['value']);
        @unlink($file);
    }

    public function testPatchInvalidFragmentIsValidationExit2(): void
    {
        $file = sys_get_temp_dir() . '/yctl_patchbad_' . uniqid() . '.json';
        // a folder {link:N} with n=0 fails the same validator the panel save runs.
        file_put_contents($file, json_encode(['black' => ['flows' => [['name' => 'F', 'filters' => [], 'steps' => [['action' => 'folder', 'folders' => [['name' => 'lp', 'links' => [['n' => 0, 'url' => 'https://a.com']]]]]]]]]]));
        $this->assertErrorContract($this->runCli(['campaign', 'patch', '1', '--apply', $file, '--db', $this->dbPath]), 2, 'VALIDATION');
        @unlink($file);
    }

    public function testPatchCheckoutRoutesUsesSharedValidationInDryRunAndCommit(): void
    {
        $this->db->set_common_settings([
            'networks' => [['id' => 'n1', 'name' => 'Network 1', 'params' => 'cid={clickid}']],
            'destinations' => [['id' => 'd1', 'name' => 'Checkout 1', 'base_url' => 'https://checkout.example.com', 'network_id' => 'n1']],
        ]);
        $file = sys_get_temp_dir() . '/yctl_checkout_routes_' . uniqid() . '.json';
        file_put_contents($file, json_encode([
            'black' => ['flows' => [[
                'name' => 'Flow 1',
                'filters' => [],
                'steps' => [[
                    'action' => 'folder',
                    'folders' => [],
                    'checkout_routes' => [[
                        'network_id' => 'n1',
                        'weight' => 7,
                        'links' => [['n' => 1, 'destination_id' => 'd1']],
                    ]],
                ]],
            ]]],
        ]));

        $dry = $this->assertCleanJson($this->runCli(['campaign', 'patch', '1', '--apply', $file, '--db', $this->dbPath]));
        $this->assertTrue($dry['dry_run']);
        $this->assertContains('black', $dry['changed']);

        $this->runCli(['campaign', 'patch', '1', '--apply', $file, '--yes', '--db', $this->dbPath]);
        $section = $this->assertCleanJson($this->runCli(['campaign', 'get', '1', '--section', 'black.flows.0.steps.0.checkout_routes', '--db', $this->dbPath]));
        $this->assertSame(100, $section['value'][0]['weight']);
        $this->assertSame('n1', $section['value'][0]['network_id']);
        $this->assertSame([['n' => 1, 'destination_id' => 'd1']], $section['value'][0]['links']);
        @unlink($file);
    }

    public function testPatchRequiresApply(): void
    {
        $this->assertErrorContract($this->runCli(['campaign', 'patch', '1', '--db', $this->dbPath]), 2, 'INVALID_ARG');
    }

    public function testCreateFromTemplateCommits(): void
    {
        $before = $this->assertCleanJson($this->runCli(['campaigns', 'list', '--db', $this->dbPath]))['count'];
        $res = $this->assertCleanJson($this->runCli(['campaign', 'create', '--name', 'Fresh Offer', '--from-template', 'blank', '--yes', '--db', $this->dbPath]));
        $this->assertFalse($res['dry_run']);
        $this->assertSame('Fresh Offer', $res['name']);
        $after = $this->assertCleanJson($this->runCli(['campaigns', 'list', '--db', $this->dbPath]));
        $this->assertSame($before + 1, $after['count']);

        $sec = $this->assertCleanJson($this->runCli(['campaign', 'get', (string)$res['id'], '--section', 'domains', '--db', $this->dbPath]));
        $this->assertSame([], $sec['value']);
    }

    public function testCreateDryRunDoesNotWrite(): void
    {
        $before = $this->assertCleanJson($this->runCli(['campaigns', 'list', '--db', $this->dbPath]))['count'];
        $res = $this->assertCleanJson($this->runCli(['campaign', 'create', '--name', 'Preview', '--db', $this->dbPath]));
        $this->assertTrue($res['dry_run']);
        $this->assertSame($before, $this->assertCleanJson($this->runCli(['campaigns', 'list', '--db', $this->dbPath]))['count']);
    }

    public function testCreateRequiresName(): void
    {
        $this->assertErrorContract($this->runCli(['campaign', 'create', '--db', $this->dbPath]), 2, 'INVALID_ARG');
    }

    public function testCreateUnknownTemplateIsRejected(): void
    {
        $this->assertErrorContract($this->runCli(['campaign', 'create', '--name', 'X', '--from-template', 'ghost', '--db', $this->dbPath]), 2, 'INVALID_ARG');
    }

    public function testPatchSetCommits(): void
    {
        $this->db->seedCampaign(9, 'u', [
            'uniqueness' => ['enabled' => false, 'method' => 'cookie_ip_ua', 'ttl_hours' => 24, 'get_parameter' => ''],
            'black' => ['flows' => []],
        ]);
        $res = $this->assertCleanJson($this->runCli(['campaign', 'patch', '9', '--set', 'uniqueness.enabled=true', '--set', 'uniqueness.ttl_hours=48', '--yes', '--db', $this->dbPath]));
        $this->assertFalse($res['dry_run']);
        $sec = $this->assertCleanJson($this->runCli(['campaign', 'get', '9', '--section', 'uniqueness', '--db', $this->dbPath]));
        $this->assertTrue($sec['value']['enabled']);
        $this->assertSame(48, $sec['value']['ttl_hours']);
    }

    public function testPatchSetAndApplyAreMutuallyExclusive(): void
    {
        $this->assertErrorContract($this->runCli(['campaign', 'patch', '1', '--set', 'x=1', '--apply', 'f.json', '--db', $this->dbPath]), 2, 'INVALID_ARG');
    }

    public function testKillDefaultsRemovesAuthorDefaults(): void
    {
        $def = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/code/db/default.json'), true);
        $def['domains'] = [];
        $this->db->seedCampaign(9, 'authored', $def);
        $res = $this->assertCleanJson($this->runCli(['campaign', 'kill-defaults', '9', '--yes', '--db', $this->dbPath]));
        $this->assertSame('kill-defaults', $res['action']);
        $this->assertCount(3, $res['removed']);
        $full = $this->runCli(['campaign', 'get', '9', '--full', '--db', $this->dbPath]);
        $this->assertStringNotContainsStringIgnoringCase('rolltrk', $full['stdout']);
        $this->assertStringNotContainsStringIgnoringCase('roerads', $full['stdout']);
    }

    public function testNetworksAddListDelete(): void
    {
        $add = $this->assertCleanJson($this->runCli(['networks', 'add', '--name', 'BuyGoods', '--params', '?subid={clickid}', '--yes', '--db', $this->dbPath]));
        $id = $add['network']['id'];
        $this->assertNotSame('', $id);
        $this->assertSame('subid={clickid}', $add['network']['params']);

        $list = $this->assertCleanJson($this->runCli(['networks', 'list', '--db', $this->dbPath]));
        $this->assertSame(1, $list['count']);

        $this->runCli(['networks', 'delete', $id, '--yes', '--db', $this->dbPath]);
        $this->assertSame(0, $this->assertCleanJson($this->runCli(['networks', 'list', '--db', $this->dbPath]))['count']);
    }

    public function testNetworksAddRequiresName(): void
    {
        $this->assertErrorContract($this->runCli(['networks', 'add', '--params', 'x=1', '--db', $this->dbPath]), 2, 'INVALID_ARG');
    }

    public function testDestinationAddComposesEffectiveUrl(): void
    {
        $nid = $this->assertCleanJson($this->runCli(['networks', 'add', '--name', 'N', '--params', 'subid={clickid}', '--yes', '--db', $this->dbPath]))['network']['id'];
        $this->runCli(['destinations', 'add', '--name', 'Checkout', '--base-url', 'checkout.example.com/a', '--network', $nid, '--yes', '--db', $this->dbPath]);
        $list = $this->assertCleanJson($this->runCli(['destinations', 'list', '--db', $this->dbPath]));
        $this->assertSame(1, $list['count']);
        $this->assertSame('https://checkout.example.com/a?subid={clickid}', $list['destinations'][0]['effective_url']);
    }

    public function testDestinationDeleteUnknownIsExit3(): void
    {
        $this->assertErrorContract($this->runCli(['destinations', 'delete', 'ghost', '--db', $this->dbPath]), 3, 'DESTINATION_NOT_FOUND');
    }

    public function testNetworkDeleteInUseIsExit2WithStableCode(): void
    {
        $this->db->set_common_settings([
            'networks' => [['id' => 'n1', 'name' => 'N', 'params' => '']],
            'destinations' => [['id' => 'd1', 'name' => 'D', 'base_url' => 'https://example.com', 'network_id' => 'n1']],
        ]);
        $this->db->seedCampaign(9, 'Routed', [
            'black' => [
                'flows' => [[
                    'name' => 'F1',
                    'steps' => [[
                        'checkout_routes' => [[
                            'network_id' => 'n1',
                            'links' => [['n' => 1, 'destination_id' => 'd1']],
                        ]],
                    ]],
                ]],
            ],
        ]);

        $run = $this->runCli(['networks', 'delete', 'n1', '--db', $this->dbPath]);
        $this->assertErrorContract($run, 2, 'RESOURCE_IN_USE');
        $this->assertStringContainsString('Routed: F1 — step 1', $run['stderr']);
    }

    public function testLandingUploadRequiresZip(): void
    {
        $this->assertErrorContract($this->runCli(['landing', 'upload', 'promo', '--db', $this->dbPath]), 2, 'INVALID_ARG');
    }

    public function testLandingUnknownVerbIsUsage(): void
    {
        $this->assertErrorContract($this->runCli(['landing', 'frobnicate', '--db', $this->dbPath]), 2, 'USAGE');
    }

    public function testLandingDeleteUnknownIsExit3(): void
    {
        $this->assertErrorContract($this->runCli(['landing', 'delete', 'zzz_nonexistent_test_landing', '--db', $this->dbPath]), 3, 'LANDING_NOT_FOUND');
    }

    public function testClicksFilterAndParamColumn(): void
    {
        $this->db->seedClicks([
            ['campaign_id' => 1, 'time' => self::SEED_TIME, 'country' => 'US', 'clickid' => 'k1', 'params' => '{"subid":"s1"}'],
            ['campaign_id' => 1, 'time' => self::SEED_TIME, 'country' => 'BR', 'clickid' => 'k2', 'params' => '{"subid":"s2"}'],
        ]);
        $res = $this->assertCleanJson($this->runCli([
            'clicks', '--campaign', '1', '--from', self::SEED_DATE, '--to', self::SEED_DATE,
            '--filter', 'country:=:US', '--param', 'subid', '--db', $this->dbPath,
        ]));
        $this->assertSame(1, $res['count']);
        $this->assertSame('s1', $res['clicks'][0]['param.subid']);
        $this->assertSame([['field' => 'country', 'operator' => '=', 'value' => 'US']], $res['filters']);
    }

    public function testClicksPageMustBeInteger(): void
    {
        $this->assertErrorContract($this->runCli(['clicks', '--campaign', '1', '--page', 'abc', '--db', $this->dbPath]), 2, 'INVALID_ARG');
    }
}
