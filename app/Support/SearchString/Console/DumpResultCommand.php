<?php

namespace App\Support\SearchString\Console;

class DumpResultCommand extends BaseCommand
{
    protected $signature = 'search-string:get {model} {query*}';
    protected $description = 'Parses the given search string and displays the result';

    public function handle()
    {
        $builder = $this->getManager()->createBuilder($this->getQuery());

        dump($builder->get());
    }
}
