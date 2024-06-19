<?php

namespace Octobro\API\Classes\Traits;

use Winter\Storm\Database\Model;

trait NestedChildrenTransformer
{
    private static array $baseLevelDefaultIncludes;

    public function includeChildren(Model $model)
    {
        $transformer = new self;

        if (!isset(static::$baseLevelDefaultIncludes)) {
            static::$baseLevelDefaultIncludes = $this->getCurrentScopeIncludes()->toArray();
        }

        $transformer->defaultIncludes = static::$baseLevelDefaultIncludes;

        return $this->collection($model->children, $transformer);
    }
}
