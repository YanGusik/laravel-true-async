<?php

namespace Spawn\Laravel\Tests\PHPStan\Fixtures;

class SafeOnly
{
    private int $count = 0;
    public string $name = '';
    public const TYPE = 'safe';
    public static readonly string $label;
}
