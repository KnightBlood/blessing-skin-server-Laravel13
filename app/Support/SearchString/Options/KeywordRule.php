<?php

namespace App\Support\SearchString\Options;

class KeywordRule extends Rule
{
    public function __construct($column, $rule = null)
    {
        parent::__construct($column, $rule);
    }
}
