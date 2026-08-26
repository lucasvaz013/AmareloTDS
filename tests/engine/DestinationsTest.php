<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../code/campaign.php';
require_once __DIR__ . '/../../code/htmlprocessing.php';

final class DestinationsTest extends TestCase
{
    // ── Model: StepFolderSettings.links ───────────────────────────────────────────

    public function testFolderParsesAndSerializesLinks(): void
    {
        $folder = StepFolderSettings::fromArray([
            'name' => 'lp',
            'weight' => 50,
            'links' => [
                ['n' => 1, 'url' => 'https://a.com?x={clickid}'],
                ['n' => 3, 'url' => 'https://b.com'],
            ],
        ]);
        self::assertSame(
            [['n' => 1, 'url' => 'https://a.com?x={clickid}'], ['n' => 3, 'url' => 'https://b.com']],
            $folder->links
        );
        self::assertSame($folder->links, $folder->jsonSerialize()['links']);
    }

    public function testFolderDropsInvalidLinkEntriesOnRead(): void
    {
        $folder = StepFolderSettings::fromArray(['name' => 'lp', 'links' => [
            ['n' => 0, 'url' => 'https://zero.com'],   // n < 1 dropped
            ['n' => 2, 'url' => ''],                    // empty url dropped
            ['n' => 2, 'url' => 'https://keep.com'],
            'garbage',                                  // non-array skipped
        ]]);
        self::assertSame([['n' => 2, 'url' => 'https://keep.com']], $folder->links);
    }

    public function testFolderWithoutLinksKeyIsEmpty(): void
    {
        self::assertSame([], StepFolderSettings::fromArray(['name' => 'lp'])->links);
    }

    public function testGetLinksResolvesByFolderName(): void
    {
        $step = StepSettings::fromArray(['action' => 'folder', 'folders' => [
            ['name' => 'a', 'links' => [['n' => 1, 'url' => 'https://a.com']]],
            ['name' => 'b', 'links' => []],
        ]]);
        self::assertSame([['n' => 1, 'url' => 'https://a.com']], $step->getLinks('a'));
        self::assertSame([], $step->getLinks('b'));
        self::assertSame([], $step->getLinks('missing'));
    }

    // ── Render: resolve_link_macros ───────────────────────────────────────────────

    public function testResolveReplacesMappedLinks(): void
    {
        $out = resolve_link_macros(
            '<a href="{link:1}">A</a><a href="{link:2}">B</a>',
            [['n' => 1, 'url' => 'https://x.com/1'], ['n' => 2, 'url' => 'https://x.com/2']],
            fn(string $u): string => $u
        );
        self::assertSame('<a href="https://x.com/1">A</a><a href="https://x.com/2">B</a>', $out);
    }

    public function testResolveUnmappedBecomesHash(): void
    {
        self::assertSame(
            '<a href="#">x</a>',
            resolve_link_macros('<a href="{link:5}">x</a>', [['n' => 1, 'url' => 'https://x.com']], fn(string $u): string => $u)
        );
    }

    public function testResolveRunsUrlThroughResolver(): void
    {
        self::assertSame(
            'go https://x.com?c=ABC',
            resolve_link_macros(
                'go {link:1}',
                [['n' => 1, 'url' => 'https://x.com?c={clickid}']],
                fn(string $u): string => str_replace('{clickid}', 'ABC', $u)
            )
        );
    }

    public function testResolveLeavesHtmlWithoutTokensUntouched(): void
    {
        $html = '<a href="https://hardcoded.com">no macro</a>';
        self::assertSame($html, resolve_link_macros($html, [], fn(string $u): string => $u));
    }

    // ── Validation: normalize_links_input via normalize_flow_input ────────────────

    public function testValidationNormalizesAndKeepsLinks(): void
    {
        require_once __DIR__ . '/../../code/campaignmutation.php';
        $input = $this->flowWithFolderLinks([
            ['n' => 2, 'url' => 'https://checkout.com/1pote?sub1={clickid}'],
            ['n' => 1, 'url' => ' https://checkout.com/3potes '],
            ['n' => 5, 'url' => ''],
        ]);
        self::assertNull(normalize_flow_input($input, ['black' => ['flows' => []]]));
        self::assertSame([
            ['n' => 2, 'url' => 'https://checkout.com/1pote?sub1={clickid}'],
            ['n' => 1, 'url' => 'https://checkout.com/3potes'],
        ], $input['black']['flows'][0]['steps'][0]['folders'][0]['links']);
    }

