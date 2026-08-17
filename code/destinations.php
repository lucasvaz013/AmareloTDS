<?php

require_once __DIR__ . '/networks.php';

/**
 * Destinations library — a global registry of affiliate links. A destination is a base URL plus an
 * optional Network reference; its effective URL is base + the network's params, joined as a query
 * string. That effective URL is what the campaign step editor snapshots into a {link:N} slot. Pure
 * model + compose helper (testable); the admin editor wires it to common settings.
 */

final class Destination implements JsonSerializable
{
    public function __construct(
        public string $id,
        public string $name,
        public string $baseUrl,
        public string $networkId,
    ) {
    }

    public static function fromArray(array $a): self
    {
        return new self(
            trim((string)($a['id'] ?? '')),
            trim((string)($a['name'] ?? '')),
            self::normalizeBaseUrl((string)($a['base_url'] ?? '')),
            trim((string)($a['network_id'] ?? '')),
        );
    }

    /** Adds https:// when no scheme is given, so the composed URL passes the step's http(s) check. */
    public static function normalizeBaseUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        return $url;
    }

    /** effective URL = base + params, joined with ? or & depending on what the base already has. */
    public static function compose(string $baseUrl, string $params): string
    {
        $baseUrl = trim($baseUrl);
        $params = ltrim(trim($params), '?&');
        if ($baseUrl === '' || $params === '') {
            return $baseUrl;
        }
        if (str_contains($baseUrl, '?')) {
            $last = substr($baseUrl, -1);
            return ($last === '?' || $last === '&') ? $baseUrl . $params : $baseUrl . '&' . $params;
        }
        return $baseUrl . '?' . $params;
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'base_url' => $this->baseUrl,
            'network_id' => $this->networkId,
        ];
    }
}

final class DestinationLibrary
{
    /**
     * The URL that gets snapshotted into a step. A missing/unknown network reference degrades to
     * the base URL alone rather than erroring — the same graceful-fallback stance used elsewhere.
     *
     * @param array<string, Network> $networksById
     */
    public static function effectiveUrl(Destination $destination, array $networksById): string
    {
        $params = isset($networksById[$destination->networkId])
            ? $networksById[$destination->networkId]->params
            : '';
        return Destination::compose($destination->baseUrl, $params);
    }

    /**
     * @param array<int, mixed> $rawNetworks
     * @return array<string, Network>
     */
    public static function indexNetworks(array $rawNetworks): array
    {
        $out = [];
        foreach ($rawNetworks as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $network = Network::fromArray($raw);
            if ($network->id !== '') {
                $out[$network->id] = $network;
            }
        }
        return $out;
    }

    /**
     * Cleans a raw list from the editor: trims, drops entries missing a name or base URL, keeps
     * existing ids and assigns fresh ones (via $idGen) to new or colliding entries.
     *
     * @param array<int, mixed> $raw
     * @return array<int, array{id: string, name: string, base_url: string, network_id: string}>
     */
    public static function sanitize(array $raw, callable $idGen): array
    {
        $out = [];
        $seen = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $destination = Destination::fromArray($entry);
            if ($destination->name === '' || $destination->baseUrl === '') {
                continue;
            }
            $id = ($destination->id !== '' && !isset($seen[$destination->id]))
                ? $destination->id
                : (string)$idGen();
            $seen[$id] = true;
            $out[] = [
                'id' => $id,
                'name' => $destination->name,
                'base_url' => $destination->baseUrl,
                'network_id' => $destination->networkId,
            ];
        }
        return $out;
    }
}
