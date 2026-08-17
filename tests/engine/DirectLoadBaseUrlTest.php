<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../code/htmlprocessing.php';

final class DirectLoadBaseUrlTest extends TestCase
{
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    public function testRootInstallKeepsLeadingSlashOnDirectLoadBase(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $html = '<head></head><img src="/hero.webp">';
        $html = fix_head_add_base($html, get_directload_step_url('click-1', 0));
        $html = fix_root_relative_urls($html);

        $this->assertStringContainsString("<base href='/__dl/click-1/0/'>", $html);
        $this->assertStringNotContainsString("href='__dl/", $html);
        $this->assertStringContainsString('src="hero.webp"', $html);
    }

    public function testSubdirectoryInstallKeepsTdsPrefixOnDirectLoadBase(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/tds/index.php';

        $html = '<head></head><img src="/hero.webp">';
        $html = fix_head_add_base($html, get_directload_step_url('click-2', 0));
        $html = fix_root_relative_urls($html);

        $this->assertStringContainsString("<base href='/tds/__dl/click-2/0/'>", $html);
        $this->assertStringContainsString('src="hero.webp"', $html);
    }
}
