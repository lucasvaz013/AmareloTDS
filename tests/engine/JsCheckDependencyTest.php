<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../code/main.php';

final class JsCheckDependencyTest extends TestCase
{
    public function testProductionJsCheckObfuscatorIsLoaded(): void
    {
        $this->assertTrue(class_exists(HunterObfuscator::class));
    }
}
