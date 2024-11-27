<?php namespace Octobro\API\Classes\Transformer;

use Illuminate\Database\Eloquent\Concerns\HasAttributes;
use League\Fractal\Resource\Collection;
use League\Fractal\Resource\Item;
use League\Fractal\Resource\NullResource;
use League\Fractal\Resource\Primitive;
use October\Rain\Database\Model;
use October\Rain\Extension\ExtensionBase;
use Octobro\API\Classes\Exceptions\OctobroApiException;
use Octobro\API\Classes\Traits\EloquentModelRelationFinder;
use Octobro\API\Classes\Transformer;
use Config;
use Octobro\API\Plugin;
use Str;
use Winter\Storm\Argon\Argon;
use Winter\Storm\Database\Pivot;

class DynamicInclude extends ExtensionBase
{
    use EloquentModelRelationFinder, HasAttributes;

    private Transformer $transformer;

    private static string $defaultFileModelTransformer;

    private static string $defaultUserModelTransformer;

    private static array $alternativeTransformerPluginNamespaces;

    private string $fieldName;

    private Model $model;

    private bool $isSingularRelation;

    private array|string $relationDefinition;

    private string $relatedModelClass;

    private string $transformerClass;

    private bool $isFoundTransformer = false;

    public function __construct(Transformer $transformer)
    {
        $this->transformer = $transformer;
    }

    /**
     * @throws \ReflectionException
     * @throws OctobroApiException
     */
    public function getDynamicInclude(string $fieldName, Model $model)
    {
        if ($fieldName == 'pivot') {
            return new Item($model->$fieldName, function (?Pivot $pivot) use ($fieldName) {
                if (!$pivot) {
                    return [];
                }

                $parentModel = $pivot->pivotParent;

                $relationModel = $this->model;

                $relationName = $this->transformer->getCurrentScope()->getScopeIdentifier();

                $relationDefinition = $this->getRelationDefinition($parentModel, $relationName);

                if (!array_key_exists('pivot', $relationDefinition)) {
                    throw new OctobroApiException(sprintf(
                        'Unable to find pivot definition for %s in model %s',
                        $relationName,
                        class_basename($parentModel))
                    );
                }

                return $pivot->only($relationDefinition['pivot']);
            });
        }

        $this->setFieldName($fieldName);

        $this->setModel($model);

        if ($this->hasSimpleAttributeOrMutator()) {
            return $this->getPrimitive();
        }

        if ($this->hasRelation()) {
            if ($this->isCountRelation($model, $fieldName)) {
                return new Primitive($model->$fieldName->first()?->count ?? 0);
            } elseif ($this->hasNoValue()) {
                return $this->getNullResource();
            }

            $this->prepareRelationDefinition();

            $this->prepareTransformerClass();

            if ($this->isFoundTransformer()) {
                $transformer = app($this->getTransformerClass());

                if (collect($this->transformer->getCurrentScope()->getManager()->getRequestedIncludes())->contains('children')) {
                    $transformer->defaultIncludes = $this->transformer->getCurrentScopeIncludes($fieldName);
                }

                if ($this->isSingularRelation()) {
                    return new Item($model->$fieldName, $transformer);
                } else {
                    return new Collection($model->$fieldName, $transformer);
                }
            }
        }

        throw new OctobroApiException(sprintf(
            'Unable to find transformer include function for %s of model %s',
            $this->getFieldName(),
            get_class($this->getModel())
        ));
    }

    private function prepareRelationDefinition(): void
    {
        $this->unsetRelatedModelClass();

        if (array_key_exists($this->getFieldName(), $this->getModel()->belongsTo)) {
            $this->isSingularRelation = true;

            $this->relationDefinition = $this->getModel()->belongsTo[$this->getFieldName()];
        } else if (array_key_exists($this->getFieldName(), $this->getModel()->attachOne)) {
            $this->isSingularRelation = true;

            $this->relationDefinition = $this->getModel()->attachOne[$this->getFieldName()];
        } else if (array_key_exists($this->getFieldName(), $this->getModel()->hasOne)) {
            $this->isSingularRelation = true;

            $this->relationDefinition = $this->getModel()->hasOne[$this->getFieldName()];
        } else if (array_key_exists($this->getFieldName(), $this->getModel()->belongsToMany)) {
            $this->isSingularRelation = false;

            $this->relationDefinition = $this->getModel()->belongsToMany[$this->getFieldName()];
        } else if (array_key_exists($this->getFieldName(), $this->getModel()->hasMany)) {
            $this->isSingularRelation = false;

            $this->relationDefinition = $this->getModel()->hasMany[$this->getFieldName()];
        } else if (array_key_exists($this->getFieldName(), $this->getModel()->attachMany)) {
            $this->isSingularRelation = false;

            $this->relationDefinition = $this->getModel()->attachMany[$this->getFieldName()];
        } else if (array_key_exists($this->getFieldName(), $this->getModel()->morphOne)) {
            $this->isSingularRelation = true;

            $this->relationDefinition = $this->getModel()->morphOne[$this->getFieldName()];
        } else if (array_key_exists($this->getFieldName(), $this->getModel()->morphMany)) {
            $this->isSingularRelation = false;

            $this->relationDefinition = $this->getModel()->morphMany[$this->getFieldName()];
        } else if (array_key_exists($this->getFieldName(), $this->getModel()->morphTo)) {
            $this->isSingularRelation = true;

            $this->relationDefinition = $this->model->getAttribute($this->getFieldName() . '_type');
        }

        $this->setRelatedModelClass(is_array($this->relationDefinition) ? $this->relationDefinition[0] : $this->relationDefinition);
    }

