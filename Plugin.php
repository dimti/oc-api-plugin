<?php namespace Octobro\API;

use App;
use Octobro\API\Classes\Registration\ExtendServiceContainer;
use System\Classes\PluginBase;

class Plugin extends PluginBase
{
    use ExtendServiceContainer;

    public function boot()
    {
        // Register Cors
        App::register('\Fruitcake\Cors\CorsServiceProvider');

        // Add cors middleware
        $this->app['Illuminate\Contracts\Http\Kernel']
            ->prependMiddleware(\Fruitcake\Cors\HandleCors::class);

    }

    public function register()
    {
        $this->registerServices();

        $this->registerAliases();

        $this->registerConsoleCommand('octobro.api.transformer', 'Octobro\API\Console\CreateTransformer');
    }
}