    public function testValidationRejectsBadUrl(): void
    {
        require_once __DIR__ . '/../../code/campaignmutation.php';
        $input = $this->flowWithFolderLinks([['n' => 1, 'url' => 'not-a-url']]);
        self::assertStringContainsString('valid http', (string)normalize_flow_input($input, ['black' => ['flows' => []]]));
    }

    public function testValidationRejectsDuplicateN(): void
    {
        require_once __DIR__ . '/../../code/campaignmutation.php';
        $input = $this->flowWithFolderLinks([
            ['n' => 1, 'url' => 'https://a.com'],
            ['n' => 1, 'url' => 'https://b.com'],
        ]);
        self::assertStringContainsString('twice', (string)normalize_flow_input($input, ['black' => ['flows' => []]]));
    }

    public function testValidationRejectsZeroN(): void
    {
        require_once __DIR__ . '/../../code/campaignmutation.php';
        $input = $this->flowWithFolderLinks([['n' => 0, 'url' => 'https://a.com']]);
        self::assertStringContainsString('>= 1', (string)normalize_flow_input($input, ['black' => ['flows' => []]]));
    }

    public function testValidationRejectsTooManyLinks(): void
    {
        require_once __DIR__ . '/../../code/campaignmutation.php';
        $links = [];
        for ($i = 1; $i <= 21; $i++) {
            $links[] = ['n' => $i, 'url' => 'https://x.com/' . $i];
        }
        $input = $this->flowWithFolderLinks($links);
        self::assertStringContainsString('at most 20', (string)normalize_flow_input($input, ['black' => ['flows' => []]]));
    }

    public function testValidationNormalizesCheckoutRoutesAgainstCatalog(): void
    {
        require_once __DIR__ . '/../../code/campaignmutation.php';
        $input = $this->flowWithCheckoutRoutes([
            [
                'network_id' => 'cp',
                'weight' => 1,
                'links' => [
                    ['n' => 1, 'destination_id' => 'cp-1'],
                    ['n' => 2, 'destination_id' => 'cp-2'],
                ],
            ],
            [
                'network_id' => 'bg',
                'weight' => 1,
                'links' => [
                    ['n' => 1, 'destination_id' => 'bg-1'],
                    ['n' => 2, 'destination_id' => 'bg-2'],
                ],
            ],
        ]);

        self::assertNull(normalize_flow_input(
            $input,
            ['black' => ['flows' => []]],
            $this->networks(),
            $this->destinations()
        ));
        self::assertSame([50, 50], array_column(
            $input['black']['flows'][0]['steps'][0]['checkout_routes'],
            'weight'
        ));
    }

    public function testValidationRejectsCheckoutDestinationFromAnotherNetwork(): void
    {
        require_once __DIR__ . '/../../code/campaignmutation.php';
        $input = $this->flowWithCheckoutRoutes([[
            'network_id' => 'cp',
            'links' => [['n' => 1, 'destination_id' => 'bg-1']],
        ]]);

        self::assertStringContainsString('does not belong', (string)normalize_flow_input(
            $input,
            ['black' => ['flows' => []]],
            $this->networks(),
            $this->destinations()
        ));
    }

    public function testValidationRejectsCheckoutRoutesWithDifferentSlots(): void
    {
        require_once __DIR__ . '/../../code/campaignmutation.php';
        $input = $this->flowWithCheckoutRoutes([
            ['network_id' => 'cp', 'links' => [['n' => 1, 'destination_id' => 'cp-1']]],
            ['network_id' => 'bg', 'links' => [['n' => 2, 'destination_id' => 'bg-2']]],
        ]);

        self::assertStringContainsString('same slots', (string)normalize_flow_input(
            $input,
            ['black' => ['flows' => []]],
            $this->networks(),
            $this->destinations()
        ));
    }