    private function prepareTransformerClass(): void
    {
        $this->unsetTransformerClass();

        if (strpos($this->getRelatedModelClass(), 'File') !== false) {
            $this->setTransformerClass(static::getDefaultFileModelTransformer());
        } elseif (strpos($this->getRelatedModelClass(), 'User') !== false) {
            $this->setTransformerClass(static::getDefaultUserModelTransformer());
        } else {
            $transformerClass = str_replace('Models', 'Transformers', $this->getRelatedModelClass()) . 'Transformer';

            if (class_exists($transformerClass)) {
                $this->setTransformerClass($transformerClass);
            } else {
                preg_match('#^([A-z]+)\\\\([A-z]+)\\\\.*#', $transformerClass, $match);

                $currentPluginAuthorName = $match[1];
                $currentPluginName = $match[2];

                foreach (static::getAlternativeTransformerPluginNamespaces() as $alternatePluginNamespace) {
                    preg_match('#^([A-z]+)\.([A-z]+)$#', $alternatePluginNamespace, $match);

                    $pluginAuthorName = ucfirst(camel_case($match[1]));
                    $pluginName = ucfirst(camel_case($match[2]));

                    $alternateTransformerClass = $transformerClass;

                    if ($pluginAuthorName != $currentPluginAuthorName && $pluginName != $currentPluginName) {
                        $alternateTransformerClass = str_replace($currentPluginAuthorName, $pluginAuthorName, $alternateTransformerClass);

                        $alternateTransformerClass = str_replace($currentPluginName, $pluginName, $alternateTransformerClass);

                        if (class_exists($alternateTransformerClass)) {
                            $this->setTransformerClass($alternateTransformerClass);

                            break;
                        }
                    }
                }
            }
        }

        if ($this->hasTransformerClass() && class_exists($this->getTransformerClass())) {
            $this->setIsFoundTransformer(true);
        }
    }

    /**
     * @return string
     */
    public static function getDefaultUserModelTransformer(): string
    {
        if (!isset(static::$defaultUserModelTransformer)) {
            static::setDefaultUserModelTransformer(Config::get('fractal.defaultUserModelTransformer'));
        }

        return self::$defaultUserModelTransformer;
    }

    /**
     * @param string $defaultUserModelTransformer
     */
    public static function setDefaultUserModelTransformer(string $defaultUserModelTransformer): void
    {
        self::$defaultUserModelTransformer = $defaultUserModelTransformer;
    }

    /**
     * @return array
     */
    public static function getAlternativeTransformerPluginNamespaces(): array
    {
        if (!isset(static::$alternativeTransformerPluginNamespaces)) {
            static::setAlternativeTransformerPluginNamespaces(Config::get('fractal.alternativeTransformerPluginNamespaces'));
        }

        return self::$alternativeTransformerPluginNamespaces;
    }

    /**
     * @param array $alternativeTransformerPluginNamespaces
     */
    public static function setAlternativeTransformerPluginNamespaces(array $alternativeTransformerPluginNamespaces): void
    {
        self::$alternativeTransformerPluginNamespaces = $alternativeTransformerPluginNamespaces;
    }

    /**
     * @return string
     */
    public static function getDefaultFileModelTransformer(): string
    {
        if (!isset(self::$defaultFileModelTransformer)) {
            static::setDefaultFileModelTransformer(Config::get('fractal.defaultFileModelTransformer'));
        }

        return self::$defaultFileModelTransformer;
    }

