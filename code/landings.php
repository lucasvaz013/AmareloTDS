<?php

/**
 * Landings library — the standalone half of what a Flow step already does when it uploads a
 * ZIP or picks an existing folder. A "landing" is nothing more than a directory under
 * caching/landings/<name>: there is no database row and no registry, so everything here works
 * off the filesystem and off campaign settings loaded elsewhere. The admin endpoint
 * (landingseditor.php) wires these classes to $db and the real cache directory; the pure
 * pieces are kept pure so the engine test suite can exercise them directly.
 */

final class LandingName
{
    /** The exact charset the ZIP uploader and file editor already enforce for folder names. */
    public static function isValid(string $name): bool
    {
        if ($name === '' || $name === '.' || $name === '..') {
            return false;
        }
        return preg_match('/^[a-zA-Z0-9_\-\.]+$/', $name) === 1;
    }
}

/**
 * Finds every place a campaign points at a landing folder, so a folder can be deleted with the
 * operator knowing exactly what will break. Mirrors the two shapes references take: steps store
 * objects ({name, loadtype, weight, mvt} — see flows/collectors.js) while white pages store
 * plain strings.
 */
final class LandingUsage
{
    /**
     * @param array<string,mixed> $settings one campaign's decoded settings
     * @return list<string> human-readable locations, empty when the folder is unused
     */
    public static function scan(array $settings, string $folder): array
    {
        $usages = [];

        foreach (($settings['black']['flows'] ?? []) as $flow) {
            if (!is_array($flow)) {
                continue;
            }
            $flowName = trim((string)($flow['name'] ?? '')) ?: 'Unnamed flow';
            foreach (($flow['steps'] ?? []) as $stepIndex => $step) {
                if (is_array($step) && self::foldersContain($step['folders'] ?? [], $folder)) {
                    $usages[] = $flowName . ' — step ' . ((int)$stepIndex + 1);
                }
            }
        }

        if (self::foldersContain($settings['white']['folders'] ?? [], $folder)) {
            $usages[] = 'White page';
        }

        foreach (($settings['white']['domainfilter']['domains'] ?? []) as $domain) {
            if (is_array($domain) && self::foldersContain($domain['folders'] ?? [], $folder)) {
                $label = trim((string)($domain['domain'] ?? $domain['name'] ?? ''));
                $usages[] = 'White page' . ($label !== '' ? ' for ' . $label : '');
            }
        }

        return array_values(array_unique($usages));
    }

    /** Folder lists appear both as [{name: ..}] (steps) and as ["name"] (white). */
    private static function foldersContain(mixed $folders, string $folder): bool
    {
        if (!is_array($folders)) {
            return false;
        }
        foreach ($folders as $entry) {
            $name = is_array($entry) ? (string)($entry['name'] ?? '') : (string)$entry;
            if ($name === $folder) {
                return true;
            }
        }
        return false;
    }
}

/**
 * Filesystem operations on the landings cache directory. The base directory is injected so the
 * same code runs against the real cache in production and a temp directory under test. Every
 * mutating call revalidates the name and confirms the resolved path stays inside the base, so a
 * crafted name can never reach a sibling directory.
 */
final class LandingLibrary
{
    public function __construct(private string $baseDir)
    {
    }

