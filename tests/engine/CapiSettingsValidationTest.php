<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../code/campaignvalidation.php';

final class CapiSettingsValidationTest extends TestCase
{
    public function testMultiplePixelsAreNormalizedAndFirstIsMirrored(): void
    {
        $input = $this->validInput();
        $input['capi'] = [
            'enabled' => true,
            'pixels' => [
                ['pixel_id' => ' 111 ', 'access_token' => ' TOKEN_A ', 'test_event_code' => ''],
                ['pixel_id' => ' 222 ', 'access_token' => ' TOKEN_B ', 'test_event_code' => ' TEST_B '],
            ],
            'map' => [['status' => 'purchase', 'event_name' => 'Purchase']],
        ];

        self::assertNull(normalize_conversion_input($input));
        self::assertNull(normalize_capi_input($input));
        self::assertSame('111', $input['capi']['pixel_id']);
        self::assertSame('TOKEN_A', $input['capi']['access_token']);
        self::assertSame('', $input['capi']['test_event_code']);
        self::assertSame([
            ['pixel_id' => '111', 'access_token' => 'TOKEN_A', 'test_event_code' => ''],
            ['pixel_id' => '222', 'access_token' => 'TOKEN_B', 'test_event_code' => 'TEST_B'],
        ], $input['capi']['pixels']);
    }

    public function testBlankRowsDoNotCountAgainstTwentyPixelLimit(): void
    {
        $input = $this->validInput();
        $pixels = [];
        for ($index = 1; $index <= CapiSettings::MAX_PIXELS; $index++) {
            $pixels[] = ['pixel_id' => (string)$index, 'access_token' => 'TOKEN_' . $index];
        }
        $pixels[] = ['pixel_id' => '', 'access_token' => '', 'test_event_code' => ''];
        $input['capi'] = [
            'enabled' => true,
            'pixels' => $pixels,
            'map' => [['status' => 'Purchase', 'event_name' => 'Purchase']],
        ];

        self::assertNull(normalize_conversion_input($input));
        self::assertNull(normalize_capi_input($input));
        self::assertCount(CapiSettings::MAX_PIXELS, $input['capi']['pixels']);
    }

    public function testValidSettingsAreNormalized(): void
    {
        $input = $this->validInput();
        $input['capi'] = [
            'enabled' => 'true',
            'pixel_id' => ' 1234567890 ',
            'access_token' => '  EAAG-token  ',
            'test_event_code' => ' TEST12345 ',
            'map' => [
                ['status' => 'purchase', 'event_name' => 'Purchase'],
                ['status' => 'Lead', 'event_name' => 'InitiateCheckout'],
            ],
        ];

        self::assertNull(normalize_conversion_input($input));
        self::assertNull(normalize_capi_input($input));
        self::assertSame(
            [
                'enabled' => true,
                'pixel_id' => '1234567890',
                'access_token' => 'EAAG-token',
                'test_event_code' => 'TEST12345',
                'pixels' => [[
                    'pixel_id' => '1234567890',
                    'access_token' => 'EAAG-token',
                    'test_event_code' => 'TEST12345',
                ]],
                'map' => [
                    ['status' => 'Purchase', 'event_name' => 'Purchase'],
                    ['status' => 'Lead', 'event_name' => 'InitiateCheckout'],
                ],
            ],
            $input['capi']
        );
    }

    public function testAbsentSectionIsLeftAlone(): void
    {
        $input = $this->validInput();
        self::assertNull(normalize_capi_input($input));
        self::assertArrayNotHasKey('capi', $input);
    }

    public function testDisabledSettingsMayBeIncomplete(): void
    {
        $input = $this->validInput();
        $input['capi'] = ['enabled' => false, 'pixel_id' => '', 'access_token' => '', 'map' => []];

        self::assertNull(normalize_conversion_input($input));
        self::assertNull(normalize_capi_input($input));
        self::assertFalse($input['capi']['enabled']);
    }

