<?php

namespace App\Support\SearchString\Compiler;

use Illuminate\Support\Enumerable;
use App\Support\SearchString\AST\Symbol;

interface CompilerInterface
{
    public function lex(?string $input): Enumerable;
    public function parse(?string $input): Symbol;
}
