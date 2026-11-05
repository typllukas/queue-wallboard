<?php

declare(strict_types=1);

namespace QueueWallboard\Tests\Tools\Rector\Fixture;

final class CastingClass
{
    public function convert(int $count, string $digits, float $seconds, ?int $flag): array
    {
        return [(string) $count, (int) $digits, (int) round($seconds * 1000), (float) $digits, (bool) $flag];
    }
}

?>
-----
<?php

declare(strict_types=1);

namespace QueueWallboard\Tests\Tools\Rector\Fixture;

final class CastingClass
{
    public function convert(int $count, string $digits, float $seconds, ?int $flag): array
    {
        return [strval($count), intval($digits), intval(round($seconds * 1000)), floatval($digits), (bool) $flag];
    }
}

?>
