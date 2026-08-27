<?php

use PHPUnit\Framework\TestCase;

final class ParametersModalTest extends TestCase
{
    public function testHeaderExposesParametersBesideSettings(): void
    {
        $header = file_get_contents(__DIR__ . '/../../code/admin/header.php');
        $parametersPosition = strpos($header, 'id="openParameters"');
        $settingsPosition = strpos($header, 'id="openSettings"');

        self::assertNotFalse($parametersPosition);
        self::assertNotFalse($settingsPosition);
        self::assertLessThan($settingsPosition, $parametersPosition);
        self::assertStringContainsString("include __DIR__ . '/parametersmodal.php'", $header);
    }

    public function testModalDocumentsFacebookInboundAndAmareloMacros(): void
    {
        $modal = file_get_contents(__DIR__ . '/../../code/admin/parametersmodal.php');

        foreach ([
            'campaignname={{campaign.name}}',
            '{c.campaignname}',
            'campaignid={{campaign.id}}',
            '{c.campaignid}',
            'adsetname={{adset.name}}',
            '{c.adsetname}',
            'adname={{ad.name}}',
            '{c.adname}',
            'placement={{placement}}',
            '{c.placement}',
            '{clickid}',
            '{userid}',
            '{domain}',
            '{country}',
            '{c.NAME}',
            '{px}',
        ] as $parameter) {
            self::assertStringContainsString(htmlspecialchars($parameter, ENT_QUOTES), $modal);
        }

        self::assertStringContainsString('Meta expands the double-brace value', $modal);
        self::assertStringContainsString('whole query-parameter value', $modal);
    }

    public function testParametersOverlayHasDedicatedOpenAndCloseBindings(): void
    {
        $script = file_get_contents(__DIR__ . '/../../code/admin/js/parameters.js');
        $scripts = file_get_contents(__DIR__ . '/../../code/admin/scripts.php');

        self::assertStringContainsString("node('#openParameters')", $script);
        self::assertStringContainsString("$('#parametersModal').modal", $script);
        self::assertStringContainsString("node('#closeParameters')", $script);
        self::assertStringContainsString('/parameters.js?v=', $scripts);
    }
}