    /**
     * @param string $defaultFileModelTransformer
     */
    public static function setDefaultFileModelTransformer(string $defaultFileModelTransformer): void
    {
        self::$defaultFileModelTransformer = $defaultFileModelTransformer;
    }

    /**
     * @param string $fieldName
     */
    public function setFieldName(string $fieldName): void
    {
        $this->fieldName = $fieldName;
    }

    /**
     * @param Model $model
     */
    public function setModel(Model $model): void
    {
        $this->model = $model;
    }

    /**
     * @return string
     */
    public function getFieldName(): string
    {
        return $this->fieldName;
    }

    /**
     * @return string
     */
    public function getFieldNameSnakeCase(): string
    {
        return snake_case($this->fieldName);
    }

    /**
     * @return Model
     */
    public function getModel(): Model
    {
        return $this->model;
    }

    private function hasSimpleAttributeOrMutator(): bool
    {
        return array_key_exists($this->getFieldNameSnakeCase(), $this->getModel()->attributes) || $this->getModel()->hasGetMutator($this->getFieldName());
    }

    /**
     * @return mixed|string|Model|\Illuminate\Support\Collection
     */
    private function getValue()
    {
        return $this->getModel()->{$this->getFieldName()};
    }

    /**
     * @return mixed|string|Model|\Illuminate\Support\Collection
     */
    private function getValueFromSnakeCaseField()
    {
        return $this->getModel()->{$this->getFieldNameSnakeCase()};
    }

    /**
     * @throws OctobroApiException
     * @see Plugin::boot()
     */
    private function getPrimitive(): Primitive
    {
        $fieldValue = $this->getValueFromSnakeCaseField();

        if ($fieldValue instanceof Argon) {
            if ($this->model->attributes[$this->getFieldNameSnakeCase()] == '0000-00-00 00:00:00') {
                $fieldValue = null;
            } else {
                $fieldValue = $fieldValue->toISOString(true);
            }
        } else {
            $castType = null;

            if (array_key_exists($this->fieldName, $this->transformer->dynamicCasts)) {
                $castType = $this->transformer->dynamicCasts[$this->fieldName];
            } elseif (array_key_exists(Str::snake($this->fieldName), $this->transformer->dynamicCasts)) {
                $castType = $this->transformer->dynamicCasts[Str::snake($this->fieldName)];
            }

            if (!is_null($castType)) {
                /**
                 * @see HasAttributes::castAttribute
                 */
                $fieldValue = match ($castType) {
                    'int', 'integer' => (int)$fieldValue,
                    'real', 'float', 'double' => $this->fromFloat($fieldValue),
                    default => throw new OctobroApiException(sprintf(
                        'Unexpected cast type %s for key %s',
                        $castType,
                        $this->fieldName
                    )),
                };
            }
        }

        return new Primitive($fieldValue);
    }

    private function hasRelation(): bool
    {
        return $this->getModel()->hasRelation($this->getFieldName());
    }

    private function hasNoValue(): bool
    {
        return !$this->getValue();
    }

    private function getNullResource(): NullResource
    {
        return new NullResource();
    }

    /**
     * @return bool
     */
    public function isSingularRelation(): bool
    {
        return $this->isSingularRelation;
    }

    /**
     * @return bool
     */
    public function hasTransformerClass(): bool
    {
        return !!isset($this->transformerClass);
    }

    /**
     * @return string
     */
    public function getTransformerClass(): string
    {
        return $this->transformerClass;
    }

    /**
     * @param string $transformerClass
     */
    public function setTransformerClass(string $transformerClass): void
    {
        $this->transformerClass = $transformerClass;
    }

    public function unsetTransformerClass(): void
    {
        unset($this->transformerClass);

        $this->isFoundTransformer = false;
    }

    /**
     * @return string
     */
    public function getRelatedModelClass(): string
    {
        return $this->relatedModelClass;
    }

    /**
     * @param string $relatedModelClass
     */
    public function setRelatedModelClass(string $relatedModelClass): void
    {
        $this->relatedModelClass = $relatedModelClass;
    }

    public function unsetRelatedModelClass(): void
    {
        unset($this->isSingularRelation, $this->relationDefinition, $this->relatedModelClass);
    }

    /**
     * @return bool
     */
    public function isFoundTransformer(): bool
    {
        return $this->isFoundTransformer;
    }

    /**
     * @param bool $isFoundTransformer
     */
    public function setIsFoundTransformer(bool $isFoundTransformer): void
    {
        $this->isFoundTransformer = $isFoundTransformer;
    }
}
