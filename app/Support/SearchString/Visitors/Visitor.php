<?php

namespace App\Support\SearchString\Visitors;

use App\Support\SearchString\AST\AndSymbol;
use App\Support\SearchString\AST\ListSymbol;
use App\Support\SearchString\AST\NotSymbol;
use App\Support\SearchString\AST\EmptySymbol;
use App\Support\SearchString\AST\OrSymbol;
use App\Support\SearchString\AST\QuerySymbol;
use App\Support\SearchString\AST\RelationshipSymbol;
use App\Support\SearchString\AST\SearchSymbol;
use App\Support\SearchString\AST\SoloSymbol;

abstract class Visitor
{
    public function visitOr(OrSymbol $or)
    {
        return new OrSymbol($or->expressions->map->accept($this));
    }

    public function visitAnd(AndSymbol $and)
    {
        return new AndSymbol($and->expressions->map->accept($this));
    }

    public function visitNot(NotSymbol $not)
    {
        return new NotSymbol($not->expression->accept($this));
    }

    public function visitRelationship(RelationshipSymbol $relationship)
    {
        $relationship->expression = $relationship->expression->accept($this);

        return $relationship;
    }

    public function visitSolo(SoloSymbol $solo)
    {
        return $solo;
    }

    public function visitQuery(QuerySymbol $query)
    {
        return $query;
    }

    public function visitList(ListSymbol $list)
    {
        return $list;
    }

    public function visitEmpty(EmptySymbol $empty)
    {
        return $empty;
    }
}
