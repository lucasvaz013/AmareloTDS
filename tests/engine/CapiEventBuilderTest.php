<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../code/capi.php';

final class CapiEventBuilderTest extends TestCase
{
    private const CLICK_TIME = 1785966596;
    private const FBCLID = 'IwAR0PROVA456';

    public function testFbcCarriesMillisecondsNotSeconds(): void
    {
        $fbc = CapiEventBuilder::buildFbc(self::FBCLID, self::CLICK_TIME);

        self::assertSame('fb.1.1785966596000.' . self::FBCLID, $fbc);

        // The whole point: an fbc built from seconds is still well-formed, so Meta
        // accepts it and silently fails to attribute. Guard the exact digit count.
        $timestamp = explode('.', (string)$fbc)[2];
        self::assertSame(13, strlen($timestamp), 'creationTime must be in milliseconds');
        self::assertSame(self::CLICK_TIME * 1000, (int)$timestamp);
    }

    public function testFbclidIsUsedVerbatim(): void
    {
        $mixedCase = 'IwAR2F4-dbP0l7Mn1IawQQ_GCINEz7';
        $fbc = (string)CapiEventBuilder::buildFbc($mixedCase, self::CLICK_TIME);

        self::assertStringEndsWith('.' . $mixedCase, $fbc);
    }

    public function testFbcIsNullWithoutUsableInput(): void
    {
        self::assertNull(CapiEventBuilder::buildFbc('', self::CLICK_TIME));
        self::assertNull(CapiEventBuilder::buildFbc('   ', self::CLICK_TIME));
        self::assertNull(CapiEventBuilder::buildFbc(null, self::CLICK_TIME));
        self::assertNull(CapiEventBuilder::buildFbc(self::FBCLID, 0));
    }

    public function testEventCarriesEveryFieldMetaRequires(): void
    {
        $event = CapiEventBuilder::build(
            $this->click(),
            'Purchase',
            1785970000,
            'https://tds.example/',
            197.0,
            'BRL',
            'ORDER-1'
        );

        self::assertSame('Purchase', $event['event_name']);
        self::assertSame(1785970000, $event['event_time']);
        self::assertSame('https://tds.example/', $event['event_source_url']);
        self::assertSame('website', $event['action_source']);
        self::assertNotSame('', $event['event_id']);
        self::assertArrayHasKey('client_user_agent', $event['user_data']);
    }

    public function testUserDataMatchesWhatAnAffiliateCanProvide(): void
    {
        $event = CapiEventBuilder::build($this->click(), 'Purchase', 1785970000, 'https://tds.example/', 197.0, 'BRL');
        $userData = $event['user_data'];

        self::assertSame('203.0.113.9', $userData['client_ip_address']);
        self::assertSame('Mozilla/5.0 (iPhone)', $userData['client_user_agent']);
        self::assertSame('fb.1.1785966596000.' . self::FBCLID, $userData['fbc']);
        self::assertSame(hash('sha256', 'user-42'), $userData['external_id']);

        // No pixel runs on the TDS, so there is never an fbp to send.
        self::assertArrayNotHasKey('fbp', $userData);
        // We have no PII; these must never be invented.
        self::assertArrayNotHasKey('em', $userData);
        self::assertArrayNotHasKey('ph', $userData);
    }

    public function testExternalIdFallsBackToClickidWhenNoUserCookie(): void
    {
        $click = $this->click();
        unset($click['userid']);

        $event = CapiEventBuilder::build($click, 'Lead', 1785970000, 'https://tds.example/');

        self::assertSame(hash('sha256', 'CLICK-ABC'), $event['user_data']['external_id']);
    }

    public function testEventWithoutFbclidOmitsFbcInsteadOfSendingGarbage(): void
    {
        $click = $this->click();
        $click['params'] = ['sub1' => 'organic'];

        $event = CapiEventBuilder::build($click, 'Lead', 1785970000, 'https://tds.example/');

        self::assertArrayNotHasKey('fbc', $event['user_data']);
        self::assertArrayHasKey('client_ip_address', $event['user_data']);
    }

    public function testParamsAreReadWhetherStoredAsJsonOrArray(): void
    {
        $asArray = $this->click();
        $asJson = $this->click();
        $asJson['params'] = json_encode($asArray['params']);

        self::assertSame(
            CapiEventBuilder::build($asArray, 'Lead', 1785970000, 'https://tds.example/')['user_data']['fbc'],
            CapiEventBuilder::build($asJson, 'Lead', 1785970000, 'https://tds.example/')['user_data']['fbc']
        );
    }

    public function testEventIdIsStableSoResentPostbacksDeduplicate(): void
    {
        $first = CapiEventBuilder::eventId('CLICK-ABC', 'Purchase', 'ORDER-1');
        $second = CapiEventBuilder::eventId('CLICK-ABC', 'Purchase', 'ORDER-1');

        self::assertSame($first, $second);
    }

    public function testEventIdSeparatesDistinctEvents(): void
    {
        $purchase = CapiEventBuilder::eventId('CLICK-ABC', 'Purchase', 'ORDER-1');

        self::assertNotSame($purchase, CapiEventBuilder::eventId('CLICK-ABC', 'Purchase', 'ORDER-2'));
        self::assertNotSame($purchase, CapiEventBuilder::eventId('CLICK-ABC', 'InitiateCheckout', 'ORDER-1'));
        self::assertNotSame($purchase, CapiEventBuilder::eventId('CLICK-XYZ', 'Purchase', 'ORDER-1'));
    }

    public function testValueTravelsInTheCurrencyItWasReportedIn(): void
    {
        $event = CapiEventBuilder::build($this->click(), 'Purchase', 1785970000, 'https://tds.example/', 197.0, 'brl');

        self::assertSame('197.00', $event['custom_data']['value']);
        self::assertSame('BRL', $event['custom_data']['currency']);
    }

    public function testCustomDataIsOmittedWhenNoValueWasReported(): void
    {
        $event = CapiEventBuilder::build($this->click(), 'Lead', 1785970000, 'https://tds.example/');

        self::assertArrayNotHasKey('custom_data', $event);
    }

    public function testTransactionIdBecomesOrderId(): void
    {
        $event = CapiEventBuilder::build(
            $this->click(),
            'Purchase',
            1785970000,
            'https://tds.example/',
            10.0,
            'USD',
            'TX-77'
        );

        self::assertSame('TX-77', $event['custom_data']['order_id']);
    }

    public function testEndpointPinsTheGraphApiVersion(): void
    {
        self::assertSame(
            'https://graph.facebook.com/' . CapiEventBuilder::GRAPH_API_VERSION . '/1234567890/events',
            CapiSender::endpoint('1234567890')
        );
    }

    /** @return array<string, mixed> */
    private function click(): array
    {
        return [
            'clickid' => 'CLICK-ABC',
            'userid' => 'user-42',
            'time' => self::CLICK_TIME,
            'ip' => '203.0.113.9',
            'ua' => 'Mozilla/5.0 (iPhone)',
            'params' => ['fbclid' => self::FBCLID, 'sub1' => 'camp99'],
        ];
    }
}
