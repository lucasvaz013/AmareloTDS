<?php

use PHPUnit\Framework\TestCase;

final class PostbackGatewayUiTest extends TestCase
{
    public function testDomainsPageExplainsAndCollectsAPostbackGateway(): void
    {
        $page = (string)file_get_contents(__DIR__ . '/../../code/admin/domains.php');

        self::assertStringContainsString('Postback Gateway', $page);
        self::assertStringContainsString('id="postbackGatewayDomain"', $page);
        self::assertStringContainsString('only <code>/api/postback.php</code>', $page);
        self::assertStringContainsString('replaces the apex A record', $page);
    }

    public function testEndpointRequiresExplicitConfirmationForGatewayDnsMutation(): void
    {
        $endpoint = (string)file_get_contents(__DIR__ . '/../../code/admin/domainseditor.php');

        self::assertStringContainsString("case 'gateway-sync':", $endpoint);
        self::assertStringContainsString('Gateway DNS replacement must be confirmed.', $endpoint);
        self::assertStringContainsString("case 'gateway-remove':", $endpoint);
        self::assertStringContainsString("'postback_gateways'", $endpoint);
        self::assertStringContainsString('That domain is not a registered postback gateway.', $endpoint);
        self::assertStringContainsString('PostbackGatewayProvisioner::retryState', $endpoint);
    }

    public function testBrowserSendsConfirmationAndRendersGatewayState(): void
    {
        $script = (string)file_get_contents(__DIR__ . '/../../code/admin/js/domains.js');

        self::assertStringContainsString("action: 'gateway-sync'", $script);
        self::assertStringContainsString('confirm: true', $script);
        self::assertStringContainsString('state.postback_gateways', $script);
        self::assertStringContainsString("action: 'gateway-remove'", $script);
    }
}
