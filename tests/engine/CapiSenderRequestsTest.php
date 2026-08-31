<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../code/capi.php';

final class CapiSenderRequestsTest extends TestCase
{
    public function testBuildRequestsCreatesOneRequestPerPixelWithSharedEvent(): void
    {
        $settings = CapiSettings::fromArray([
            'enabled' => true,
            'pixels' => [
                ['pixel_id' => '111', 'access_token' => 'TOKEN_A', 'test_event_code' => ''],
                ['pixel_id' => '222', 'access_token' => 'TOKEN_B', 'test_event_code' => 'TEST_B'],
            ],
            'map' => [['status' => 'Purchase', 'event_name' => 'Purchase']],
        ]);
        $event = CapiEventBuilder::build(
            ['clickid' => 'CLICK-1', 'userid' => 'USER-1', 'time' => 1785966596],
            'Purchase',
            1785970000,
            'https://tds.example/',
            25.0,
            'USD',
            'ORDER-1'
        );

        $requests = CapiSender::buildRequests($settings, [$event]);

        self::assertCount(2, $requests);
        self::assertSame('https://graph.facebook.com/v25.0/111/events', $requests[0]->url);
        self::assertSame('https://graph.facebook.com/v25.0/222/events', $requests[1]->url);
        self::assertContains('Authorization: Bearer TOKEN_A', $requests[0]->headers);
        self::assertContains('Authorization: Bearer TOKEN_B', $requests[1]->headers);

        $bodyA = json_decode((string)$requests[0]->body, true, flags: JSON_THROW_ON_ERROR);
        $bodyB = json_decode((string)$requests[1]->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($bodyA['data'], $bodyB['data']);
        self::assertSame($bodyA['data'][0]['event_id'], $bodyB['data'][0]['event_id']);
        self::assertArrayNotHasKey('test_event_code', $bodyA);
        self::assertSame('TEST_B', $bodyB['test_event_code']);
    }
}
