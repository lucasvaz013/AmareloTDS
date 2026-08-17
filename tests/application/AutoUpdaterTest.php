<?php

use PHPUnit\Framework\TestCase;

$GLOBALS['cloSettings'] = [
    'adminPassword' => 'test',
    'adminDomain' => '',
    'adminIp' => '',
    'adminPath' => 'admin',
    'dbConnection' => 'test_dummy.db',
    'backupDir' => 'backups',
    'useUTP' => false,
    'debug' => false,
    'cachingDir' => 'caching',
    'plugins' => [
        'currency' => [
            'items' => [
                'frankfurter' => ['enabled' => true, 'preferredCurrencies' => []],
                'turkish' => ['enabled' => true, 'preferredCurrencies' => ['RUB', 'THB']],
            ],
        ],
        'vpn' => [
            'mode' => 'any',
            'items' => [
                'blackbox' => ['enabled' => true],
                'ipintel' => ['enabled' => true],
            ],
        ],
    ],
];

$_SERVER['SCRIPT_FILENAME'] = __FILE__;
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['SCRIPT_NAME'] = '/e3c80abc/autoupdate.php';
$_SERVER['PHP_SELF'] = '/e3c80abc/autoupdate.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['QUERY_STRING'] = '';
$_SERVER['REQUEST_URI'] = '/e3c80abc/autoupdate.php';

require_once __DIR__ . '/../../code/admin/autoupdate.php';

class AutoUpdaterTest extends TestCase
{
    private string $tempRoot;

    protected function setUp(): void
    {
        $this->tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'amarelotds_updater_' . bin2hex(random_bytes(4));
        mkdir($this->tempRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempRoot);
    }

    public function testUpdateMapsSourceAdminToConfiguredAdminPath(): void
    {
        $target = $this->tempRoot . DIRECTORY_SEPARATOR . 'target';
        $source = $this->tempRoot . DIRECTORY_SEPARATOR . 'source';
        mkdir($target, 0755, true);
        mkdir($source . DIRECTORY_SEPARATOR . 'admin', 0755, true);
        mkdir($target . DIRECTORY_SEPARATOR . 'e3c80abc', 0755, true);

        file_put_contents($target . DIRECTORY_SEPARATOR . 'settings.php', '<?php $cloSettings = ["adminPath" => "e3c80abc"];');
        foreach (['autoupdate.php', 'version.txt', 'login.php', 'index.php'] as $file) {
            file_put_contents($source . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . $file, $file . ' updated');
        }
        file_put_contents($source . DIRECTORY_SEPARATOR . 'settings.php', '<?php $cloSettings = ["adminPath" => "admin"];');

        $updater = new AutoUpdater();
        $updater->applyExtractedUpdate($source, $target);

        $this->assertFileExists($target . DIRECTORY_SEPARATOR . 'e3c80abc' . DIRECTORY_SEPARATOR . 'version.txt');
        $this->assertSame(
            'version.txt updated',
            file_get_contents($target . DIRECTORY_SEPARATOR . 'e3c80abc' . DIRECTORY_SEPARATOR . 'version.txt')
        );
        $this->assertDirectoryDoesNotExist($target . DIRECTORY_SEPARATOR . 'admin');
        $local = include $target . DIRECTORY_SEPARATOR . 'settings.local.php';
        $this->assertSame('e3c80abc', $local['adminPath']);
        $this->assertSame('<?php $cloSettings = ["adminPath" => "admin"];', file_get_contents($target . DIRECTORY_SEPARATOR . 'settings.php'));
    }

