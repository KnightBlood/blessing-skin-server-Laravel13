<?php

namespace App\Support\SearchString\AST;

use App\Support\SearchString\Visitors\Visitor;

class NotSymbol extends Symbol
{
    /** @var Symbol */
    public $expression;

    function __construct(Symbol $expression)
    {
        $this->expression = $expression;
    }

    public function accept(Visitor $visitor)
    {
        return $visitor->visitNot($this);
    }
}
