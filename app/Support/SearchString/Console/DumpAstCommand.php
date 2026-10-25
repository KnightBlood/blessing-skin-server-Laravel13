<?php

namespace App\Support\SearchString\Console;

use App\Support\SearchString\Visitors\DumpVisitor;

class DumpAstCommand extends BaseCommand
{
    protected $signature = 'search-string:ast {model} {query*}';
    protected $description = 'Parses the given search string and dumps the resulting AST';

    public function handle()
    {
        $ast = $this->getManager()->visit($this->getQuery());
        $dump = $ast->accept(new DumpVisitor());

        $this->getOutput()->write($dump);
    }
}
