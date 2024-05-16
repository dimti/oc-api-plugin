<?php

namespace Octobro\API\Classes\Traits;

use Winter\Storm\Database\Model;

trait NestedChildrenTransformer
{
    public function includeChildren(Model $model)
    {
        $transformer = new self;

        $transformer->defaultIncludes = $this->getCurrentScopeIncludes()->toArray();

        return $this->collection($model->children, $transformer);
    }
}
