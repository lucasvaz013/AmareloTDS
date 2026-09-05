<?php

/**
 * Performs a conservative static scan of one stored landing root. The result is
 * advisory only: campaign saves remain valid when a landing is missing or
 * ambiguous because PHP and MVT can generate markup dynamically.
 *
 * @return array{
 *   status:string,
 *   offer_method:string,
 *   offer_candidates:int,
 *   checkout_link_slots:list<int>,
 *   checkout_markers:int
 * }
 */
function analyze_landing_event_compatibility(string $landingDirectory): array
{
    $result = [
        'status' => 'missing_index',
        'offer_method' => 'missing',
        'offer_candidates' => 0,
        'checkout_link_slots' => [],
        'checkout_markers' => 0,
    ];

    $indexPath = null;
    foreach (['index.php', 'index.html', 'index.htm'] as $indexName) {
        $candidate = rtrim($landingDirectory, '/\\') . DIRECTORY_SEPARATOR . $indexName;
        if (is_file($candidate)) {
            $indexPath = $candidate;
            break;
        }
    }
    if ($indexPath === null) {
        return $result;
    }

    $html = file_get_contents($indexPath);
    if (!is_string($html)) {
        $result['status'] = 'unreadable';
        return $result;
    }
    $result['status'] = 'ready';

    preg_match_all('/\sdata-ytds-offer(?:\s|=|>)/i', $html, $explicitMatches);
    $explicitCount = count($explicitMatches[0] ?? []);
    if ($explicitCount > 0) {
        $result['offer_method'] = 'explicit';
        $result['offer_candidates'] = $explicitCount;
    } else {
        preg_match_all(
            '/\sclass\s*=\s*([\'\"])(?:(?!\1).)*\bdelay-hidden\b(?:(?!\1).)*\1/is',
            $html,
            $conventionalMatches
        );
        $conventionalCount = count($conventionalMatches[0] ?? []);
        $result['offer_candidates'] = $conventionalCount;
        if ($conventionalCount === 1) {
            $result['offer_method'] = 'automatic';
        } elseif ($conventionalCount > 1) {
            $result['offer_method'] = 'ambiguous';
        }
    }

    preg_match_all('/\{link:(\d+)\}/', $html, $slotMatches);
    $slots = array_values(array_unique(array_map('intval', $slotMatches[1] ?? [])));
    sort($slots);
    $result['checkout_link_slots'] = $slots;

    preg_match_all('/\sdata-ytds-checkout(?:\s|=|>)/i', $html, $checkoutMatches);
    $result['checkout_markers'] = count($checkoutMatches[0] ?? []);

    return $result;
}
