<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../code/campaign.php';
require_once __DIR__ . '/../../code/admin/clmns.php';

final class StandardEventColumnsTest extends TestCase
{
    public function testEnabledStandardEventsAppearOnceAlongsideCustomEvents(): void
    {
        $settings = json_decode(
            (string)file_get_contents(__DIR__ . '/../../code/templates/blank.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $settings['events']['offer_revealed']['use'] = true;
        $settings['events']['checkout_click']['use'] = true;
        $settings['events']['custom'] = ['offer_revealed', 'cta_click'];
        $campaign = new Campaign(1, $settings);

        $fields = array_column(AvailableColumns::get_stats_columns_for_campaign($campaign), 'field');

        self::assertSame(1, count(array_keys($fields, 'event.offer_revealed.count', true)));
        self::assertContains('event.checkout_click.count', $fields);
        self::assertContains('event.cta_click.count', $fields);
    }
}
