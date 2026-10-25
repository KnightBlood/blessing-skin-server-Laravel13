<?php

namespace App\Support\SearchString\Visitors;

use App\Support\SearchString\AST\AndSymbol;
use App\Support\SearchString\AST\ListSymbol;
use App\Support\SearchString\AST\NotSymbol;
use App\Support\SearchString\AST\EmptySymbol;
use App\Support\SearchString\AST\OrSymbol;
use App\Support\SearchString\AST\QuerySymbol;
use App\Support\SearchString\AST\RelationshipSymbol;
use App\Support\SearchString\AST\SoloSymbol;

class DumpVisitor extends Visitor
{
    protected $indent = 0;

    public function indent()
    {
        if ($this->indent === 0) return '';
        return str_repeat('>   ', $this->indent);
    }

    public function dump($value)
    {
        return $this->indent() . $value . "\n";
    }

    public function visitOr(OrSymbol $or)
    {
        $root = $this->dump('OR');
        $this->indent++;
        $leaves = collect($or->expressions)->map->accept($this)->implode('');
        $this->indent--;
        return $root . $leaves;
    }

    public function visitAnd(AndSymbol $and)
    {
        $root = $this->dump('AND');
        $this->indent++;
        $leaves = collect($and->expressions)->map->accept($this)->implode('');
        $this->indent--;
        return $root . $leaves;
    }

    public function visitNot(NotSymbol $not)
    {
        $root = $this->dump('NOT');
        $this->indent++;
        $leaves = $not->expression->accept($this);
        $this->indent--;
        return $root . $leaves;
    }

    public function visitRelationship(RelationshipSymbol $relationship)
    {
        $explicitOperation = ! $relationship->isCheckingExistance() && ! $relationship->isCheckingInexistance();

        $root = $this->dump(sprintf(
            '%s [%s]%s',
            $relationship->isCheckingInexistance() ? 'NOT_EXISTS' : 'EXISTS',
            $relationship->key,
            $explicitOperation ? (' ' . $relationship->getExpectedOperation()) : '',
        ));

        $this->indent++;
        $leaves = $relationship->expression->accept($this);
        $this->indent--;
        return $root . $leaves;
    }

    public function visitSolo(SoloSymbol $solo)
    {
        return $this->dump(sprintf(
            '%s %s',
            $solo->negated ? 'NOT_SOLO' : 'SOLO',
            $solo->content
        ));
    }

    public function visitQuery(QuerySymbol $query)
    {
        return $this->dump("$query->key $query->operator $query->value");
    }

    public function visitList(ListSymbol $list)
    {
        $operator = $list->negated ? 'not in' : 'in';
        return $this->dump(sprintf('%s %s [%s]', $list->key, $operator, implode(', ', $list->values)));
    }

    public function visitEmpty(EmptySymbol $empty)
    {
        return $this->dump('EMPTY');
    }
}
