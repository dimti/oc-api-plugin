<?php namespace Octobro\API\Classes;

use Closure;
use Config;
use Illuminate\Support\Collection;
use League\Fractal\Scope;
use League\Fractal\TransformerAbstract;
use October\Rain\Database\Model;
use October\Rain\Extension\ExtendableTrait;
use Octobro\API\Classes\Exceptions\OctobroApiException;
use Octobro\API\Classes\Traits\EloquentModelRelationFinder;
use Octobro\API\Classes\Transformer\DynamicInclude;
use Str;
use System\Models\File;

/**
 * @method getDynamicInclude(string $fieldName, Model $model)
 * @see DynamicInclude::getDynamicInclude()
 */
abstract class Transformer extends TransformerAbstract
{
    use ExtendableTrait, EloquentModelRelationFinder;

    public $implement = [
        DynamicInclude::class,
    ];

    public $defaultIncludes = [];

    /**
     * @var array<string, string>
     */
    public array $dynamicCasts = [];

    public $availableIncludes = [];

    protected $additionalFields = [];
    /**
     * Instantiate a new BackendController instance.
     */
    public function __construct()
    {
        $this->extendableConstruct();
    }

    /**
     * Extend this object properties upon construction.
     */
    public static function extend(Closure $callback)
    {
        self::extendableExtendCallback($callback);
    }

    final public function transform($data)
    {
        $additionalData = [];

        foreach ($this->additionalFields as $key => $additionalField) {
            $additionalData[$key] = is_callable($additionalField) ? $additionalField($data) : $data->{$key};
        }

        return array_merge($data ? $this->data($data) : [], $additionalData);
    }

    /**
     * Perform dynamic methods
     */
    public function __call($method, $parameters)
    {
        if ($this->methodExists($method)) {
            return $this->extendableCall($method, $parameters);
        }

        if (method_exists($this, $method)) {
            return call_user_func_array([$this, $method], $parameters);
        }

        return $this->getDynamicInclude(camel_case(substr($method, 7)), $parameters[0]);
    }

    public function addField($key, $callback = null)
    {
        $this->additionalFields[$key] = $callback;
    }

    public function addFields($fields)
    {
        foreach ($fields as $key => $field) {
            if (is_int($key)) {
                $this->addField($field);
            } else {
                $this->addField($key, $field);
            }
        }
    }

    /**
     * [addInclude description]
     * @param [type]  $key        [description]
     * @param [type]  $callback   [description]
     * @param boolean $addDefault [description]
     */
    public function addInclude($key, $callback, $addDefault = false)
    {
        $this->availableIncludes[] = $key;

        if ($addDefault) {
            $this->defaultIncludes[] = $key;
        }

        $this->addDynamicMethod(camel_case('include ' . $key), $callback);
    }

    public function addDefaultInclude($key, $callback)
    {
        $this->addInclude($key, $callback, true);
    }

    protected function file($file)
    {
        if (!$file)
            return null;

        return array_only($file->toArray(), ['file_name', 'file_size', 'path']);
    }

    protected function image(?File $file, ?array $customSizes = [], $includeOrigin = false)
    {
        if (!isset($file) || $file === null) {
            return null;
        }

        $image = [];

        if (!$customSizes || $includeOrigin) {
            $image['original'] = $file->path;
        }

        // If the custom size is not array
        if (!is_array(reset($customSizes)) && count($customSizes) >= 2) {
            $customSizes = [
                'thumb' => $customSizes,
            ];
        }

        foreach ($customSizes as $name => $size) {
            $image[$name] = call_user_func_array([$file, 'getThumb'], $size);
        }

        return $image;
    }

    protected function files($files)
    {
        $result = [];

        foreach ($files as $file) {
            $result[] = $this->file($file);
        }

        return $result;
    }

    protected function images($files, Array $customSizes = [])
    {
        $result = [];

        foreach ($files as $file) {
            $result[] = $this->image($file);
        }

        return $result;
    }

    public function getCurrentScopeIncludes(?string $currentScopePath = null): Collection
    {
        $requestedIncludes = collect($this->getCurrentScope()->getManager()->getRequestedIncludes());

        $currentScopePath = $currentScopePath ?? (
            $this->getCurrentScope()->getParentScopes() ?
                collect($this->getCurrentScope()->getParentScopes())->slice(1)->add($this->getCurrentScope()->getScopeIdentifier()) :
                collect([])
        )->join('.');

        return $requestedIncludes
            ->when($currentScopePath && !Str::startsWith($currentScopePath, 'children'), fn($items) => $items
                ->filter(fn($segment) => Str::startsWith($segment, $currentScopePath . '.') && $segment != $currentScopePath)
                ->map(fn($segment) => Str::replaceFirst($currentScopePath . '.', '', $segment))
            )
            ->map(fn($segment) => explode('.', $segment)[0])
            ->filter()
            ->unique();
    }

    /**
     * @throws OctobroApiException
     */
    public function processIncludedResources(Scope $scope, $data)
    {
        if (Config::get('octobro.api::useStrictIncludes', false) && (!$scope->getScopeIdentifier() || !$this->isContainMorphRelationByIdentifierRelation($data, $scope->getScopeIdentifier()))) {
            $requestedCurrentScopeIncludes = $this->getCurrentScopeIncludes();

            $availableIncludesInTransformer = collect($this->getAvailableIncludes());

            if (
                $requestedCurrentScopeIncludes->count() &&
                ($diff = $requestedCurrentScopeIncludes->filter(fn($column) => $column != 'id')->diff($availableIncludesInTransformer)) &&
                $diff->count() > 0
            ) {
                throw new OctobroApiException(sprintf(
                    'The requested includes %s are not available in %s.',
                    $diff->join(', '),
                    class_basename($this)
                ));
            }
        }

        return parent::processIncludedResources($scope, $data);
    }

    public function hasDynamicInclude(string $fieldName): bool
    {
        return array_key_exists(camel_case('include ' . $fieldName), $this->extensionData['dynamicMethods']);
    }
}
