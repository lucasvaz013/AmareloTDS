<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../code/checkoutroutes.php';

final class CheckoutRoutesTest extends TestCase
{
    public function testResolveCheckoutSnapshotUsesSelectedRouteAndCurrentNetworkParams(): void
    {
        $step = StepSettings::fromArray([
            'action' => 'folder',
            'folders' => [['name' => 'landing']],
            'checkout_routes' => [
                [
                    'network_id' => 'cp',
                    'weight' => 50,
                    'links' => [['n' => 1, 'destination_id' => 'cp-1']],
                ],
                [
                    'network_id' => 'bg',
                    'weight' => 50,
                    'links' => [['n' => 1, 'destination_id' => 'bg-1']],
                ],
            ],
        ]);

        $snapshot = resolve_checkout_snapshot(
            0,
            $step,
            $this->networks('fresh={clickid}'),
            $this->destinations(),
            static fn(array $routes): int => 1,
            static fn(string $url): string => str_replace('{clickid}', 'CLICK-1', $url)
        );

        self::assertSame('bg', $snapshot['_ytds_network_id']);
        self::assertSame('BuyGoods', $snapshot['_ytds_network_name']);
        self::assertSame(0, $snapshot['_ytds_checkout']['step']);
        self::assertSame([
            'n' => 1,
            'destination_id' => 'bg-1',
            'destination_name' => 'BG 1',
            'url' => 'https://bg.test/1?fresh=CLICK-1',
        ], $snapshot['_ytds_checkout']['links'][0]);
    }

    public function testCheckoutSnapshotParamsCannotBeOverriddenByIncomingParams(): void
    {
        $incoming = [
            'campaignname' => 'example',
            '_ytds_network_id' => 'attacker',
            '_ytds_checkout' => ['url' => 'https://attacker.test'],
        ];
        $snapshot = [
            '_ytds_network_id' => 'cp',
            '_ytds_network_name' => 'Cartpanda',
            '_ytds_checkout' => ['step' => 0, 'links' => []],
        ];

        self::assertSame('cp', merge_checkout_snapshot_params($incoming, $snapshot)['_ytds_network_id']);
        self::assertSame('example', merge_checkout_snapshot_params($incoming, $snapshot)['campaignname']);
    }

    public function testFrozenLinksAreReadOnlyForTheirRecordedStep(): void
    {
        $params = [
            '_ytds_checkout' => [
                'step' => 1,
                'links' => [['n' => 2, 'url' => 'https://frozen.test/2']],
            ],
        ];

        self::assertNull(checkout_links_from_click_params($params, 0));
        self::assertSame(
            [['n' => 2, 'url' => 'https://frozen.test/2']],
            checkout_links_from_click_params($params, 1)
        );
    }

    public function testMalformedFrozenCheckoutDoesNotFallBackToLegacyLinks(): void
    {
        self::assertSame([], checkout_links_from_click_params(['_ytds_checkout' => 'broken'], 0));
        self::assertSame([], checkout_links_from_click_params(['_ytds_checkout' => ['links' => []]], 0));
    }

    public function testSelectionValidationDoesNotPersistRouteId(): void
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
        $selection = checkout_selection_from_route($step->checkoutRoutes[0]);

        self::assertSame([
            'network_id' => 'cp',
            'links' => [['n' => 1, 'destination_id' => 'cp-1']],
        ], $selection);
        self::assertTrue(checkout_selection_is_valid($selection, $step));
        self::assertArrayNotHasKey('route_id', $selection);
    }

    /** @return array<int, array<string, string>> */
    private function networks(string $bgParams = 'subid={clickid}'): array
    {
        return [
            ['id' => 'cp', 'name' => 'Cartpanda', 'params' => 'cid={clickid}'],
            ['id' => 'bg', 'name' => 'BuyGoods', 'params' => $bgParams],
        ];
    }

    /** @return array<int, array<string, string>> */
    private function destinations(): array
    {
        return [
            ['id' => 'cp-1', 'name' => 'CP 1', 'base_url' => 'https://cp.test/1', 'network_id' => 'cp'],
            ['id' => 'bg-1', 'name' => 'BG 1', 'base_url' => 'https://bg.test/1', 'network_id' => 'bg'],
        ];
    }
}