    public function testValidationRejectsMoreThanOneCheckoutStepPerFlow(): void
    {
        require_once __DIR__ . '/../../code/campaignmutation.php';
        $step = $this->flowWithCheckoutRoutes([[
            'network_id' => 'cp',
            'links' => [['n' => 1, 'destination_id' => 'cp-1']],
        ]])['black']['flows'][0]['steps'][0];
        $input = ['black' => ['flows' => [[
            'name' => 'Flow',
            'steps' => [$step, $step],
        ]]]];

        self::assertStringContainsString('one Step', (string)normalize_flow_input(
            $input,
            ['black' => ['flows' => []]],
            $this->networks(),
            $this->destinations()
        ));
    }

    public function testValidationRejectsUnknownCheckoutNetworkAndDestination(): void
    {
        require_once __DIR__ . '/../../code/campaignmutation.php';
        $unknownNetwork = $this->flowWithCheckoutRoutes([[
            'network_id' => 'missing',
            'links' => [['n' => 1, 'destination_id' => 'cp-1']],
        ]]);
        $unknownDestination = $this->flowWithCheckoutRoutes([[
            'network_id' => 'cp',
            'links' => [['n' => 1, 'destination_id' => 'ghost']],
        ]]);

        self::assertStringContainsString('existing Network', (string)normalize_flow_input(
            $unknownNetwork,
            ['black' => ['flows' => []]],
            $this->networks(),
            $this->destinations()
        ));
        self::assertStringContainsString('existing Destination', (string)normalize_flow_input(
            $unknownDestination,
            ['black' => ['flows' => []]],
            $this->networks(),
            $this->destinations()
        ));
    }

    public function testValidationRejectsCheckoutRoutesOnRedirectStep(): void
    {
        require_once __DIR__ . '/../../code/campaignmutation.php';
        $input = ['black' => ['flows' => [[
            'name' => 'Flow',
            'steps' => [[
                'action' => 'redirect',
                'folders' => [],
                'redirect' => ['urls' => [['url' => 'https://example.com']], 'type' => 302],
                'checkout_routes' => [[
                    'network_id' => 'cp',
                    'links' => [['n' => 1, 'destination_id' => 'cp-1']],
                ]],
            ]],
        ]]]];

        self::assertStringContainsString('folder Steps', (string)normalize_flow_input(
            $input,
            ['black' => ['flows' => []]],
            $this->networks(),
            $this->destinations()
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $links
     * @return array<string, mixed>
     */
    private function flowWithFolderLinks(array $links): array
    {
        return ['black' => ['flows' => [[
            'name' => 'Flow',
            'steps' => [[
                'action' => 'folder',
                'folders' => [[
                    'name' => 'landing',
                    'loadtype' => 'base',
                    'weight' => 100,
                    'mvt' => ['enabled' => false, 'tests' => []],
                    'links' => $links,
                ]],
                'redirect' => ['urls' => [], 'type' => 302],
            ]],
        ]]]];
    }

    /** @param array<int, array<string, mixed>> $routes */
    private function flowWithCheckoutRoutes(array $routes): array
    {
        $input = $this->flowWithFolderLinks([]);
        $input['black']['flows'][0]['steps'][0]['checkout_routes'] = $routes;
        return $input;
    }

    /** @return array<int, array<string, string>> */
    private function networks(): array
    {
        return [
            ['id' => 'cp', 'name' => 'Cartpanda', 'params' => 'cid={clickid}'],
            ['id' => 'bg', 'name' => 'BuyGoods', 'params' => 'subid={clickid}'],
        ];
    }

    /** @return array<int, array<string, string>> */
    private function destinations(): array
    {
        return [
            ['id' => 'cp-1', 'name' => 'CP 1', 'base_url' => 'https://cp.test/1', 'network_id' => 'cp'],
            ['id' => 'cp-2', 'name' => 'CP 2', 'base_url' => 'https://cp.test/2', 'network_id' => 'cp'],
            ['id' => 'bg-1', 'name' => 'BG 1', 'base_url' => 'https://bg.test/1', 'network_id' => 'bg'],
            ['id' => 'bg-2', 'name' => 'BG 2', 'base_url' => 'https://bg.test/2', 'network_id' => 'bg'],
        ];
    }
}
