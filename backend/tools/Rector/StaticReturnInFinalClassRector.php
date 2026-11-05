<?php

declare(strict_types=1);

namespace QueueWallboard\Tools\Rector;

use Override;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\Class_;
use Rector\Rector\AbstractRector;

final class StaticReturnInFinalClassRector extends AbstractRector
{
    /**
     * @return array<class-string<Node>>
     */
    #[Override]
    public function getNodeTypes(): array
    {
        return [Class_::class];
    }

    #[Override]
    public function refactor(Node $node): ?Node
    {
        if (!$node instanceof Class_ || !$node->isFinal()) {
            return null;
        }

        $hasChanged = false;
        foreach ($node->getMethods() as $classMethod) {
            $returnType = $classMethod->returnType;
            $nonNullReturnType = $returnType instanceof NullableType ? $returnType->type : $returnType;
            if (!$nonNullReturnType instanceof Name || $nonNullReturnType->toLowerString() !== 'static') {
                continue;
            }

            $classMethod->returnType = $returnType instanceof NullableType
                ? new NullableType(new Name('self'))
                : new Name('self');
            $hasChanged = true;
        }

        return $hasChanged ? $node : null;
    }
}