    public function testUpdateSkipsRuntimePaths(): void
    {
        $target = $this->tempRoot . DIRECTORY_SEPARATOR . 'target';
        $source = $this->tempRoot . DIRECTORY_SEPARATOR . 'source';
        mkdir($target, 0755, true);
        mkdir($source . DIRECTORY_SEPARATOR . 'admin', 0755, true);
        mkdir($source . DIRECTORY_SEPARATOR . 'logs', 0755, true);
        mkdir($source . DIRECTORY_SEPARATOR . 'tmp', 0755, true);
        mkdir($source . DIRECTORY_SEPARATOR . 'temp_update', 0755, true);
        mkdir($source . DIRECTORY_SEPARATOR . 'backups', 0755, true);
        mkdir($source . DIRECTORY_SEPARATOR . 'db', 0755, true);
        mkdir($source . DIRECTORY_SEPARATOR . 'caching' . DIRECTORY_SEPARATOR . 'devices', 0755, true);

        file_put_contents($target . DIRECTORY_SEPARATOR . 'settings.php', '<?php $cloSettings = ["adminPath" => "e3c80abc"];');
        foreach (['autoupdate.php', 'version.txt', 'login.php', 'index.php'] as $file) {
            file_put_contents($source . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . $file, $file);
        }
        file_put_contents($source . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'update.log', 'log');
        file_put_contents($source . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'cache.tmp', 'tmp');
        file_put_contents($source . DIRECTORY_SEPARATOR . 'temp_update' . DIRECTORY_SEPARATOR . 'update.zip', 'zip');
        file_put_contents($source . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'settings.php', 'backup');
        file_put_contents($source . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'clicks.db', 'db');
        file_put_contents($source . DIRECTORY_SEPARATOR . 'caching' . DIRECTORY_SEPARATOR . 'devices' . DIRECTORY_SEPARATOR . 'device.cache', 'cache');

        $updater = new AutoUpdater();
        $updater->applyExtractedUpdate($source, $target);

        $this->assertDirectoryDoesNotExist($target . DIRECTORY_SEPARATOR . 'logs');
        $this->assertDirectoryDoesNotExist($target . DIRECTORY_SEPARATOR . 'tmp');
        $this->assertDirectoryDoesNotExist($target . DIRECTORY_SEPARATOR . 'temp_update');
        $this->assertDirectoryDoesNotExist($target . DIRECTORY_SEPARATOR . 'backups');
        $this->assertFileDoesNotExist($target . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'clicks.db');
        $this->assertDirectoryDoesNotExist($target . DIRECTORY_SEPARATOR . 'caching' . DIRECTORY_SEPARATOR . 'devices');
    }

    public function testUpdatePreservesExistingLocalSettings(): void
    {
        $target = $this->tempRoot . DIRECTORY_SEPARATOR . 'target';
        $source = $this->tempRoot . DIRECTORY_SEPARATOR . 'source';
        mkdir($target . DIRECTORY_SEPARATOR . 'e3c80abc', 0755, true);
        mkdir($source . DIRECTORY_SEPARATOR . 'admin', 0755, true);
        file_put_contents($target . DIRECTORY_SEPARATOR . 'settings.php', '<?php $cloSettings = ["adminPath" => "admin"];');
        file_put_contents($target . DIRECTORY_SEPARATOR . 'settings.local.php', '<?php return ["_revision" => 7, "adminPath" => "e3c80abc"];');
        file_put_contents($source . DIRECTORY_SEPARATOR . 'settings.php', '<?php $cloSettings = ["adminPath" => "admin"];');
        file_put_contents($source . DIRECTORY_SEPARATOR . 'settings.local.php', '<?php return ["_revision" => 1, "adminPath" => "wrong"];');
        foreach (['autoupdate.php', 'version.txt', 'login.php', 'index.php'] as $file) {
            file_put_contents($source . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . $file, $file);
        }

        (new AutoUpdater())->applyExtractedUpdate($source, $target);

        $local = include $target . DIRECTORY_SEPARATOR . 'settings.local.php';
        $this->assertSame(7, $local['_revision']);
        $this->assertSame('e3c80abc', $local['adminPath']);
    }

    public function testRepositoryUpdateTargetsDistributionSubdirectory(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../../code/admin/autoupdate.php');

        $this->assertStringContainsString("contents/code/admin/version.txt?ref='", $source);
        $this->assertStringContainsString("\$extractedDir . DIRECTORY_SEPARATOR . 'code'", $source);
        $this->assertStringContainsString('Downloaded update does not contain the code directory', $source);
    }

