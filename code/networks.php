<?php

/**
 * Networks library — a global, per-instance registry of traffic/affiliate networks and the query
 * parameters each one expects (e.g. BuyGoods -> "subid={clickid}&subid2={c.campaignname}"). Lives
 * in the common settings JSON. A Destination references a Network by id, and the two compose into
 * an effective URL that gets snapshotted into a campaign step. Pure model + helpers so the engine
 * suite can exercise them; the admin editor wires them to common settings.
 */

final class Network implements JsonSerializable
{
    public function __construct(
        public string $id,
        public string $name,
        public string $params,
    ) {
    }

    public static function fromArray(array $a): self
    {
        return new self(
            trim((string)($a['id'] ?? '')),
            trim((string)($a['name'] ?? '')),
            self::normalizeParams((string)($a['params'] ?? '')),
        );
    }

    /** A leading ? or & is stripped so the params always join cleanly onto a base URL. */
    public static function normalizeParams(string $params): string
    {
        return ltrim(trim($params), '?&');
    }

    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'params' => $this->params];
    }
}

final class NetworkLibrary
{
    /**
     * Cleans a raw list from the editor: trims, drops entries with an empty name, keeps existing
     * ids and assigns a fresh one (via $idGen) to new or colliding entries.
     *
     * @param array<int, mixed> $raw
     * @return array<int, array{id: string, name: string, params: string}>
     */
    public static function sanitize(array $raw, callable $idGen): array
    {
        $out = [];
        $seen = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $network = Network::fromArray($entry);
            if ($network->name === '') {
                continue;
            }
            $id = ($network->id !== '' && !isset($seen[$network->id]))
                ? $network->id
                : (string)$idGen();
            $seen[$id] = true;
            $out[] = ['id' => $id, 'name' => $network->name, 'params' => $network->params];
        }
        return $out;
    }
}
