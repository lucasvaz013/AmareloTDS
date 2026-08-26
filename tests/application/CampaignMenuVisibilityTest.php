<?php

use PHPUnit\Framework\TestCase;

final class CampaignMenuVisibilityTest extends TestCase
{
    public function testCampaignActionMenuIsOnlyInjectedOnTheDashboard(): void
    {
        $script = file_get_contents(__DIR__ . '/../../code/admin/js/campeditor.js');

        $this->assertStringContainsString("document.getElementById('campaigns')", $script);
        $this->assertStringContainsString('#renameCampaign', $script);
        $this->assertLessThan(
            strpos($script, "document.getElementById('campaigns')"),
            strpos($script, '#renameCampaign')
        );
        $this->assertStringContainsString('btn-settings', $script);
        $this->assertStringContainsString('btn-clone', $script);
        $this->assertStringContainsString('btn-allowed', $script);
        $this->assertStringContainsString('btn-blocked', $script);
        $this->assertStringContainsString('btn-leads', $script);
        $this->assertStringContainsString('btn-delete', $script);
    }
}
