<?php

use PHPUnit\Framework\TestCase;

final class CheckoutRoutesPanelTest extends TestCase
{
    public function testStepOwnsCheckoutRoutesPanelAndCatalogs(): void
    {
        $form = file_get_contents(__DIR__ . '/../../code/admin/campsettings.php');

        $this->assertStringContainsString('flow-checkout-routes-panel', $form);
        $this->assertStringContainsString('data-checkout-routes=', $form);
        $this->assertStringContainsString('window.CHECKOUT_ROUTE_NETWORKS', $form);
        $this->assertStringContainsString('window.CHECKOUT_ROUTE_DESTINATIONS', $form);
        $this->assertStringContainsString("'network_id' => \$__dest->networkId", $form);
        $this->assertStringContainsString("'id' => \$__dest->id", $form);
    }

    public function testCheckoutRoutesGroupUsesTheSameTitlePatternAsOtherFlowGroups(): void
    {
        $form = file_get_contents(__DIR__ . '/../../code/admin/campsettings.php');
        $script = file_get_contents(__DIR__ . '/../../code/admin/js/flows/checkout-routes.js');

        $this->assertStringContainsString('<span class="flow-group-title">Checkout Routes</span>', $form);
        $this->assertStringNotContainsString('flow-checkout-routes-heading', $script);
        $this->assertStringNotContainsString('<strong>Checkout Routes</strong>', $script);
    }

    public function testModuleSupportsRoutesSlotsWeightsAndLegacySourceLock(): void
    {
        $script = file_get_contents(__DIR__ . '/../../code/admin/js/flows/checkout-routes.js');
        $collector = file_get_contents(__DIR__ . '/../../code/admin/js/flows/collectors.js');
        $index = file_get_contents(__DIR__ . '/../../code/admin/js/flows/index.js');

        foreach ([
            'flow-checkout-route-add',
            'flow-checkout-route-remove',
            'flow-checkout-slot-add',
            'flow-checkout-slot-remove',
            'flow-checkout-route-network',
            'flow-checkout-route-destination',
            'flow-checkout-route-weight',
            'Checkout Routes are the source of truth',
        ] as $contract) {
            $this->assertStringContainsString($contract, $script);
        }
        $this->assertStringContainsString('uniqueNs.length >= 20', $script);
        $this->assertStringContainsString('collectCheckoutRoutes', $collector);
        $this->assertStringContainsString('checkout_routes:', $collector);
        $this->assertStringContainsString("action === 'folder'", $collector);
        $this->assertStringContainsString('initializeCheckoutRoutesPanels', $index);
        $this->assertStringContainsString('handleCheckoutRoutesClick', $index);
        $this->assertStringContainsString('handleCheckoutRoutesChange', $index);
    }

    public function testNewlyAddedFoldersInheritTheLegacyDestinationLock(): void
    {
        $script = file_get_contents(__DIR__ . '/../../code/admin/js/flows/checkout-routes.js');
        $handlers = file_get_contents(__DIR__ . '/../../code/admin/js/flows/handlers.js');
        $zip = file_get_contents(__DIR__ . '/../../code/admin/js/flows/zip-upload.js');
        $templates = file_get_contents(__DIR__ . '/../../code/admin/js/flows/templates.js');

        $this->assertStringContainsString('export function lockLegacyLinksForStep', $script);
        $this->assertStringContainsString('lockLegacyLinksForStep(stepSec)', $handlers);
        $this->assertStringContainsString('lockLegacyLinksForStep(stepSec)', $zip);
        $this->assertStringContainsString('lockLegacyLinksForStep', $templates);
    }

    public function testDestinationPageDerivesReadableNetworkNameWithoutPersistingLabel(): void
    {
        $page = file_get_contents(__DIR__ . '/../../code/admin/destinations.php');
        $script = file_get_contents(__DIR__ . '/../../code/admin/js/destinations.js');

        $this->assertStringContainsString('destination-network-name', $page);
        $this->assertStringContainsString("'name' => \$n->name", $page);
        $this->assertStringContainsString('nameByNetwork', $script);
        $this->assertStringNotContainsString("label:", $script);
        $this->assertStringNotContainsString("'label' =>", $page);
        $css = file_get_contents(__DIR__ . '/../../code/admin/css/destinations.css');
        $this->assertStringContainsString('.destination-network-name', $css);
    }

    public function testBlankTemplateStartsWithNoCheckoutRoutes(): void
    {
        $template = json_decode((string)file_get_contents(__DIR__ . '/../../code/templates/blank.json'), true);
        $step = $template['black']['flows'][0]['steps'][0];

        $this->assertArrayHasKey('checkout_routes', $step);
        $this->assertSame([], $step['checkout_routes']);
    }
}
