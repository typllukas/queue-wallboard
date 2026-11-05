<?php

declare(strict_types=1);

namespace QueueWallboard\Tests\Tools\Rector\Fixture;

final class FinalFluentClass
{
    public function setName(): static
    {
        return $this;
    }

    public function findParent(): ?static
    {
        return null;
    }

    public function findOwner(): OpenFluentClass
    {
        return new OpenFluentClass();
    }
}

class OpenFluentClass
{
    public function setName(): static
    {
        return $this;
    }
}

?>
-----
<?php

declare(strict_types=1);

namespace QueueWallboard\Tests\Tools\Rector\Fixture;

final class FinalFluentClass
{
    public function setName(): self
    {
        return $this;
    }

    public function findParent(): ?self
    {
        return null;
    }

    public function findOwner(): OpenFluentClass
    {
        return new OpenFluentClass();
    }
}

class OpenFluentClass
{
    public function setName(): static
    {
        return $this;
    }
}

?>
