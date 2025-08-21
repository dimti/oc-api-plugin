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

        if (!$scope->getScopeIdentifier()) {
            $includes = request()->get('include');

            if (is_array($includes)) {
                $includes = implode(',', $includes);
            }

            // Check if includes contains bracket notation (but not just parameter notation)
            if ($includes && $this->containsBracketNotation($includes)) {
                // Transform bracket notation to dot notation
                $dotIncludes = $this->transformBracketToDotNotation($includes);

                // Parse the transformed includes
                $scope->getManager()->parseIncludes($dotIncludes);
            } elseif ($includes) {
                // If no bracket notation, just parse the includes as is
                $scope->getManager()->parseIncludes($includes);
            }
        }

        return parent::processIncludedResources($scope, $data);
    }

    public function hasDynamicInclude(string $fieldName): bool
    {
        return array_key_exists(camel_case('include ' . $fieldName), $this->extensionData['dynamicMethods']);
    }

    /**
     * Transform bracket notation to dot notation for includes
     * Example: type(id,code) -> type.id,type.code
     *
     * @param string $includes
     * @return string
     */
    protected function transformBracketToDotNotation(string $includes): string
    {
        // Process each include segment separately, but only split at top level
        $segments = $this->splitTopLevelCommas($includes);
        $result = [];

        foreach ($segments as $segment) {
            $segment = trim($segment);

            // If segment contains bracket notation
            if (strpos($segment, '(') !== false && strpos($segment, ')') !== false) {
                $result = array_merge($result, $this->processBracketNotation($segment));
            } else {
                $result[] = $segment;
            }
        }

        return implode(',', $result);
    }

    /**
     * Process a single bracket notation segment
     * Example: type(id,code) -> [type.id, type.code]
     * Preserves parameter notation like thumb:size(250|32)
     *
     * @param string $segment
     * @return array
     */
    protected function processBracketNotation(string $segment): array
    {
        // Find the position of the first opening bracket
        $openPos = strpos($segment, '(');
        if ($openPos === false) {
            return [$segment];
        }

        // Check if this is parameter notation (has colon before bracket without comma between)
        $colonPos = strrpos(substr($segment, 0, $openPos), ':');
        if ($colonPos !== false) {
            // Check if there's a comma between colon and bracket
            $commaPos = strpos(substr($segment, $colonPos, $openPos - $colonPos), ',');
            if ($commaPos === false) {
                // This is parameter notation, return as is
                return [$segment];
            }
        }

        // Get the relation name (everything before the first opening bracket)
        $relation = substr($segment, 0, $openPos);

        // Find the matching closing bracket
        $closePos = $this->findMatchingClosingBracket($segment, $openPos);
        if ($closePos === false) {
            return [$segment];
        }

        // Get the content inside the brackets
        $content = substr($segment, $openPos + 1, $closePos - $openPos - 1);

        // Split the content by commas, but only at the top level
        $fields = $this->splitTopLevelCommas($content);

        // Add the relation itself to the result
        $dotNotation = [$relation];

        // Transform to dot notation
        foreach ($fields as $field) {
            $field = trim($field);

            // Check if field contains parameter notation
            $fieldOpenPos = strpos($field, '(');
            if ($fieldOpenPos !== false) {
                $fieldColonPos = strrpos(substr($field, 0, $fieldOpenPos), ':');
                if ($fieldColonPos !== false) {
                    // Check if there's a comma between colon and bracket
                    $fieldCommaPos = strpos(substr($field, $fieldColonPos, $fieldOpenPos - $fieldColonPos), ',');
                    if ($fieldCommaPos === false) {
                        // This is parameter notation, preserve it
                        $dotNotation[] = $relation . '.' . $field;
                        continue;
                    }
                }
            }

            // If field contains bracket notation, process it recursively
            if (strpos($field, '(') !== false && strpos($field, ')') !== false) {
                $subResults = $this->processBracketNotation($field);
                foreach ($subResults as $subResult) {
                    $dotNotation[] = $relation . '.' . $subResult;
                }
            } else {
                $dotNotation[] = $relation . '.' . $field;
            }
        }

        return $dotNotation;
    }

    /**
     * Find the position of the matching closing bracket
     *
     * @param string $str
     * @param int $openPos
     * @return int|false
     */
    protected function findMatchingClosingBracket(string $str, int $openPos)
    {
        $level = 0;
        $len = strlen($str);

        for ($i = $openPos; $i < $len; $i++) {
            if ($str[$i] === '(') {
                $level++;
            } elseif ($str[$i] === ')') {
                $level--;
                if ($level === 0) {
                    return $i;
                }
            }
        }

        return false;
    }

    /**
     * Check if the includes string contains bracket notation that should be transformed
     * Ignores parameter notation like ":size(250|32)" which should not be transformed
     *
     * @param string $includes
     * @return bool
     */
    protected function containsBracketNotation(string $includes): bool
    {
        // Split by commas at top level
        $segments = $this->splitTopLevelCommas($includes);

        foreach ($segments as $segment) {
            $segment = trim($segment);

            // Check for opening bracket
            $openPos = strpos($segment, '(');
            if ($openPos === false) {
                continue;
            }

            // Check if this is parameter notation (has colon before bracket without comma between)
            $colonPos = strrpos(substr($segment, 0, $openPos), ':');
            if ($colonPos !== false) {
                // Check if there's a comma between colon and bracket
                $commaPos = strpos(substr($segment, $colonPos, $openPos - $colonPos), ',');
                if ($commaPos === false) {
                    // This is parameter notation, skip it
                    continue;
                }
            }

            // This is bracket notation
            return true;
        }

        return false;
    }

    /**
     * Split a string by commas, but only at the top level
     * Example: "a,b(c,d),e" -> ["a", "b(c,d)", "e"]
     *
     * @param string $str
     * @return array
     */
    protected function splitTopLevelCommas(string $str): array
    {
        $result = [];
        $current = '';
        $level = 0;
        $len = strlen($str);

        for ($i = 0; $i < $len; $i++) {
            $char = $str[$i];

            if ($char === '(') {
                $level++;
                $current .= $char;
            } elseif ($char === ')') {
                $level--;
                $current .= $char;
            } elseif ($char === ',' && $level === 0) {
                $result[] = $current;
                $current = '';
            } else {
                $current .= $char;
            }
        }

        if ($current !== '') {
            $result[] = $current;
        }

        return $result;
    }
}
