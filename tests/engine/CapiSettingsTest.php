<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../code/campaign.php';

final class CapiSettingsTest extends TestCase
{
    public function testLegacyScalarSettingsProduceOnePixel(): void
    {
        $settings = CapiSettings::fromArray([
            'enabled' => true,
            'pixel_id' => '111',
            'access_token' => 'TOKEN_A',
            'test_event_code' => 'TEST_A',
            'map' => [['status' => 'Purchase', 'event_name' => 'Purchase']],
        ]);

        self::assertCount(1, $settings->pixels);
        self::assertSame('111', $settings->pixels[0]->pixelId);
        self::assertSame('TOKEN_A', $settings->pixels[0]->accessToken);
        self::assertSame('TEST_A', $settings->pixels[0]->testEventCode);
        self::assertTrue($settings->isUsable());
    }

    public function testMultiplePixelsPreserveTheirOwnCredentials(): void
    {
        $settings = CapiSettings::fromArray([
            'enabled' => true,
            'pixels' => [
                ['pixel_id' => '111', 'access_token' => 'TOKEN_A', 'test_event_code' => ''],
                ['pixel_id' => '222', 'access_token' => 'TOKEN_B', 'test_event_code' => 'TEST_B'],
            ],
            'map' => [['status' => 'Lead', 'event_name' => 'InitiateCheckout']],
        ]);

        self::assertCount(2, $settings->pixels);
        self::assertSame(['111', '222'], array_map(
            static fn(CapiPixelSettings $pixel): string => $pixel->pixelId,
            $settings->pixels
        ));
        self::assertSame('TOKEN_B', $settings->pixels[1]->accessToken);
        self::assertSame('TEST_B', $settings->pixels[1]->testEventCode);
    }

    public function testSerializationMirrorsFirstPixelForLegacyRollback(): void
    {
        $settings = CapiSettings::fromArray([
            'enabled' => true,
            'pixels' => [
                ['pixel_id' => '111', 'access_token' => 'TOKEN_A', 'test_event_code' => 'TEST_A'],
                ['pixel_id' => '222', 'access_token' => 'TOKEN_B', 'test_event_code' => 'TEST_B'],
            ],
            'map' => [['status' => 'Purchase', 'event_name' => 'Purchase']],
        ]);

        $serialized = json_decode(json_encode($settings, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('111', $serialized['pixel_id']);
        self::assertSame('TOKEN_A', $serialized['access_token']);
        self::assertSame('TEST_A', $serialized['test_event_code']);
        self::assertCount(2, $serialized['pixels']);
        self::assertSame('222', $serialized['pixels'][1]['pixel_id']);
    }

    public function testRuntimeNeverLoadsMoreThanTwentyPixels(): void
    {
        $pixels = [];
        for ($index = 1; $index <= 21; $index++) {
            $pixels[] = ['pixel_id' => (string)$index, 'access_token' => 'TOKEN_' . $index];
        }

        $settings = CapiSettings::fromArray(['enabled' => true, 'pixels' => $pixels]);

        self::assertCount(CapiSettings::MAX_PIXELS, $settings->pixels);
        self::assertSame('20', $settings->pixels[19]->pixelId);
    }
}
