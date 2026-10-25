<?php

namespace App\Support\SearchString\Visitors;

use App\Support\SearchString\AST\EmptySymbol;
use App\Support\SearchString\AST\ListSymbol;
use App\Support\SearchString\AST\QuerySymbol;
use App\Support\SearchString\AST\RelationshipSymbol;
use App\Support\SearchString\Options\KeywordRule;

class RemoveKeywordsVisitor extends Visitor
{
    public function visitRelationship(RelationshipSymbol $relationship)
    {
        // Keywords are not allowed within relationships.
        return $relationship;
    }

    public function visitQuery(QuerySymbol $query)
    {
        return $query->rule instanceof KeywordRule ? new EmptySymbol : $query;
    }

    public function visitList(ListSymbol $list)
    {
        return $list->rule instanceof KeywordRule ? new EmptySymbol : $list;
    }
}
