<?php namespace Octobro\API;

use App;
use Carbon\CarbonInterface;
use Config;
use DateTime;
use DateTimeZone;
use Fruitcake\Cors\HandleCors;
use Illuminate\Database\Eloquent\Concerns\HasAttributes;
use Illuminate\Support\Facades\Date;
use Octobro\API\Classes\Registration\ExtendServiceContainer;
use Octobro\API\Classes\Transformer\DynamicInclude;
use Symfony\Component\HttpFoundation\JsonResponse;
use System\Classes\PluginBase;
use Winter\Storm\Database\Model;

class Plugin extends PluginBase
{
    use ExtendServiceContainer;

    private string $localTimezone;

    /**
     * @return void
     * @see DynamicInclude::getPrimitive
     */
    public function boot()
    {
        // Register Cors
        App::register('\Fruitcake\Cors\CorsServiceProvider');

        // Add cors middleware
        $this->app['Illuminate\Contracts\Http\Kernel']->prependMiddleware(HandleCors::class);

        $this->localTimezone = Config::get('app.timezone', 'UTC');

        Model::extend(function (Model $model) {
            $model->bindEvent('model.beforeSetAttribute', function ($attribute, $value) use ($model) {
                /**
                 * @desc For a catch strange values use: !$value instanceof Carbon\CarbonInterface && !is_scalar($value) && !is_null($value) && !is_array($value)
                 */
                if ($this->isDateDateAttribute($model, $attribute) &&
                    (
                        ($value instanceof CarbonInterface && $value->tzName !== $this->localTimezone) ||
                        ($value instanceof DateTime && $value->getTimezone()->getName() != $this->localTimezone) ||
                        (is_string($value) && str_contains($value, 'T'))
                    )
                ) {
                    /**
                     * @example "2024-10-17T15:11:00.000000+08:00" ➝ "2024-10-17T10:11:00.000000+03:00"
                     * @see Model::asDateTime
                     * @see JsonResponse
                     * @see HasAttributes::fromDateTime
                     */
                    return (is_string($value) ?
                        Date::createFromFormat('Y-m-d\TH:i:s.up', $value) :
                        $value)
                        ->setTimezone(new DateTimeZone($this->localTimezone));
                }

                return $value;
            });
        });
    }

    private function isDateDateAttribute($model, $attribute)
    {
        return in_array($attribute, $model->getDates(), true) || $model->hasCast($attribute, ['date', 'datetime', 'immutable_date', 'immutable_datetime']);
    }

    public function register()
    {
        $this->registerServices();

        $this->registerAliases();

        $this->registerConsoleCommand('octobro.api.transformer', 'Octobro\API\Console\CreateTransformer');
    }
}
