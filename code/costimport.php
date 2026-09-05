<?php

/** Parses the campaign/day spend rows exported by Meta Ads Manager. */
final class MetaCostCsv
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_ROWS = 10000;
    public const MAX_SPEND_CENTS = 100000000000;

    /** @return array{source: array{name: string, sha256: string}, rows: array<int, array<string, mixed>>} */
    public static function parseFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new YtdsOpError('FILE_NOT_FOUND', 400, 'cost report is not readable: ' . $path, 'pass --file with a Meta CSV export');
        }
        $bytes = filesize($path);
        if ($bytes === false || $bytes > self::MAX_BYTES) {
            throw new YtdsOpError('INVALID_ARG', 400, 'cost report exceeds the 5 MiB limit', 'export a smaller daily report');
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new YtdsOpError('FILE_NOT_FOUND', 400, 'could not read cost report: ' . $path, '');
        }
        return self::parse($raw, basename($path));
    }

    /** @return array{source: array{name: string, sha256: string}, rows: array<int, array<string, mixed>>} */
    public static function parse(string $raw, string $sourceName = 'report.csv'): array
    {
        if (strlen($raw) > self::MAX_BYTES) {
            throw new YtdsOpError('INVALID_ARG', 400, 'cost report exceeds the 5 MiB limit', 'export a smaller daily report');
        }
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new YtdsOpError('INTERNAL', 500, 'could not open CSV buffer', '');
        }
        fwrite($stream, $raw);
        rewind($stream);
        $header = fgetcsv($stream, 0, ',', '"', '');
        if (!is_array($header)) {
            fclose($stream);
            throw new YtdsOpError('INVALID_CSV', 400, 'cost report has no CSV header', 'export a campaign report from Meta Ads Manager');
        }
        $header = array_map(static fn(mixed $value): string => trim((string)$value), $header);
        $required = ['Nome da campanha', 'Valor gasto (USD)', 'Início dos relatórios'];
        foreach ($required as $column) {
            if (!in_array($column, $header, true)) {
                fclose($stream);
                throw new YtdsOpError('INVALID_CSV', 400, 'cost report is missing column: ' . $column, 'export campaign name, amount spent, and reporting dates');
            }
        }

        $rows = [];
        $line = 1;
        while (($values = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $line++;
            if (count($values) === 1 && trim((string)$values[0]) === '') {
                continue;
            }
            $values = array_pad($values, count($header), '');
            $record = array_combine($header, array_slice($values, 0, count($header)));
            if (!is_array($record)) {
                fclose($stream);
                throw new YtdsOpError('INVALID_CSV', 400, 'invalid CSV row at line ' . $line, '');
            }
            $name = trim((string)($record['Nome da campanha'] ?? ''));
            if ($name === '') {
                continue; // Meta total row; importing it would duplicate detailed spend.
            }
            $currency = strtoupper(trim((string)($record['Moeda'] ?? 'USD')));
            if ($currency === '') {
                $currency = 'USD';
            }
            if ($currency !== 'USD') {
                fclose($stream);
                throw new YtdsOpError('INVALID_CURRENCY', 400, 'only USD cost reports are supported; line ' . $line . ' is ' . $currency, 'export or convert the report to USD');
            }
            $date = trim((string)($record['Início dos relatórios'] ?? ''));
            $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $date) {
                fclose($stream);
                throw new YtdsOpError('INVALID_CSV', 400, 'invalid reporting date at line ' . $line . ': ' . $date, 'expected YYYY-MM-DD');
            }
            $spend = trim((string)($record['Valor gasto (USD)'] ?? ''));
            if (preg_match('/^((?:0|[1-9][0-9]*))(?:\.([0-9]{1,2}))?$/', $spend, $match) !== 1) {
                fclose($stream);
                throw new YtdsOpError('INVALID_CSV', 400, 'invalid USD spend at line ' . $line . ': ' . $spend, 'expected a non-negative amount with up to 2 decimals');
            }
            $maxDollars = (string)intdiv(self::MAX_SPEND_CENTS, 100);
            $whole = $match[1];
            if (strlen($whole) > strlen($maxDollars) || (strlen($whole) === strlen($maxDollars) && strcmp($whole, $maxDollars) > 0)) {
                fclose($stream);
                throw new YtdsOpError('INVALID_CSV', 400, 'USD spend exceeds the safety limit at line ' . $line, 'split or verify the Meta report');
            }
            $fraction = str_pad((string)($match[2] ?? ''), 2, '0');
            $cents = ((int)$whole * 100) + (int)$fraction;
            if ($cents > self::MAX_SPEND_CENTS) {
                fclose($stream);
                throw new YtdsOpError('INVALID_CSV', 400, 'USD spend exceeds the safety limit at line ' . $line, 'split or verify the Meta report');
            }
            $rows[] = [
                'date' => $date,
                'utm_campaign' => $name,
                'meta_campaign_id' => trim((string)($record['Identificação da campanha'] ?? '')),
                'currency' => 'USD',
                'spend_cents' => $cents,
                'meta_link_clicks' => self::optionalInteger($record['Cliques no link'] ?? ''),
            ];
            if (count($rows) > self::MAX_ROWS) {
                fclose($stream);
                throw new YtdsOpError('INVALID_CSV', 400, 'cost report exceeds the 10000-row limit', 'split the export into smaller files');
            }
        }
        fclose($stream);
        if ($rows === []) {
            throw new YtdsOpError('INVALID_CSV', 400, 'cost report has no campaign rows', 'blank campaign-name rows are Meta totals and are ignored');
        }
        return [
            'source' => ['name' => $sourceName, 'sha256' => hash('sha256', $raw)],
            'rows' => $rows,
        ];
    }

    private static function optionalInteger(mixed $value): ?int
    {
        $raw = trim((string)$value);
        return $raw !== '' && ctype_digit($raw) ? (int)$raw : null;
    }
}
