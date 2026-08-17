<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../code/destinations.php';

final class NetworksDestinationsTest extends TestCase
{
    // ── Network ───────────────────────────────────────────────────────────────────

    #[DataProvider('paramsProvider')]
    public function testNormalizeParamsStripsLeadingJoiner(string $in, string $out): void
    {
        self::assertSame($out, Network::normalizeParams($in));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function paramsProvider(): array
    {
        return [
            'plain' => ['subid={clickid}', 'subid={clickid}'],
            'leading question' => ['?subid={clickid}', 'subid={clickid}'],
            'leading amp' => ['&subid={clickid}', 'subid={clickid}'],
            'padded' => ['  ?subid={clickid}  ', 'subid={clickid}'],
            'empty' => ['', ''],
        ];
    }

    public function testNetworkRoundTrip(): void
    {
        $n = Network::fromArray(['id' => 'abc', 'name' => ' BuyGoods ', 'params' => '?s={clickid}']);
        self::assertSame(['id' => 'abc', 'name' => 'BuyGoods', 'params' => 's={clickid}'], $n->jsonSerialize());
    }

    // ── Destination::normalizeBaseUrl ─────────────────────────────────────────────

    #[DataProvider('baseUrlProvider')]
    public function testNormalizeBaseUrl(string $in, string $out): void
    {
        self::assertSame($out, Destination::normalizeBaseUrl($in));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function baseUrlProvider(): array
    {
        return [
            'no scheme' => ['afflink.com', 'https://afflink.com'],
            'https kept' => ['https://afflink.com', 'https://afflink.com'],
            'http kept' => ['http://afflink.com', 'http://afflink.com'],
            'padded' => ['  afflink.com/go  ', 'https://afflink.com/go'],
            'empty' => ['', ''],
        ];
    }

    // ── Destination::compose ──────────────────────────────────────────────────────

    #[DataProvider('composeProvider')]
    public function testCompose(string $base, string $params, string $expected): void
    {
        self::assertSame($expected, Destination::compose($base, $params));
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function composeProvider(): array
    {
        return [
            'no query in base' => ['https://a.com', 'subid={clickid}', 'https://a.com?subid={clickid}'],
            'base already has query' => ['https://a.com?x=1', 'subid={clickid}', 'https://a.com?x=1&subid={clickid}'],
            'base ends with question' => ['https://a.com?', 'subid={clickid}', 'https://a.com?subid={clickid}'],
            'base ends with amp' => ['https://a.com?x=1&', 'subid={clickid}', 'https://a.com?x=1&subid={clickid}'],
            'leading joiner in params' => ['https://a.com', '&subid={clickid}', 'https://a.com?subid={clickid}'],
            'empty params' => ['https://a.com', '', 'https://a.com'],
            'empty base' => ['', 'subid=1', ''],
        ];
    }

    // ── DestinationLibrary ────────────────────────────────────────────────────────

    public function testEffectiveUrlWithNetwork(): void
    {
        $networks = DestinationLibrary::indexNetworks([
            ['id' => 'bg', 'name' => 'BuyGoods', 'params' => 'subid={clickid}&subid2={c.campaignname}'],
        ]);
        $dest = Destination::fromArray(['name' => 'P1', 'base_url' => 'afflink.com', 'network_id' => 'bg']);
        self::assertSame(
            'https://afflink.com?subid={clickid}&subid2={c.campaignname}',
            DestinationLibrary::effectiveUrl($dest, $networks)
        );
    }

    public function testEffectiveUrlWithoutNetworkIsBaseOnly(): void
    {
        $dest = Destination::fromArray(['name' => 'P1', 'base_url' => 'afflink.com', 'network_id' => '']);
        self::assertSame('https://afflink.com', DestinationLibrary::effectiveUrl($dest, []));
    }

    public function testEffectiveUrlWithDanglingNetworkDegradesToBase(): void
    {
        $dest = Destination::fromArray(['name' => 'P1', 'base_url' => 'https://afflink.com', 'network_id' => 'deleted']);
        self::assertSame('https://afflink.com', DestinationLibrary::effectiveUrl($dest, []));
    }

    public function testSanitizeNetworksAssignsIdsAndDropsNameless(): void
    {
        $counter = 0;
        $idGen = function () use (&$counter) {
            $counter++;
            return 'gen' . $counter;
        };
        $clean = NetworkLibrary::sanitize([
            ['name' => 'Keep', 'params' => '?a={clickid}'],   // no id -> assigned
            ['id' => 'x', 'name' => 'HasId', 'params' => ''],
            ['name' => '', 'params' => 'ignored'],             // nameless -> dropped
            'garbage',
        ], $idGen);
        self::assertSame([
            ['id' => 'gen1', 'name' => 'Keep', 'params' => 'a={clickid}'],
            ['id' => 'x', 'name' => 'HasId', 'params' => ''],
        ], $clean);
    }

    public function testSanitizeDestinationsDropsMissingNameOrBase(): void
    {
        $idGen = fn() => 'gen';
        $clean = DestinationLibrary::sanitize([
            ['name' => 'Ok', 'base_url' => 'afflink.com', 'network_id' => 'bg'],
            ['name' => 'NoBase', 'base_url' => '', 'network_id' => 'bg'],   // dropped
            ['name' => '', 'base_url' => 'afflink.com'],                     // dropped
        ], $idGen);
        self::assertSame([
            ['id' => 'gen', 'name' => 'Ok', 'base_url' => 'https://afflink.com', 'network_id' => 'bg'],
        ], $clean);
    }
}
