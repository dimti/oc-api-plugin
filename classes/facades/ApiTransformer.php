<?php namespace Octobro\API\Classes\Facades;

use October\Rain\Support\Facade;

class ApiTransformer extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'api.responder';
    }
}
