<?php

use PHPUnit\Framework\TestCase;

final class FiltrationCoreRegressionTest extends TestCase
{
    public function testUserAgentFilterReadsCollectedUserAgent(): void
    {
        $core = new FiltrationCore([
            'tds_ua' => 'Mozilla/5.0 Chrome/140.0.0.0 Safari/537.36',
        ]);

        $matches = $core->click_matches_filters([
            'condition' => 'AND',
            'rules' => [[
                'id' => 'useragent',
                'field' => 'useragent',
                'type' => 'string',
                'input' => 'text',
                'operator' => 'contains',
                'value' => 'Chrome',
            ]],
        ]);

        $this->assertTrue($matches);
        $this->assertSame('useragent', $core->block_reason);
    }
}
