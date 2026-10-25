<?php

namespace App\Support\SearchString\Concerns;

use App\Support\SearchString\SearchStringManager;
use App\Support\SearchString\Visitors\AttachRulesVisitor;
use App\Support\SearchString\Visitors\BuildColumnsVisitor;
use App\Support\SearchString\Visitors\BuildKeywordsVisitor;
use App\Support\SearchString\Visitors\IdentifyRelationshipsFromRulesVisitor;
use App\Support\SearchString\Visitors\OptimizeAstVisitor;
use App\Support\SearchString\Visitors\RemoveKeywordsVisitor;
use App\Support\SearchString\Visitors\RemoveNotSymbolVisitor;
use App\Support\SearchString\Visitors\ValidateRulesVisitor;

trait SearchString
{
    public function getSearchStringManager()
    {
        $managerClass = config('search-string.manager', SearchStringManager::class);
        return new $managerClass($this);
    }

    public function getSearchStringOptions()
    {
        return [
            'columns' => $this->searchStringColumns ?? [],
            'keywords' => $this->searchStringKeywords ?? [],
        ];
    }

    public function getSearchStringVisitors($manager, $builder)
    {
        return [
            new AttachRulesVisitor($manager),
            new IdentifyRelationshipsFromRulesVisitor(),
            new ValidateRulesVisitor(),
            new RemoveNotSymbolVisitor(),
            new BuildKeywordsVisitor($manager, $builder),
            new RemoveKeywordsVisitor(),
            new OptimizeAstVisitor(),
            new BuildColumnsVisitor($manager, $builder),
        ];
    }

    public function scopeUsingSearchString($query, $string)
    {
        $this->getSearchStringManager()->updateBuilder($query, $string);
    }
}
