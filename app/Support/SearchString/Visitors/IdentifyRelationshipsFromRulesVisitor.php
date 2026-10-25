<?php

namespace App\Support\SearchString\Visitors;

use App\Support\SearchString\AST\EmptySymbol;
use App\Support\SearchString\AST\QuerySymbol;
use App\Support\SearchString\AST\RelationshipSymbol;
use App\Support\SearchString\AST\SoloSymbol;
use App\Support\SearchString\Exceptions\InvalidSearchStringException;
use App\Support\SearchString\Options\ColumnRule;
use App\Support\SearchString\Options\Rule;

class IdentifyRelationshipsFromRulesVisitor extends Visitor
{
    public function visitSolo(SoloSymbol $solo)
    {
        if (! $this->isRelationship($solo->rule)) {
            return $solo;
        }

        return (new RelationshipSymbol($solo->content, new EmptySymbol()))
            ->attachRule($solo->rule);
    }

    public function visitQuery(QuerySymbol $query)
    {
        if (! $this->isRelationship($query->rule)) {
            return $query;
        }

        if (! ctype_digit($query->value)) {
            throw InvalidSearchStringException::fromVisitor('The expected relationship count must be an integer');
        }

        return (new RelationshipSymbol($query->key, new EmptySymbol(), $query->operator, $query->value))
            ->attachRule($query->rule);
    }

    protected function isRelationship(?Rule $rule)
    {
        return $rule && $rule instanceof ColumnRule && $rule->relationship;
    }
}
