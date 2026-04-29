<?php

namespace Octobro\API\Classes\Transformer;

use Illuminate\Database\Eloquent\Model;

trait VirtualUuid
{
    public const VIRTUAL_FIELD_UUID = 'uuid';

    public function includeUuid(Model $model)
    {
        // 1. Берём первичный ключ (учитывает кастомные имена, например 'uuid', 'code')
        $identifier = $model->getKey();

        // 2. Если ключ отсутствует (новая модель или нет PK), падаем на первый атрибут
        if ($identifier === null) {
            $attrs = $model->getAttributes();
            $identifier = reset($attrs) ?: 'no_key';
        }

        return $this->primitive(hash('fnv164', get_class($model) . ':' . $identifier));
    }
}
