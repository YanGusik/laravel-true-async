<?php

namespace Spawn\Laravel\Tests\PHPStan\Fixtures;

class MutableStaticProperties
{
    // Should be flagged — mutable static property
    private static array $cache = []; // line 9

    // Should be flagged — mutable static, no default
    protected static ?string $instance = null; // line 12

    // Should be flagged — public mutable static
    public static int $counter = 0; // line 15

    // Should NOT be flagged — readonly static (PHP 8.2+)
    // public static readonly string $name = 'test';

    // Should NOT be flagged — constant
    public const VERSION = '1.0';

    // Should NOT be flagged — non-static property (instance state is OK)
    private array $items = [];

    // Should NOT be flagged — non-static property
    protected string $title = '';
}

class SafeClass
{
    // Should NOT be flagged — non-static
    private int $count = 0;
    public string $name = '';

    public const TYPE = 'safe';
}

class StaticReadonlyClass
{
    // Should NOT be flagged — readonly
    public static readonly string $label;

    // Should be flagged — mutable static
    private static bool $booted = false; // line 42
}
