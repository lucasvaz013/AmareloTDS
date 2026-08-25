<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../code/postbackgateway.php';

final class PostbackGatewayTest extends TestCase
{
    private const VPS_IP = '203.0.113.10';

    public function testRegistryStoresTheApexWithoutTheCampaignPrefix(): void
    {
        $list = PostbackGatewayRegistry::put([], 'example.com', 'cloudflare', 'zone-1', 1786000000);

        self::assertCount(1, $list);
        self::assertSame('example.com', $list[0]['name']);
        self::assertSame('https://example.com/api/postback.php', $list[0]['url']);
        self::assertSame(DomainStatus::CHECKING, $list[0]['status']);
    }

    public function testRegistryUpdatesInPlaceAndKeepsOriginalAddedDate(): void
    {
        $list = PostbackGatewayRegistry::put([], 'example.com', 'cloudflare', 'zone-1', 1786000000);
        $list = PostbackGatewayRegistry::put($list, 'EXAMPLE.com', 'manual', '', 1786000100, DomainStatus::ERROR, 'DNS conflict');

        self::assertCount(1, $list);
        self::assertSame(1786000000, $list[0]['added']);
        self::assertSame('zone-1', $list[0]['zone_id']);
        self::assertSame(DomainStatus::ERROR, $list[0]['status']);
    }

    public function testRegistryReadsVersionedSettingsAndRemovesCaseInsensitively(): void
    {
        $list = PostbackGatewayRegistry::put([], 'example.com', 'manual', '', 1786000000);
        $settings = ['postbackGateway' => ['version' => 1, 'domains' => $list]];

        self::assertSame($list, PostbackGatewayRegistry::all($settings));
        self::assertSame([], PostbackGatewayRegistry::remove($list, 'EXAMPLE.COM'));
    }

    public function testDnsJudgeRequiresOnlyTheExpectedIpv4AndNoIpv6(): void
    {
        self::assertTrue(PostbackGatewayDns::judge([self::VPS_IP], [], self::VPS_IP, 'example.com')->ok);

        $extraA = PostbackGatewayDns::judge([self::VPS_IP, '203.0.113.11'], [], self::VPS_IP, 'example.com');
        self::assertFalse($extraA->ok);
        self::assertStringContainsString('extra A', $extraA->message);

        $aaaa = PostbackGatewayDns::judge([self::VPS_IP], ['2001:db8::1'], self::VPS_IP, 'example.com');
        self::assertFalse($aaaa->ok);
        self::assertStringContainsString('AAAA', $aaaa->message);
    }

    public function testDnsPlanKeepsOneCorrectAAndDeletesEveryConflict(): void
    {
        $plan = PostbackGatewayDns::plan([
            ['id' => 'correct', 'type' => 'A', 'name' => 'example.com', 'content' => self::VPS_IP, 'proxied' => false],
            ['id' => 'old-a', 'type' => 'A', 'name' => 'example.com', 'content' => '203.0.113.11', 'proxied' => true],
            ['id' => 'old-v6', 'type' => 'AAAA', 'name' => 'example.com', 'content' => '2001:db8::1', 'proxied' => true],
            ['id' => 'mail', 'type' => 'MX', 'name' => 'example.com', 'content' => 'mail.example.com'],
        ], 'example.com', self::VPS_IP);

        self::assertSame('correct', $plan['keep_id']);
        self::assertFalse($plan['create']);
        self::assertSame(['old-a', 'old-v6'], $plan['delete_ids']);
    }

    public function testDnsPlanReplacesAProxiedRecordAndPreservesNonAddressRecords(): void
    {
        $plan = PostbackGatewayDns::plan([
            ['id' => 'proxied', 'type' => 'A', 'name' => 'example.com', 'content' => self::VPS_IP, 'proxied' => true],
            ['id' => 'txt', 'type' => 'TXT', 'name' => 'example.com', 'content' => 'verification'],
        ], 'example.com', self::VPS_IP);

        self::assertSame('proxied', $plan['update_id']);
        self::assertSame([], $plan['delete_ids']);
        self::assertSame([
            'type' => 'A', 'name' => 'example.com', 'content' => self::VPS_IP, 'ttl' => 1, 'proxied' => false,
        ], $plan['body']);
    }

