<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../code/integrations.php';

final class IntegrationsTest extends TestCase
{
    // ── Cloudflare ────────────────────────────────────────────────────────────────

    public function testCloudflareAcceptsAnActiveToken(): void
    {
        $status = CloudflareIntegration::interpret(200, json_encode([
            'success' => true,
            'errors' => [],
            'messages' => [['code' => 10000, 'message' => 'This API Token is valid and active']],
            'result' => ['id' => 'abc', 'status' => 'active'],
        ]));

        self::assertTrue($status->ok);
        self::assertTrue($status->configured);
        self::assertSame('active', $status->code);
    }

    /**
     * success:true alone is not enough — Meta of the Cloudflare world, this endpoint
     * answers 200 for tokens that exist but cannot be used.
     */
    #[DataProvider('inactiveTokenProvider')]
    public function testCloudflareRejectsTokensThatAreNotActive(string $tokenStatus, string $expectedCode): void
    {
        $status = CloudflareIntegration::interpret(200, json_encode([
            'success' => true,
            'errors' => [],
            'result' => ['id' => 'abc', 'status' => $tokenStatus],
        ]));

        self::assertFalse($status->ok);
        self::assertTrue($status->configured);
        self::assertSame($expectedCode, $status->code);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function inactiveTokenProvider(): array
    {
        return [
            'disabled' => ['disabled', 'cf_status_disabled'],
            'expired' => ['expired', 'cf_status_expired'],
        ];
    }

    public function testCloudflareMapsErrorCodesToActionableText(): void
    {
        $status = CloudflareIntegration::interpret(401, json_encode([
            'success' => false,
            'errors' => [['code' => 1000, 'message' => 'Invalid API Token']],
            'result' => null,
        ]));

        self::assertFalse($status->ok);
        self::assertSame('cf_1000', $status->code);
        self::assertStringContainsString('revoked', $status->message);
    }

    public function testCloudflareSurvivesGarbageResponses(): void
    {
        $status = CloudflareIntegration::interpret(502, '<html>bad gateway</html>');

        self::assertFalse($status->ok);
        self::assertSame('bad_response', $status->code);
    }

    public function testCloudflareReportsTransportFailureSeparately(): void
    {
        $status = CloudflareIntegration::interpret(0, '', 'Could not resolve host');

        self::assertFalse($status->ok);
        self::assertSame('transport_error', $status->code);
    }

    public function testCloudflareWithoutTokenIsReportedAsUnconfigured(): void
    {
        $status = CloudflareIntegration::verify(['cloudflareApiToken' => '   ']);

        self::assertFalse($status->configured);
        self::assertFalse($status->ok);
        self::assertSame('not_configured', $status->code);
    }

    // ── Namecheap ─────────────────────────────────────────────────────────────────

    public function testNamecheapAcceptsAnOkResponse(): void
    {
        $status = NamecheapIntegration::interpret($this->namecheapXml('OK', ''), '', '203.0.113.9');

        self::assertTrue($status->ok);
        self::assertSame('ok', $status->code);
        self::assertSame('203.0.113.9', $status->details['client_ip']);
    }

    /**
     * The whole reason the verdict cannot come from the HTTP status: Namecheap answers
     * 200 even when it rejects the credentials.
     */
    public function testNamecheapRejectionArrivesWithoutAnHttpError(): void
    {
        $status = NamecheapIntegration::interpret(
            $this->namecheapXml('ERROR', '<Errors><Error Number="1011102">API Key is invalid or API access has not been enabled</Error></Errors>')
        );

        self::assertFalse($status->ok);
        self::assertSame('nc_1011102', $status->code);
        self::assertStringContainsString('switched off', $status->message);
    }

    public function testNamecheapWhitelistErrorNamesTheAddressToAuthorise(): void
    {
        $status = NamecheapIntegration::interpret(
            $this->namecheapXml('ERROR', '<Errors><Error Number="1011150">Invalid request IP: 2a01:4ff:f0:6987::1</Error></Errors>')
        );

        self::assertFalse($status->ok);
        self::assertSame('nc_1011150', $status->code);
        self::assertStringContainsString('whitelist', $status->message);
        self::assertStringContainsString('2a01:4ff:f0:6987::1', $status->message);
    }

    /** A momentarily unavailable balance is a working credential, not an auth failure. */
    public function testNamecheapTreatsUnavailableBalanceAsSuccess(): void
    {
        $status = NamecheapIntegration::interpret(
            $this->namecheapXml('ERROR', '<Errors><Error Number="4022312">Balance information is not available</Error></Errors>')
        );

        self::assertTrue($status->ok);
        self::assertSame('ok_no_balance', $status->code);
    }

    /** The live response declares a default namespace the documented examples omit. */
    public function testNamecheapReadsErrorsWithAndWithoutTheXmlNamespace(): void
    {
        $withNamespace = NamecheapIntegration::interpret(
            $this->namecheapXml('ERROR', '<Errors><Error Number="1011102">nope</Error></Errors>')
        );
        $withoutNamespace = NamecheapIntegration::interpret(
            '<?xml version="1.0" encoding="utf-8"?><ApiResponse Status="ERROR">'
            . '<Errors><Error Number="1011102">nope</Error></Errors></ApiResponse>'
        );

        self::assertSame($withNamespace->code, $withoutNamespace->code);
        self::assertSame('nc_1011102', $withNamespace->code);
    }

    public function testNamecheapSurvivesEmptyAndBrokenResponses(): void
    {
        self::assertSame('bad_response', NamecheapIntegration::interpret('')->code);
        self::assertSame('bad_response', NamecheapIntegration::interpret('not xml at all <<<')->code);
    }

    public function testNamecheapWithoutCredentialsIsReportedAsUnconfigured(): void
    {
        $status = NamecheapIntegration::verify(['namecheapApiUser' => '', 'namecheapApiKey' => ''], '203.0.113.9');

        self::assertFalse($status->configured);
        self::assertSame('not_configured', $status->code);
    }

    public function testNamecheapRefusesToCallWithoutAPublicIpv4(): void
    {
        $status = NamecheapIntegration::verify(
            ['namecheapApiUser' => 'user', 'namecheapApiKey' => 'key'],
            ''
        );

        self::assertTrue($status->configured);
        self::assertFalse($status->ok);
        self::assertSame('no_ipv4', $status->code);
    }

    public function testNamecheapUsesTheSandboxEndpointWhenAsked(): void
    {
        self::assertStringContainsString('sandbox', NamecheapIntegration::SANDBOX_URL);
        self::assertStringNotContainsString('sandbox', NamecheapIntegration::PRODUCTION_URL);
    }

    /**
     * The page renders straight from this shape on load, so a missing service or a
     * missing field shows as a blank card rather than an error.
     */
    public function testEveryServiceReportsTheShapeThePageRendersFrom(): void
    {
        foreach ([
            CloudflareIntegration::verify([]),
            NamecheapIntegration::verify([], '203.0.113.9'),
        ] as $status) {
            $encoded = $status->jsonSerialize();

            self::assertArrayHasKey('service', $encoded);
            self::assertArrayHasKey('configured', $encoded);
            self::assertArrayHasKey('ok', $encoded);
            self::assertArrayHasKey('message', $encoded);
            self::assertArrayHasKey('code', $encoded);
            self::assertIsBool($encoded['configured']);
            self::assertIsBool($encoded['ok']);
            self::assertNotSame('', $encoded['message']);
        }
    }

    private function namecheapXml(string $status, string $inner): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<ApiResponse Status="' . $status . '" xmlns="' . NamecheapIntegration::XML_NAMESPACE . '">'
            . $inner
            . '</ApiResponse>';
    }
}