    /** @param array<string, mixed> $capi */
    #[DataProvider('rejectedSettingsProvider')]
    public function testInvalidSettingsAreRejected(array $capi, string $expectedFragment): void
    {
        $input = $this->validInput();
        $input['capi'] = $capi;

        self::assertNull(normalize_conversion_input($input));
        $error = normalize_capi_input($input);

        self::assertIsString($error);
        self::assertStringContainsString($expectedFragment, $error);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function rejectedSettingsProvider(): array
    {
        $base = [
            'enabled' => true,
            'pixel_id' => '1234567890',
            'access_token' => 'EAAG-token',
            'map' => [['status' => 'Purchase', 'event_name' => 'Purchase']],
        ];

        return [
            'non numeric pixel id' => [
                ['pixel_id' => 'px-abc'] + $base,
                'pixel ID must be numeric',
            ],
            'token with control characters' => [
                ['access_token' => "tok\nen"] + $base,
                'access token is invalid',
            ],
            'malformed test event code' => [
                ['test_event_code' => 'has space'] + $base,
                'test event code is invalid',
            ],
            'unknown conversion status' => [
                ['map' => [['status' => 'Refunded', 'event_name' => 'Purchase']]] + $base,
                'conversion status that does not exist',
            ],
            'non standard event name' => [
                ['map' => [['status' => 'Purchase', 'event_name' => 'BoughtStuff']]] + $base,
                'standard event names',
            ],
            'duplicated status' => [
                ['map' => [
                    ['status' => 'Purchase', 'event_name' => 'Purchase'],
                    ['status' => 'purchase', 'event_name' => 'Lead'],
                ]] + $base,
                'each status maps to one event',
            ],
            'enabled without pixel id' => [
                ['pixel_id' => ''] + $base,
                'requires a pixel ID',
            ],
            'enabled without token' => [
                ['access_token' => ''] + $base,
                'requires a pixel ID',
            ],
            'enabled without any event' => [
                ['map' => []] + $base,
                'requires a pixel ID',
            ],
            'map is not a list' => [
                ['map' => ['first' => ['status' => 'Purchase', 'event_name' => 'Purchase']]] + $base,
                'must be a list',
            ],
        ];
    }

    public function testTooManyMappingsAreRejected(): void
    {
        $input = $this->validInput();
        $input['capi'] = [
            'enabled' => true,
            'pixel_id' => '1234567890',
            'access_token' => 'EAAG-token',
            'map' => array_fill(
                0,
                CapiSettings::MAX_MAPPINGS + 1,
                ['status' => 'Purchase', 'event_name' => 'Purchase']
            ),
        ];

        self::assertNull(normalize_conversion_input($input));
        self::assertStringContainsString('at most', (string)normalize_capi_input($input));
    }

    public function testMoreThanTwentyPixelsAreRejected(): void
    {
        $input = $this->validInput();
        $input['capi'] = [
            'enabled' => true,
            'pixels' => array_map(
                static fn(int $id): array => [
                    'pixel_id' => (string)(1000 + $id),
                    'access_token' => 'TOKEN_' . $id,
                    'test_event_code' => '',
                ],
                range(1, CapiSettings::MAX_PIXELS + 1)
            ),
            'map' => [['status' => 'Purchase', 'event_name' => 'Purchase']],
        ];

        self::assertNull(normalize_conversion_input($input));
        self::assertStringContainsString('at most 20', (string)normalize_capi_input($input));
    }

    public function testDuplicatePixelIdsAreRejected(): void
    {
        $input = $this->validInput();
        $input['capi'] = [
            'enabled' => true,
            'pixels' => [
                ['pixel_id' => '111', 'access_token' => 'TOKEN_A'],
                ['pixel_id' => '111', 'access_token' => 'TOKEN_B'],
            ],
            'map' => [['status' => 'Purchase', 'event_name' => 'Purchase']],
        ];

        self::assertNull(normalize_conversion_input($input));
        self::assertStringContainsString('repeats pixel ID', (string)normalize_capi_input($input));
    }

    public function testDisabledCapiMayHaveNoPixels(): void
    {
        $input = $this->validInput();
        $input['capi'] = ['enabled' => false, 'pixels' => [], 'map' => []];

        self::assertNull(normalize_conversion_input($input));
        self::assertNull(normalize_capi_input($input));
        self::assertSame([], $input['capi']['pixels']);
        self::assertSame('', $input['capi']['pixel_id']);
    }

    public function testUnknownApiVersionFieldsAreNotPersisted(): void
    {
        $input = $this->validInput();
        $input['capi'] = [
            'enabled' => false,
            'pixels' => [],
            'map' => [],
            'api_version' => 'v99.0',
            'graph_api_version' => 'v99.0',
        ];

        self::assertNull(normalize_capi_input($input));
        self::assertArrayNotHasKey('api_version', $input['capi']);
        self::assertArrayNotHasKey('graph_api_version', $input['capi']);
    }

    /** @return array<string, mixed> */
    private function validInput(): array
    {
        return [
            'conversions' => [
                'statuses' => array_map(
                    static fn(ConversionStatus $status): array => $status->jsonSerialize(),
                    ConversionSettings::defaultStatuses()
                ),
                'deduplication' => [
                    'enabled' => true,
                    'transaction_id_parameters' => ['tid'],
                    'paid_repeat_without_tid' => 'reject',
                ],
                'form' => ['enabled' => false, 'status' => 'Lead'],
                'site' => ['enabled' => false],
            ],
        ];
    }
}
