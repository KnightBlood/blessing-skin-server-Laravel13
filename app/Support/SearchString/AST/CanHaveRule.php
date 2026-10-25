<?php

namespace App\Support\SearchString\AST;

use App\Support\SearchString\Options\Rule;

trait CanHaveRule
{
    /** @var Rule */
    public $rule;

    public function attachRule(Rule $rule)
    {
        $this->rule = $rule;

        return $this;
    }
}
