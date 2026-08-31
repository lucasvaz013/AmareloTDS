<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../code/capi.php';

final class ProcessCapiConversionTest extends TestCase
{
    public function testTwoPixelsAreLoggedIndependentlyWhenOneFails(): void
    {
        $settings = json_decode(
            file_get_contents(__DIR__ . '/../../code/db/default.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $settings['domains'] = ['tds.example'];
        $settings['capi'] = [
            'enabled' => true,
            'pixels' => [
                ['pixel_id' => '111', 'access_token' => 'TOKEN_A', 'test_event_code' => ''],
                ['pixel_id' => '222', 'access_token' => 'TOKEN_B', 'test_event_code' => ''],
            ],
            'map' => [['status' => 'Lead', 'event_name' => 'InitiateCheckout']],
        ];
        $campaign = new Campaign(7, $settings);
        $logs = [];
        $sendCalls = 0;

        process_capi_conversion(
            $campaign,
            'Lead',
            [
                'clickid' => 'CLICK-1',
                'userid' => 'USER-1',
                'time' => 1785966596,
                'ip' => '203.0.113.9',
                'ua' => 'Test UA',
                'params' => ['fbclid' => 'FBCLID-1'],
            ],
            null,
            'USD',
            null,
            static function (CapiSettings $capi, array $events) use (&$sendCalls): array {
                $sendCalls++;
                return [
                    [
                        'pixel' => $capi->pixels[0],
                        'response' => new HttpResponse('capi-conversion-0', '{"events_received":1}', [], ['http_code' => 200]),
                    ],
                    [
                        'pixel' => $capi->pixels[1],
                        'response' => new HttpResponse('capi-conversion-1', 'unavailable', [], ['http_code' => 503]),
                    ],
                ];
            },
            static function (string $direction, string $outcome, string $message, array $context) use (&$logs): bool {
                $logs[] = compact('direction', 'outcome', 'message', 'context');
                return true;
            }
        );

        self::assertSame(1, $sendCalls, 'the event batch must fan out in one parallel sender call');
        self::assertCount(2, $logs);
        self::assertSame(['delivered', 'failed'], array_column($logs, 'outcome'));
        self::assertSame(['111', '222'], array_column(array_column($logs, 'context'), 'pixel_id'));
        self::assertSame($logs[0]['context']['event_id'], $logs[1]['context']['event_id']);
        self::assertStringNotContainsString('TOKEN_', json_encode($logs, JSON_THROW_ON_ERROR));
    }

    public function testPurchaseWithoutPayoutSkipsAllPixelsBeforeTransport(): void
    {
        $settings = json_decode(
            file_get_contents(__DIR__ . '/../../code/db/default.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $settings['capi'] = [
            'enabled' => true,
            'pixels' => [
                ['pixel_id' => '111', 'access_token' => 'TOKEN_A'],
                ['pixel_id' => '222', 'access_token' => 'TOKEN_B'],
            ],
            'map' => [['status' => 'Purchase', 'event_name' => 'Purchase']],
        ];
        $campaign = new Campaign(7, $settings);
        $sendCalls = 0;
        $logs = [];

        process_capi_conversion(
            $campaign,
            'Purchase',
            ['clickid' => 'CLICK-1', 'time' => 1785966596],
            null,
            'USD',
            null,
            static function () use (&$sendCalls): array {
                $sendCalls++;
                return [];
            },
            static function (string $direction, string $outcome, string $message, array $context) use (&$logs): bool {
                $logs[] = compact('direction', 'outcome', 'message', 'context');
                return true;
            }
        );

        self::assertSame(0, $sendCalls);
        self::assertCount(2, $logs);
        self::assertSame(['111', '222'], array_column(array_column($logs, 'context'), 'pixel_id'));
        self::assertSame(['failed', 'failed'], array_column($logs, 'outcome'));
    }
}