    public function testUpdateSourceFollowsInstanceSettings(): void
    {
        $original = $GLOBALS['cloSettings'];

        try {
            $GLOBALS['cloSettings']['updateRepo'] = 'lucasvaz013/AmareloTDS';
            $GLOBALS['cloSettings']['updateBranch'] = 'staging';
            $this->assertSame('lucasvaz013/AmareloTDS@staging', (new AutoUpdater())->getUpdateSource());

            $GLOBALS['cloSettings']['updateBranch'] = '../../etc/passwd';
            $this->assertSame('lucasvaz013/AmareloTDS@production', (new AutoUpdater())->getUpdateSource());
        } finally {
            $GLOBALS['cloSettings'] = $original;
        }
    }

    /**
     * @dataProvider versionOrderingProvider
     */
    public function testVersionOrdering(string $older, string $newer): void
    {
        $convert = new ReflectionMethod(AutoUpdater::class, 'convertVersionToTimestamp');
        $updater = new AutoUpdater();

        $this->assertLessThan(
            $convert->invoke($updater, $newer),
            $convert->invoke($updater, $older),
            "$older should rank below $newer"
        );
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function versionOrderingProvider(): array
    {
        return [
            'build beats no build on the same day' => ['06.08.26', '06.08.26.1'],
            'builds increase within the day' => ['06.08.26.1', '06.08.26.2'],
            'highest build still loses to the next day' => ['06.08.26.999', '07.08.26'],
            'next month beats end of previous' => ['31.08.26.5', '01.09.26'],
            'next year beats end of previous' => ['31.12.26.9', '01.01.27'],
            'legacy three-part versions still compare' => ['05.08.26', '06.08.26'],
        ];
    }

    /**
     * @dataProvider invalidVersionProvider
     */
    public function testInvalidVersionsAreRejected(string $version): void
    {
        $convert = new ReflectionMethod(AutoUpdater::class, 'convertVersionToTimestamp');

        $this->expectException(Exception::class);
        $convert->invoke(new AutoUpdater(), $version);
    }

    /** @return array<string, array{0: string}> */
    public static function invalidVersionProvider(): array
    {
        return [
            'too few parts' => ['06.08'],
            'too many parts' => ['06.08.26.1.4'],
            'four digit year' => ['06.08.2026'],
            'build too long' => ['06.08.26.1234'],
            'not numeric' => ['06.08.2a'],
            'empty' => [''],
        ];
    }

    public function testUpdateSkipsConfiguredBackupDirectory(): void
    {
        $target = $this->tempRoot . DIRECTORY_SEPARATOR . 'target';
        $source = $this->tempRoot . DIRECTORY_SEPARATOR . 'source';
        mkdir($target . DIRECTORY_SEPARATOR . 'e3c80abc', 0755, true);
        mkdir($source . DIRECTORY_SEPARATOR . 'admin', 0755, true);
        mkdir($source . DIRECTORY_SEPARATOR . 'restore-points', 0755, true);
        file_put_contents($target . DIRECTORY_SEPARATOR . 'settings.php', '<?php $cloSettings = ["adminPath" => "admin"];');
        file_put_contents($target . DIRECTORY_SEPARATOR . 'settings.local.php', '<?php return ["_revision" => 1, "adminPath" => "e3c80abc", "backupDir" => "restore-points"];');
        foreach (['autoupdate.php', 'version.txt', 'login.php', 'index.php'] as $file) {
            file_put_contents($source . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . $file, $file);
        }
        file_put_contents($source . DIRECTORY_SEPARATOR . 'restore-points' . DIRECTORY_SEPARATOR . 'should-not-copy.zip', 'backup');

        (new AutoUpdater())->applyExtractedUpdate($source, $target);

        $this->assertDirectoryDoesNotExist($target . DIRECTORY_SEPARATOR . 'restore-points');
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
