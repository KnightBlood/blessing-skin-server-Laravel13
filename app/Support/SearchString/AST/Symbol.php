<?php

namespace App\Support\SearchString\AST;

use App\Support\SearchString\Visitors\Visitor;

abstract class Symbol
{
    abstract public function accept(Visitor $visitor);
}
