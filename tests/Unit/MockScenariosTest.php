<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Payment\MockScenarios;
use PHPUnit\Framework\TestCase;

final class MockScenariosTest extends TestCase
{
    public function testHardcodedCases(): void
    {
        $s = new MockScenarios();
        foreach (['success' => ['succeeded','succeeded','succeeded'],'retry_once' => ['transient','succeeded','succeeded'],'retry_twice' => ['transient','transient','succeeded'],'failure' => ['transient','transient','transient'],'decline' => ['declined','declined','declined'],'timeout' => ['timeout','timeout','timeout']] as $fixture => $expected) {
            self::assertSame($fixture, $s->resolve($s->dto($fixture, 'Test')));
            foreach ($expected as $i => $outcome) {
                self::assertSame($outcome, $s->outcome($fixture, $i + 1));
            }
        }
    }
}
