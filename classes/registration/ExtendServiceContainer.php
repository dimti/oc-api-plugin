<?php namespace Octobro\API\Classes\Registration;

use Illuminate\Foundation\AliasLoader;
use Octobro\API\Classes\ApiResponder;
use Octobro\API\Classes\Facades;

trait ExtendServiceContainer
{
    protected function registerServices(): void
    {
        app()->singleton('api.responder', ApiResponder::class);
    }

    private function registerAliases(): void
    {
        $aliasLoader = AliasLoader::getInstance();

        $aliases = [
            'ApiTransformer' => Facades\ApiTransformer::class,
        ];

        \Config::set('app.aliases', array_merge(\Config::get('app.aliases', []), $aliases));

        foreach ($aliases as $alias => $facadeClass) {
            $aliasLoader->alias($alias, $facadeClass);
        }
    }
}