    /** @return list<array{name:string,files:int,bytes:int,mtime:int,hasIndex:bool}> */
    public function all(): array
    {
        $items = @scandir($this->baseDir);
        if ($items === false) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === '.gitkeep') {
                continue;
            }
            if (is_dir($this->baseDir . DIRECTORY_SEPARATOR . $item)) {
                $out[] = $this->describe($item);
            }
        }
        usort($out, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $out;
    }

    /** @return array{name:string,files:int,bytes:int,mtime:int,hasIndex:bool} */
    public function describe(string $name): array
    {
        $dir = $this->pathOf($name);
        $files = 0;
        $bytes = 0;
        try {
            if (is_dir($dir)) {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
                );
                foreach ($it as $file) {
                    if ($file->isFile()) {
                        $files++;
                        $bytes += (int)$file->getSize();
                    }
                }
            }
        } catch (\Throwable $e) {
            // A landing with unreadable contents still lists; the metrics just stay partial.
        }
        return [
            'name' => $name,
            'files' => $files,
            'bytes' => $bytes,
            'mtime' => (int)(@filemtime($dir) ?: 0),
            'hasIndex' => $this->hasIndex($dir),
        ];
    }

    public function exists(string $name): bool
    {
        return LandingName::isValid($name) && is_dir($this->pathOf($name));
    }

    /** A landing without a root index.* 404s at runtime, so the UI flags it. */
    public function hasIndex(string $dir): bool
    {
        foreach (['index.php', 'index.html', 'index.htm'] as $index) {
            if (is_file($dir . DIRECTORY_SEPARATOR . $index)) {
                return true;
            }
        }
        return false;
    }

    /** Recursively removes a landing. Returns false if the name is invalid or escapes the base. */
    public function delete(string $name): bool
    {
        if (!$this->exists($name)) {
            return false;
        }
        $dir = $this->pathOf($name);
        if (!$this->within($dir)) {
            return false;
        }
        return $this->removeTree($dir);
    }

    /** Copies an existing landing to a new name. Returns false on any name or collision problem. */
    public function duplicate(string $from, string $to): bool
    {
        if (!$this->exists($from) || !LandingName::isValid($to) || $this->exists($to)) {
            return false;
        }
        $src = $this->pathOf($from);
        $dst = $this->pathOf($to);
        if (!$this->within($src) || !$this->within($dst)) {
            return false;
        }
        return $this->copyTree($src, $dst);
    }

    /** Validates a ZIP upload without extracting: name, collision, readable archive, no zip-slip. */
    public function validateUpload(string $name, string $zipPath): ?string
    {
        if (!LandingName::isValid($name)) {
            return 'invalid landing name';
        }
        if ($this->exists($name)) {
            return 'landing already exists: ' . $name;
        }
        if (!is_file($zipPath)) {
            return 'zip file is not readable';
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return 'cannot open zip archive';
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = str_replace('\\', '/', (string)$zip->getNameIndex($i));
            if ($entry === '' || str_starts_with($entry, '/') || preg_match('#(^|/)\.\.(/|$)#', $entry) === 1) {
                $zip->close();
                return 'unsafe zip entry: ' . $entry;
            }
        }
        $zip->close();
        return null;
    }

    /** Extracts a validated ZIP into a new landing folder. Returns null on success or an error string. */
    public function uploadZip(string $name, string $zipPath): ?string
    {
        $error = $this->validateUpload($name, $zipPath);
        if ($error !== null) {
            return $error;
        }
        $dir = $this->pathOf($name);
        if (!$this->within($dir)) {
            return 'resolved path escapes the landings directory';
        }
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return 'cannot create landing folder';
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return 'cannot open zip archive';
        }
        $ok = $zip->extractTo($dir);
        $zip->close();
        if (!$ok) {
            $this->removeTree($dir);
            return 'extraction failed';
        }
        return null;
    }

    private function pathOf(string $name): string
    {
        return $this->baseDir . DIRECTORY_SEPARATOR . $name;
    }

    /** Guards against a resolved path leaving the landings directory (symlinks, `..`). */
    private function within(string $path): bool
    {
        $base = realpath($this->baseDir);
        if ($base === false) {
            return false;
        }
        $real = realpath($path);
        if ($real !== false) {
            return $real === $base || str_starts_with($real, $base . DIRECTORY_SEPARATOR);
        }
        // Destination does not exist yet (duplicate target): validate its parent instead.
        $parent = realpath(dirname($path));
        return $parent !== false
            && ($parent === $base || str_starts_with($parent, $base . DIRECTORY_SEPARATOR));
    }

    private function removeTree(string $dir): bool
    {
        $items = @scandir($dir);
        if ($items === false) {
            return false;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path) && !is_link($path)) {
                if (!$this->removeTree($path)) {
                    return false;
                }
            } elseif (!@unlink($path)) {
                return false;
            }
        }
        return @rmdir($dir);
    }

    private function copyTree(string $src, string $dst): bool
    {
        if (!@mkdir($dst, 0755, true) && !is_dir($dst)) {
            return false;
        }
        $items = @scandir($src);
        if ($items === false) {
            return false;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $from = $src . DIRECTORY_SEPARATOR . $item;
            $to = $dst . DIRECTORY_SEPARATOR . $item;
            if (is_dir($from) && !is_link($from)) {
                if (!$this->copyTree($from, $to)) {
                    return false;
                }
            } elseif (!@copy($from, $to)) {
                return false;
            }
        }
        return true;
    }
}
