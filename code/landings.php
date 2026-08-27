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

/** ZIP creation and validation shared by CLI utilities and remote landing operations. */
final class LandingArchive
{
    public const MAX_FILES = 20000;
    public const MAX_UNCOMPRESSED_BYTES = 2147483648;

    /** @return array{files:int,bytes:int,sha256:string,hasIndex:bool} */
    public static function inspect(string $zipPath, bool $requireIndex = true): array
    {
        if (!is_file($zipPath) || !is_readable($zipPath)) {
            throw new InvalidArgumentException('zip file is not readable');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new InvalidArgumentException('cannot open zip archive');
        }
        $files = 0;
        $uncompressedBytes = 0;
        $hasIndex = false;
        $seen = [];
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = str_replace('\\', '/', (string)$zip->getNameIndex($i));
                if ($entry === '' || str_starts_with($entry, '/') || preg_match('#(^|/)\.\.(/|$)#', $entry) === 1) {
                    throw new InvalidArgumentException('unsafe zip entry: ' . $entry);
                }
                $normalized = rtrim($entry, '/');
                if ($normalized === '') {
                    continue;
                }
                $portableKey = strtolower($normalized);
                if (isset($seen[$portableKey])) {
                    throw new InvalidArgumentException('duplicate zip entry: ' . $normalized);
                }
                $seen[$portableKey] = true;
                $opsys = 0;
                $attributes = 0;
                if ($zip->getExternalAttributesIndex($i, $opsys, $attributes)
                    && (($attributes >> 16) & 0170000) === 0120000) {
                    throw new InvalidArgumentException('symbolic-link zip entry is not allowed: ' . $entry);
                }
                if (!str_ends_with($entry, '/')) {
                    $files++;
                    $stat = $zip->statIndex($i);
                    if ($stat === false) {
                        throw new InvalidArgumentException('cannot inspect zip entry: ' . $entry);
                    }
                    $uncompressedBytes += max(0, (int)($stat['size'] ?? 0));
                    if ($files > self::MAX_FILES) {
                        throw new InvalidArgumentException('zip archive exceeds the ' . self::MAX_FILES . ' file limit');
                    }
                    if ($uncompressedBytes > self::MAX_UNCOMPRESSED_BYTES) {
                        throw new InvalidArgumentException('zip archive exceeds the 2 GiB uncompressed limit');
                    }
                    if (in_array($entry, ['index.php', 'index.html', 'index.htm'], true)) {
                        $hasIndex = true;
                    }
                }
            }
        } finally {
            $zip->close();
        }
        if ($files === 0) {
            throw new InvalidArgumentException('zip archive contains no files');
        }
        if ($requireIndex && !$hasIndex) {
            throw new InvalidArgumentException('zip archive has no root index.php, index.html, or index.htm');
        }
        $bytes = filesize($zipPath);
        $sha = hash_file('sha256', $zipPath);
        if ($bytes === false || $sha === false) {
            throw new RuntimeException('cannot checksum zip archive');
        }
        return ['files' => $files, 'bytes' => (int)$bytes, 'sha256' => $sha, 'hasIndex' => $hasIndex];
    }

    /** @return array{files:int,bytes:int,sha256:string,hasIndex:bool} */
    public static function pack(string $sourceDir, string $zipPath, bool $overwrite = false): array
    {
        $source = realpath($sourceDir);
        if ($source === false || !is_dir($source)) {
            throw new InvalidArgumentException('landing source directory not found: ' . $sourceDir);
        }
        $outputParent = realpath(dirname($zipPath));
        if ($outputParent === false || !is_dir($outputParent)) {
            throw new InvalidArgumentException('zip output directory not found: ' . dirname($zipPath));
        }
        $output = $outputParent . DIRECTORY_SEPARATOR . basename($zipPath);
        $zip = new ZipArchive();
        $flags = ZipArchive::CREATE | ($overwrite ? ZipArchive::OVERWRITE : ZipArchive::EXCL);
        if ($zip->open($output, $flags) !== true) {
            throw new RuntimeException('cannot create zip archive: ' . $zipPath);
        }
        $open = true;
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($it as $file) {
                if (!$file->isFile() || $file->isLink()) {
                    continue;
                }
                $real = $file->getRealPath();
                if ($real === false || $real === $output) {
                    continue;
                }
                if (!str_starts_with($real, $source . DIRECTORY_SEPARATOR)) {
                    throw new RuntimeException('source file escapes the landing directory: ' . $real);
                }
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($real, strlen($source) + 1));
                if (basename($relative) === '.DS_Store' || str_starts_with($relative, '__MACOSX/')) {
                    continue;
                }
                if (!$zip->addFile($real, $relative)) {
                    throw new RuntimeException('cannot add file to zip: ' . $relative);
                }
            }
            if (!$zip->close()) {
                throw new RuntimeException('cannot finalize zip archive: ' . $zipPath);
            }
            $open = false;
            return self::inspect($output);
        } catch (Throwable $e) {
            if ($open) {
                @$zip->close();
            }
            @unlink($output);
            throw $e;
        }
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
            if ($item === '.' || $item === '..' || $item === '.gitkeep'
                || str_starts_with($item, '.ytds_replace_') || str_starts_with($item, '.ytds_backup_')) {
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
        try {
            LandingArchive::inspect($zipPath, false);
        } catch (Throwable $e) {
            return $e->getMessage();
        }
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
        if (!$this->validateExtractedTree($dir)) {
            $this->removeTree($dir);
            return 'extracted landing contains an unsafe path or symbolic link';
        }
        return null;
    }

    /** @return array{files:int,bytes:int,sha256:string,hasIndex:bool} */
    public function archive(string $name, string $zipPath): array
    {
        if (!$this->exists($name)) {
            throw new InvalidArgumentException('landing not found: ' . $name);
        }
        return LandingArchive::pack($this->pathOf($name), $zipPath, true);
    }

    /** Resolves an existing regular file without allowing traversal or symlink hops. */
    public function editableFile(string $name, string $relative): string
    {
        if (!$this->exists($name)) {
            throw new InvalidArgumentException('landing not found: ' . $name);
        }
        $relative = str_replace('\\', '/', trim($relative));
        if ($relative === '' || str_starts_with($relative, '/') || preg_match('#(^|/)\.\.(/|$)#', $relative) === 1) {
            throw new InvalidArgumentException('invalid landing file path: ' . $relative);
        }
        $landing = realpath($this->pathOf($name));
        $path = realpath($this->pathOf($name) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
        if ($landing === false || $path === false || !is_file($path) || is_link($path)
            || !str_starts_with($path, $landing . DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException('landing file not found: ' . $relative);
        }
        $cursor = $landing;
        foreach (explode('/', $relative) as $part) {
            $cursor .= DIRECTORY_SEPARATOR . $part;
            if (is_link($cursor)) {
                throw new InvalidArgumentException('landing file path contains a symlink: ' . $relative);
            }
        }
        return $path;
    }

    public function writeFileAtomic(string $path, string $content): void
    {
        $tmp = tempnam(dirname($path), '.ytds_edit_');
        if ($tmp === false || file_put_contents($tmp, $content, LOCK_EX) === false) {
            if ($tmp !== false) {
                @unlink($tmp);
            }
            throw new RuntimeException('cannot write temporary landing file');
        }
        @chmod($tmp, (int)(fileperms($path) & 0777));
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('cannot replace landing file atomically');
        }
    }

    /** @return array{files:int,bytes:int,sha256:string,hasIndex:bool} */
    public function replaceZip(string $name, string $zipPath): array
    {
        if (!$this->exists($name)) {
            throw new InvalidArgumentException('landing not found: ' . $name);
        }
        $meta = LandingArchive::inspect($zipPath);
        $target = $this->pathOf($name);
        $suffix = bin2hex(random_bytes(6));
        $staging = $this->baseDir . DIRECTORY_SEPARATOR . '.ytds_replace_' . $suffix;
        $backup = $this->baseDir . DIRECTORY_SEPARATOR . '.ytds_backup_' . $suffix;
        if (!@mkdir($staging, 0755, true)) {
            throw new RuntimeException('cannot create replacement staging folder');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            $this->removeTree($staging);
            throw new RuntimeException('cannot open replacement archive');
        }
        $extracted = $zip->extractTo($staging);
        $zip->close();
        if (!$extracted) {
            $this->removeTree($staging);
            throw new RuntimeException('replacement extraction failed');
        }
        if (!$this->validateExtractedTree($staging)) {
            $this->removeTree($staging);
            throw new RuntimeException('replacement contains an unsafe extracted path or symbolic link');
        }
        if (!@rename($target, $backup)) {
            $this->removeTree($staging);
            throw new RuntimeException('cannot stage existing landing for replacement');
        }
        if (!@rename($staging, $target)) {
            @rename($backup, $target);
            $this->removeTree($staging);
            throw new RuntimeException('cannot install replacement landing');
        }
        $meta['cleanup_pending'] = !$this->removeTree($backup);
        return $meta;
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

    /** Defense in depth after ZipArchive::extractTo(): every resulting path stays in root. */
    private function validateExtractedTree(string $dir): bool
    {
        $root = realpath($dir);
        if ($root === false) {
            return false;
        }
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $entry) {
                if ($entry->isLink()) {
                    return false;
                }
                $real = $entry->getRealPath();
                if ($real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                    return false;
                }
            }
        } catch (Throwable $e) {
            return false;
        }
        return true;
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
