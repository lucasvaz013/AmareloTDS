<?php

require_once __DIR__ . '/campaign.php';
require_once __DIR__ . '/destinations.php';

/**
 * Makes query parameters captured by FiltrationCore available to the existing {c.*} macro
 * contract while a Checkout Route is frozen before the click row exists.
 *
 * @param array<string, mixed> $clickParams
 * @return array<string, mixed>
 */
function checkout_macro_click_params(array $clickParams): array
{
    $queryParams = is_array($clickParams['qs'] ?? null) ? $clickParams['qs'] : [];
    $explicitParams = is_array($clickParams['params'] ?? null) ? $clickParams['params'] : [];
    $clickParams['params'] = array_replace($queryParams, $explicitParams);
    return $clickParams;
}

/** @return array{network_id:string,links:array<int,array{n:int,destination_id:string}>} */
function checkout_selection_from_route(CheckoutRouteSettings $route): array
{
    return [
        'network_id' => $route->networkId,
        'links' => array_map(
            static fn(CheckoutRouteLinkSettings $link): array => $link->jsonSerialize(),
            $route->links
        ),
    ];
}

function checkout_selection_is_valid(array $selection, StepSettings $step): bool
{
    foreach ($step->checkoutRoutes as $route) {
        if (checkout_selection_from_route($route) === $selection) {
            return true;
        }
    }
    return false;
}

/** @param CheckoutRouteSettings[] $routes */
function checkout_pick_route_index(array $routes): int
{
    if (count($routes) <= 1) {
        return 0;
    }
    $weights = array_map(static fn(CheckoutRouteSettings $route): int => max(0, $route->weight), $routes);
    $total = array_sum($weights);
    if ($total <= 0) {
        return random_int(0, count($routes) - 1);
    }
    $roll = random_int(1, $total);
    $cumulative = 0;
    foreach ($weights as $index => $weight) {
        $cumulative += $weight;
        if ($roll <= $cumulative) {
            return $index;
        }
    }
    return count($routes) - 1;
}

/**
 * Resolves one step's selected route against the live libraries and freezes the composed URLs.
 *
 * @param array<int, mixed> $rawNetworks
 * @param array<int, mixed> $rawDestinations
 * @param callable(array<int, CheckoutRouteSettings>):int|null $pickIndex
 * @param callable(string):string $resolveUrlMacros
 * @return array{_ytds_network_id:string,_ytds_network_name:string,_ytds_checkout:array{step:int,links:array}}
 */
function resolve_checkout_snapshot(
    int $stepIndex,
    StepSettings $step,
    array $rawNetworks,
    array $rawDestinations,
    ?callable $pickIndex,
    callable $resolveUrlMacros,
    ?array $savedSelection = null
): array {
    if (!$step->hasCheckoutRoutes()) {
        return [];
    }

    $selectedRoute = null;
    if ($savedSelection !== null && checkout_selection_is_valid($savedSelection, $step)) {
        foreach ($step->checkoutRoutes as $route) {
            if (checkout_selection_from_route($route) === $savedSelection) {
                $selectedRoute = $route;
                break;
            }
        }
    }
    if ($selectedRoute === null) {
        $index = $pickIndex !== null ? (int)$pickIndex($step->checkoutRoutes) : checkout_pick_route_index($step->checkoutRoutes);
        if (!isset($step->checkoutRoutes[$index])) {
            throw new RuntimeException('Checkout Route selector returned an invalid index.');
        }
        $selectedRoute = $step->checkoutRoutes[$index];
    }

    $networksById = DestinationLibrary::indexNetworks($rawNetworks);
    $network = $networksById[$selectedRoute->networkId] ?? null;
    if (!$network instanceof Network) {
        throw new RuntimeException('Checkout Route references an unknown Network.');
    }
    $destinationsById = [];
    foreach ($rawDestinations as $rawDestination) {
        if (!is_array($rawDestination)) {
            continue;
        }
        $destination = Destination::fromArray($rawDestination);
        if ($destination->id !== '') {
            $destinationsById[$destination->id] = $destination;
        }
    }

    $links = [];
    foreach ($selectedRoute->links as $routeLink) {
        $destination = $destinationsById[$routeLink->destinationId] ?? null;
        if (!$destination instanceof Destination || $destination->networkId !== $network->id) {
            throw new RuntimeException('Checkout Route references an invalid Destination.');
        }
        $links[] = [
            'n' => $routeLink->n,
            'destination_id' => $destination->id,
            'destination_name' => $destination->name,
            'url' => $resolveUrlMacros(DestinationLibrary::effectiveUrl($destination, $networksById)),
        ];
    }

    return [
        '_ytds_network_id' => $network->id,
        '_ytds_network_name' => $network->name,
        '_ytds_checkout' => ['step' => $stepIndex, 'links' => $links],
    ];
}

/** @return array<string, mixed> */
function merge_checkout_snapshot_params(array $params, array $snapshot): array
{
    foreach (array_keys($params) as $key) {
        if (str_starts_with((string)$key, '_ytds_')) {
            unset($params[$key]);
        }
    }
    foreach (['_ytds_network_id', '_ytds_network_name', '_ytds_checkout'] as $key) {
        if (array_key_exists($key, $snapshot)) {
            $params[$key] = $snapshot[$key];
        }
    }
    return $params;
}

/** @return array{network_id:string,links:array<int,array{n:int,destination_id:string}>}|null */
function checkout_selection_from_snapshot(array $snapshot): ?array
{
    $networkId = trim((string)($snapshot['_ytds_network_id'] ?? ''));
    $rawLinks = $snapshot['_ytds_checkout']['links'] ?? null;
    if ($networkId === '' || !is_array($rawLinks)) {
        return null;
    }
    $links = [];
    foreach ($rawLinks as $rawLink) {
        if (!is_array($rawLink)) {
            return null;
        }
        $n = (int)($rawLink['n'] ?? 0);
        $destinationId = trim((string)($rawLink['destination_id'] ?? ''));
        if ($n < 1 || $destinationId === '') {
            return null;
        }
        $links[] = ['n' => $n, 'destination_id' => $destinationId];
    }
    return ['network_id' => $networkId, 'links' => $links];
}

/** @return array<int,array{n:int,url:string}>|null null means no frozen checkout for this step */
function checkout_links_from_click_params(mixed $params, int $stepIndex): ?array
{
    if (is_string($params)) {
        $params = json_decode($params, true);
    }
    if (!is_array($params)) {
        return null;
    }
    if (!array_key_exists('_ytds_checkout', $params)) {
        return null;
    }
    $checkout = $params['_ytds_checkout'];
    if (!is_array($checkout) || !array_key_exists('step', $checkout)) {
        return [];
    }
    $recordedStep = $checkout['step'];
    if (!is_int($recordedStep) && !(is_string($recordedStep) && ctype_digit($recordedStep))) {
        return [];
    }
    if ((int)$recordedStep !== $stepIndex) {
        return null;
    }
    $rawLinks = $checkout['links'] ?? null;
    if (!is_array($rawLinks)) {
        return [];
    }
    $links = [];
    foreach ($rawLinks as $rawLink) {
        if (!is_array($rawLink)) {
            continue;
        }
        $n = (int)($rawLink['n'] ?? 0);
        $url = trim((string)($rawLink['url'] ?? ''));
        if ($n >= 1 && preg_match('#^https?://#i', $url) === 1) {
            $links[] = ['n' => $n, 'url' => $url];
        }
    }
    return $links;
}
