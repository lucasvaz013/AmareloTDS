<?php

use PHPUnit\Framework\TestCase;

final class CapiPanelTest extends TestCase
{
    public function testPanelUsesRepeatablePixelCardsWithTwentyPixelLimit(): void
    {
        $form = file_get_contents(__DIR__ . '/../../code/admin/campsettings.php');
        $script = file_get_contents(__DIR__ . '/../../code/admin/js/campsettings/capi.js');

        self::assertStringContainsString('id="capi-pixels"', $form);
        self::assertStringContainsString('data-max-pixels="<?= CapiSettings::MAX_PIXELS ?>"', $form);
        self::assertStringContainsString('data-capi-pixel', $form);
        self::assertStringContainsString('<template id="capi-pixel-template">', $form);
        self::assertStringContainsString('id="add-capi-pixel"', $form);
        self::assertStringContainsString('data-capi-pixel-id', $form);
        self::assertStringContainsString('data-capi-access-token', $form);
        self::assertStringContainsString('data-capi-test-event-code', $form);
        self::assertStringContainsString('pixels:', $script);
        self::assertStringContainsString('container.dataset.maxPixels', $script);
        self::assertStringNotContainsString('graph_api_version', $form . $script);
        self::assertStringNotContainsString('api-version', $form . $script);
    }

    public function testCollectorMirrorsFirstPixelForLegacyRollback(): void
    {
        $script = file_get_contents(__DIR__ . '/../../code/admin/js/campsettings/capi.js');

        self::assertStringContainsString('pixel_id: primary.pixel_id', $script);
        self::assertStringContainsString('access_token: primary.access_token', $script);
        self::assertStringContainsString('test_event_code: primary.test_event_code', $script);
    }
}
