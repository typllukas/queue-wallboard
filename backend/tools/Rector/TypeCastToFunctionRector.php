<?php

declare(strict_types=1);

namespace QueueWallboard\Tools\Rector;

use Override;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Cast\Double;
use PhpParser\Node\Expr\Cast\Int_;
use PhpParser\Node\Expr\Cast\String_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use Rector\Rector\AbstractRector;

/**
 * (bool) is left alone: what replaces it is an explicit comparison, which a rule cannot pick.
 */
final class TypeCastToFunctionRector extends AbstractRector
{
    /**
     * @return array<class-string<Node>>
     */
    #[Override]
    public function getNodeTypes(): array
    {
        return [Int_::class, String_::class, Double::class];
    }

    #[Override]
    public function refactor(Node $node): ?Node
    {
        $functionName = match (true) {
            $node instanceof Int_ => 'intval',
            $node instanceof String_ => 'strval',
            $node instanceof Double => 'floatval',
            default => null,
        };

        return $functionName === null ? null : new FuncCall(new Name($functionName), [new Arg($node->expr)]);
    }
}
