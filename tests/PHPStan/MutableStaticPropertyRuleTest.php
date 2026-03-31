<?php

namespace Spawn\Laravel\Tests\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Spawn\Laravel\PHPStan\MutableStaticPropertyRule;

/**
 * @extends RuleTestCase<MutableStaticPropertyRule>
 */
class MutableStaticPropertyRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new MutableStaticPropertyRule();
    }

    public function test_detects_mutable_static_properties(): void
    {
        $ns = 'Spawn\\Laravel\\Tests\\PHPStan\\Fixtures';
        $this->analyse([__DIR__ . '/Fixtures/MutableStaticProperties.php'], [
            ["Class {$ns}\\MutableStaticProperties has mutable static property \$cache — potential coroutine state leak.", 8],
            ["Class {$ns}\\MutableStaticProperties has mutable static property \$instance — potential coroutine state leak.", 11],
            ["Class {$ns}\\MutableStaticProperties has mutable static property \$counter — potential coroutine state leak.", 14],
            ["Class {$ns}\\StaticReadonlyClass has mutable static property \$booted — potential coroutine state leak.", 44],
        ]);
    }

    public function test_no_errors_on_safe_class(): void
    {
        $this->analyse([__DIR__ . '/Fixtures/SafeOnly.php'], []);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [];
    }
}
