<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../code/checkoutroutes.php';
require_once __DIR__ . '/../../code/experiments.php';

final class CheckoutRouteExperimentTest extends TestCase
{
    protected function setUp(): void
    {
        $_COOKIE = [];
        unset($GLOBALS['ytds_saved_paths_state']);
    }

    public function testCheckoutSelectionLivesBesidePathWithoutRouteIdOrUrl(): void
    {
        $selection = [
            'network_id' => 'cp',
            'links' => [['n' => 1, 'destination_id' => 'cp-1']],
        ];
        $step = StepSettings::fromArray([
            'action' => 'folder',
            'folders' => [['name' => 'landing']],
            'checkout_routes' => [[
                'network_id' => 'cp',
                'weight' => 100,
                'links' => [['n' => 1, 'destination_id' => 'cp-1']],
            ]],
        ]);
        $state = ['1' => ['Flow' => ['path' => ['landing'], 'checkout' => $selection]]];

        self::assertSame($selection, experiment_get_checkout_selection($state, 1, 'Flow', $step));
        self::assertArrayNotHasKey('route_id', $state['1']['Flow']['checkout']);
        self::assertStringNotContainsString('url', (string)json_encode($state));
    }

    public function testInvalidSavedSelectionIsDiscarded(): void
    {
        $step = StepSettings::fromArray([
            'action' => 'folder',
            'folders' => [['name' => 'landing']],
            'checkout_routes' => [[
                'network_id' => 'cp',
                'weight' => 100,
                'links' => [['n' => 1, 'destination_id' => 'cp-1']],
            ]],
        ]);
        $state = ['1' => ['Flow' => ['checkout' => [
            'network_id' => 'deleted',
            'links' => [['n' => 1, 'destination_id' => 'gone']],
        ]]]];

        self::assertNull(experiment_get_checkout_selection($state, 1, 'Flow', $step));
    }
}
