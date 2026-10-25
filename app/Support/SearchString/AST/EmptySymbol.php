<?php

namespace App\Support\SearchString\AST;

use App\Support\SearchString\Visitors\Visitor;

class EmptySymbol extends Symbol
{
    public function accept(Visitor $visitor)
    {
        return $visitor->visitEmpty($this);
    }
}