    public function testProvisioningStateUsesItsOwnFile(): void
    {
        $root = sys_get_temp_dir() . '/ytds_gateway_' . bin2hex(random_bytes(4));
        mkdir($root . '/tmp', 0777, true);

        PostbackGatewayProvisioner::write($root, ['example.com' => ['ok' => true, 'checked' => 1786000000]]);
        $state = PostbackGatewayProvisioner::read($root);

        self::assertTrue($state['example.com']['ok']);
        self::assertStringEndsWith('/tmp/postback-gateway-nginx.json', PostbackGatewayProvisioner::statePath($root));

        @unlink(PostbackGatewayProvisioner::statePath($root));
        @rmdir($root . '/tmp');
        @rmdir($root);
    }

    public function testOnlyMarkedNginxConfigsAreManagedByTheGateway(): void
    {
        self::assertTrue(PostbackGatewayProvisioner::isManagedConfig(
            "# amarelotds-postback-gateway v1\nserver { return 404; }\n"
        ));
        self::assertFalse(PostbackGatewayProvisioner::isManagedConfig(
            "server { server_name example.com; }\n"
        ));
        self::assertFalse(PostbackGatewayProvisioner::isManagedConfig(
            "server {\n    # amarelotds-postback-gateway v1\n    return 404;\n}\n"
        ));
    }

    public function testCloudflareSyncRejectsAnInvalidOriginIpBeforeCallingTheApi(): void
    {
        $outcome = postback_gateway_sync_cloudflare([], 'example.com', '');

        self::assertSame(DomainStatus::ERROR, $outcome->status);
        self::assertStringContainsString('valid public IPv4', $outcome->message);
    }

    public function testRefreshSavePlanIgnoresReadyGatewaysAndKeepsPendingOnes(): void
    {
        $list = PostbackGatewayRegistry::put([], 'ready.example', 'cloudflare', 'z1', 1, DomainStatus::READY, 'ok');
        $list = PostbackGatewayRegistry::put($list, 'pending.example', 'cloudflare', 'z2', 2, DomainStatus::CHECKING, 'waiting');

        $plan = PostbackGatewayRegistry::refreshSavePlan($list);

        self::assertFalse($plan['save']);
        self::assertSame(['pending.example'], $plan['pending']);
    }

    public function testRefreshSavePlanSavesOnlyWhenAPendingStatusChanges(): void
    {
        $list = PostbackGatewayRegistry::put([], 'pending.example', 'cloudflare', 'z2', 2, DomainStatus::CHECKING, 'waiting');
        $plan = PostbackGatewayRegistry::refreshSavePlan($list, [
            'pending.example' => ['status' => DomainStatus::READY, 'detail' => 'published', 'zone_id' => 'z2'],
        ]);

        self::assertTrue($plan['save']);
        self::assertSame(DomainStatus::READY, $plan['domains'][0]['status']);
    }

    public function testResumeRequiresAnExistingRegistryEntry(): void
    {
        $list = PostbackGatewayRegistry::put([], 'example.com', 'cloudflare', 'zone-1', 1);

        self::assertNotNull(PostbackGatewayRegistry::find($list, 'example.com'));
        self::assertNull(PostbackGatewayRegistry::find($list, 'other.example'));
    }

    public function testProvisioningRetryClearsExhaustedAttempts(): void
    {
        $cleared = PostbackGatewayProvisioner::retryState([
            'ok' => false,
            'attempts' => PostbackGatewayProvisioner::MAX_ATTEMPTS,
            'message' => 'certbot failed.',
        ]);

        self::assertSame(0, $cleared['attempts']);
        self::assertFalse($cleared['ok']);
    }
}
