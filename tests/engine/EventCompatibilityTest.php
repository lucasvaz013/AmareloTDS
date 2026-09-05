<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../code/eventcompatibility.php';

final class EventCompatibilityTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ytds-event-compat-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->directory, 0755, true));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testExplicitOfferMarkerTakesPriorityAndCheckoutSourcesAreCounted(): void
    {
        file_put_contents($this->directory . '/index.html', <<<'HTML'
<div class="delay-hidden"></div>
<section data-ytds-offer hidden></section>
<a href="{link:1}">One</a>
<a href="{link:1}">One again</a>
<button data-ytds-checkout>Buy</button>
HTML);

        self::assertSame([
            'status' => 'ready',
            'offer_method' => 'explicit',
            'offer_candidates' => 1,
            'checkout_link_slots' => [1],
            'checkout_markers' => 1,
        ], analyze_landing_event_compatibility($this->directory));
    }

    public function testOneDelayHiddenIsAutomaticButMultipleAreAmbiguous(): void
    {
        file_put_contents($this->directory . '/index.htm', '<div class="foo delay-hidden bar"></div>');
        self::assertSame('automatic', analyze_landing_event_compatibility($this->directory)['offer_method']);

        file_put_contents(
            $this->directory . '/index.htm',
            '<div class="delay-hidden"></div><div class="delay-hidden extra"></div>'
        );
        self::assertSame('ambiguous', analyze_landing_event_compatibility($this->directory)['offer_method']);
    }

    public function testMissingIndexIsReportedWithoutReadingOtherAssets(): void
    {
        file_put_contents($this->directory . '/fragment.html', '<div data-ytds-offer></div>');

        self::assertSame([
            'status' => 'missing_index',
            'offer_method' => 'missing',
            'offer_candidates' => 0,
            'checkout_link_slots' => [],
            'checkout_markers' => 0,
        ], analyze_landing_event_compatibility($this->directory));
    }
}
