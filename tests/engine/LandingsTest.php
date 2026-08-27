<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../code/landings.php';

final class LandingsTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ytds-landings-' . bin2hex(random_bytes(6));
        mkdir($this->baseDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->baseDir);
    }

    // ── LandingName ─────────────────────────────────────────────────────────────────

    #[DataProvider('nameProvider')]
    public function testNameValidation(string $name, bool $expected): void
    {
        self::assertSame($expected, LandingName::isValid($name));
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function nameProvider(): array
    {
        return [
            'simple' => ['honeyfil_pv', true],
            'hyphen and dot' => ['promo-v2.1', true],
            'digits' => ['12345', true],
            'empty' => ['', false],
            'single dot' => ['.', false],
            'double dot' => ['..', false],
            'slash' => ['a/b', false],
            'traversal' => ['../evil', false],
            'space' => ['my landing', false],
            'null byte' => ["a\0b", false],
        ];
    }

    // ── LandingUsage ────────────────────────────────────────────────────────────────

    public function testUsageFindsStepFolder(): void
    {
        $settings = [
            'black' => ['flows' => [
                ['name' => 'Flow 1', 'steps' => [
                    ['folders' => [['name' => 'honeyfil_pv', 'loadtype' => 'base', 'weight' => 100]]],
                    ['folders' => [['name' => 'other']]],
                ]],
            ]],
        ];
        self::assertSame(['Flow 1 — step 1'], LandingUsage::scan($settings, 'honeyfil_pv'));
    }

    public function testUsageFindsWhiteStringFolder(): void
    {
        $settings = ['white' => ['folders' => ['safe1', 'safe2']]];
        self::assertSame(['White page'], LandingUsage::scan($settings, 'safe2'));
    }

    public function testUsageFindsPerDomainWhiteFolder(): void
    {
        $settings = ['white' => ['domainfilter' => ['domains' => [
            ['domain' => 'ytds.example.com', 'folders' => ['whitedom']],
        ]]]];
        self::assertSame(['White page for ytds.example.com'], LandingUsage::scan($settings, 'whitedom'));
    }

    public function testUsageAggregatesAcrossLocationsAndDeduplicates(): void
    {
        $settings = [
            'black' => ['flows' => [
                ['name' => 'A', 'steps' => [['folders' => [['name' => 'shared']]]]],
                ['name' => 'B', 'steps' => [['folders' => [['name' => 'shared']]]]],
            ]],
            'white' => ['folders' => ['shared']],
        ];
        self::assertSame(['A — step 1', 'B — step 1', 'White page'], LandingUsage::scan($settings, 'shared'));
    }

    public function testUsageReturnsEmptyWhenUnused(): void
    {
        $settings = ['black' => ['flows' => [['name' => 'F', 'steps' => [['folders' => [['name' => 'x']]]]]]]];
        self::assertSame([], LandingUsage::scan($settings, 'not-there'));
    }

    public function testUsageHandlesMissingKeysGracefully(): void
    {
        self::assertSame([], LandingUsage::scan([], 'anything'));
    }

    // ── LandingLibrary ──────────────────────────────────────────────────────────────

    public function testAllListsFoldersWithMetadata(): void
    {
        $this->makeLanding('alpha', ['index.html' => '<h1>a</h1>', 'style.css' => 'body{}']);
        $this->makeLanding('beta', ['readme.txt' => 'no index here']);
        $lib = new LandingLibrary($this->baseDir);

        $all = $lib->all();
        self::assertCount(2, $all);
        self::assertSame('alpha', $all[0]['name']);
        self::assertSame(2, $all[0]['files']);
        self::assertTrue($all[0]['hasIndex']);
        self::assertGreaterThan(0, $all[0]['bytes']);
        self::assertSame('beta', $all[1]['name']);
        self::assertFalse($all[1]['hasIndex']);
    }

    public function testAllSkipsGitkeepAndLooseFiles(): void
    {
        file_put_contents($this->baseDir . '/.gitkeep', '');
        file_put_contents($this->baseDir . '/loose.txt', 'x');
        $this->makeLanding('real', ['index.php' => '<?php echo 1;']);
        $lib = new LandingLibrary($this->baseDir);

        self::assertSame(['real'], array_column($lib->all(), 'name'));
    }

    public function testDeleteRemovesFolderRecursively(): void
    {
        $this->makeLanding('gone', ['index.html' => 'x', 'sub/deep.js' => 'y']);
        $lib = new LandingLibrary($this->baseDir);

        self::assertTrue($lib->exists('gone'));
        self::assertTrue($lib->delete('gone'));
        self::assertFalse($lib->exists('gone'));
        self::assertDirectoryDoesNotExist($this->baseDir . '/gone');
    }

    public function testDeleteRejectsInvalidAndMissing(): void
    {
        $lib = new LandingLibrary($this->baseDir);
        self::assertFalse($lib->delete('../evil'));
        self::assertFalse($lib->delete('does-not-exist'));
    }

    public function testDeleteCannotEscapeBaseViaName(): void
    {
        // A sibling of the base dir must survive an attempted traversal delete.
        $sibling = $this->baseDir . '-sibling';
        mkdir($sibling, 0755, true);
        file_put_contents($sibling . '/keep.txt', 'keep');
        $lib = new LandingLibrary($this->baseDir);

        self::assertFalse($lib->delete('..' . DIRECTORY_SEPARATOR . basename($sibling)));
        self::assertFileExists($sibling . '/keep.txt');

        $this->removeTree($sibling);
    }

    public function testDuplicateCopiesTree(): void
    {
        $this->makeLanding('src', ['index.html' => 'hi', 'a/b.css' => 'c{}']);
        $lib = new LandingLibrary($this->baseDir);

        self::assertTrue($lib->duplicate('src', 'dst'));
        self::assertTrue($lib->exists('dst'));
        self::assertFileExists($this->baseDir . '/dst/index.html');
        self::assertFileExists($this->baseDir . '/dst/a/b.css');
        self::assertSame('hi', file_get_contents($this->baseDir . '/dst/index.html'));
    }

    public function testDuplicateRefusesExistingTarget(): void
    {
        $this->makeLanding('src', ['index.html' => 'x']);
        $this->makeLanding('taken', ['index.html' => 'y']);
        $lib = new LandingLibrary($this->baseDir);

        self::assertFalse($lib->duplicate('src', 'taken'));
        self::assertSame('y', file_get_contents($this->baseDir . '/taken/index.html'));
    }

    public function testArchivePackAndInspectRoundTrip(): void
    {
        $source = $this->baseDir . '/source';
        mkdir($source . '/assets', 0755, true);
        file_put_contents($source . '/index.html', '<h1>page</h1>');
        file_put_contents($source . '/assets/app.js', 'console.log(1)');
        file_put_contents($source . '/.DS_Store', 'ignored');
        $archive = $this->baseDir . '/page.zip';

        $packed = LandingArchive::pack($source, $archive);
        $inspected = LandingArchive::inspect($archive);

        self::assertSame(2, $packed['files']);
        self::assertSame($packed, $inspected);
        self::assertSame(hash_file('sha256', $archive), $inspected['sha256']);
        self::assertTrue($inspected['hasIndex']);
    }

    public function testArchivePackRefusesToOverwriteExistingOutput(): void
    {
        $source = $this->baseDir . '/source';
        mkdir($source, 0755, true);
        file_put_contents($source . '/index.html', 'new');
        $archive = $this->baseDir . '/existing.zip';
        file_put_contents($archive, 'keep');

        try {
            LandingArchive::pack($source, $archive);
            self::fail('existing output should not be overwritten');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('cannot create zip archive', $e->getMessage());
        }
        self::assertSame('keep', file_get_contents($archive));
    }

    public function testArchiveInspectRejectsZipSlipAndMissingRootIndex(): void
    {
        $unsafe = $this->baseDir . '/unsafe.zip';
        $zip = new ZipArchive();
        $zip->open($unsafe, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('../escape.txt', 'x');
        $zip->close();

        try {
            LandingArchive::inspect($unsafe);
            self::fail('unsafe archive should fail');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('unsafe zip entry', $e->getMessage());
        }

        $missing = $this->baseDir . '/missing-index.zip';
        $zip = new ZipArchive();
        $zip->open($missing, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('nested/index.html', 'x');
        $zip->close();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('root index');
        LandingArchive::inspect($missing);
    }

    public function testArchiveInspectRejectsSymbolicLinkEntry(): void
    {
        $archive = $this->baseDir . '/symlink.zip';
        $zip = new ZipArchive();
        $zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('index.html', 'x');
        $zip->addFromString('escape-link', '../../outside');
        $zip->setExternalAttributesName('escape-link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $zip->close();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('symbolic-link');
        LandingArchive::inspect($archive);
    }

    public function testEditableFileRejectsTraversalAndSymlink(): void
    {
        $this->makeLanding('editable', ['index.html' => 'ok']);
        $outside = $this->baseDir . '/outside.html';
        file_put_contents($outside, 'outside');
        symlink($outside, $this->baseDir . '/editable/link.html');
        $lib = new LandingLibrary($this->baseDir);

        foreach (['../outside.html', 'link.html'] as $relative) {
            try {
                $lib->editableFile('editable', $relative);
                self::fail('unsafe editable path should fail: ' . $relative);
            } catch (InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testAllHidesInternalReplacementFolders(): void
    {
        $this->makeLanding('real', ['index.html' => 'ok']);
        $this->makeLanding('.ytds_replace_deadbeef', ['index.html' => 'staging']);
        $this->makeLanding('.ytds_backup_deadbeef', ['index.html' => 'backup']);

        self::assertSame(['real'], array_column((new LandingLibrary($this->baseDir))->all(), 'name'));
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────

    /** @param array<string, string> $files relative path => content */
    private function makeLanding(string $name, array $files): void
    {
        $dir = $this->baseDir . DIRECTORY_SEPARATOR . $name;
        mkdir($dir, 0755, true);
        foreach ($files as $rel => $content) {
            $path = $dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            @mkdir(dirname($path), 0755, true);
            file_put_contents($path, $content);
        }
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
