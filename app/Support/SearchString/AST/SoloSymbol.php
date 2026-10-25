<?php

namespace App\Support\SearchString\AST;

use App\Support\SearchString\Visitors\Visitor;

class SoloSymbol extends Symbol
{
    use CanHaveRule;
    use CanBeNegated;

    /** @var string */
    public $content;

    function __construct(string $content)
    {
        $this->content = $content;
    }

    public function accept(Visitor $visitor)
    {
        return $visitor->visitSolo($this);
    }
}
