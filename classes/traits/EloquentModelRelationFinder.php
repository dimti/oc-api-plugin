<?php namespace Octobro\API\Classes\traits;

use Illuminate\Database\Eloquent\Concerns\HasRelationships;
use Illuminate\Database\Eloquent\Model;
use Octobro\API\Classes\enums\Relation;
use Octobro\API\Classes\Exceptions\OctobroApiException;
use RainLab\User\Models\User;
use ReflectionClass;
use ReflectionException;

trait EloquentModelRelationFinder
{
    /**
     * @throws ReflectionException
     */
    private function getReflectionClassOfModel(Model|string $parentModel): ReflectionClass
    {
        return new ReflectionClass(
            is_string($parentModel) ? $parentModel : get_class($parentModel)
        );
    }

    /**
     * @throws ReflectionException
     */
    private function getMayBeRelationDefinitionByReflect(ReflectionClass $reflectionClass, Relation $relationType, string $mayBeRelation): array|string
    {
        if ($this->isInteractWithUserRelation($reflectionClass, $mayBeRelation)) {
            return [User::class, 'key' => \Str::snake($mayBeRelation, '_') . '_id'];
        }

        return $this->getRelationDefinitionsProperty($reflectionClass, $relationType)[$mayBeRelation];
    }

    private function getRelationDefinitionsProperty(ReflectionClass $reflect, Relation $relationType)
    {
        return ($reflect->getProperty($relationType->value)?->getDefaultValue() ?? []);
    }

    private function isInteractWithUserRelation(ReflectionClass $reflectionClass, string $mayBeRelation)
    {
        return in_array('Wpstudio\Helpers\Classes\Traits\InteractWithUser', class_uses_recursive($modelClassName = $reflectionClass->getName())) &&
            ($interactWithUserAttributes = array_map(fn(string $attributeId) => $modelClassName::getRelationNameFromAttributeId($attributeId), array_filter([
                $modelClassName::getCreatedUserIdAttributeName($modelClassName),
                $modelClassName::getUpdatedUserIdAttributeName($modelClassName),
                $modelClassName::getDeletedUserIdAttributeName($modelClassName),
            ]))) &&
            in_array($mayBeRelation, $interactWithUserAttributes);
    }

    /**
     * @throws ReflectionException
     */
    public function getRelationType(Model|string $parentModel, string $mayBeRelation): ?Relation
    {
        $reflectionClass = $this->getReflectionClassOfModel($parentModel);

        if ($this->isInteractWithUserRelation($reflectionClass, $mayBeRelation)) {
            return Relation::RELATION_BELONGS_TO;
        }

        return collect(Relation::cases())->filter(
            fn ($relationType) => array_key_exists(
                $mayBeRelation,
                $this->getRelationDefinitionsProperty($reflectionClass, $relationType)
            )
        )->first();
    }

    /**
     * @throws ReflectionException
     */
    public function getRelationDefinition(Model|string $parentModel, string $mayBeRelation): array|string|null
    {
        $relationType = $this->getRelationType($parentModel, $mayBeRelation);

        $reflect = $this->getReflectionClassOfModel($parentModel);

        return $relationType ? $this->getMayBeRelationDefinitionByReflect($reflect, $relationType, $mayBeRelation) : null;
    }

    /**
     * @throws ReflectionException
     */
    public function isCountRelation(Model|string $parentModel, string $mayBeRelation): bool
    {
        $relationDefinition = $this->getRelationDefinition($parentModel, $mayBeRelation);

        return is_array($relationDefinition) && array_key_exists('count', $relationDefinition) && $relationDefinition['count'];
    }

    /**
     * @throws ReflectionException
     */
    public function hasRelation(Model|string $parentModel, string $mayBeRelation): bool
    {
        return $this->getRelationType($parentModel, $mayBeRelation) !== null;
    }

    /**
     * @throws OctobroApiException
     * @throws ReflectionException
     */
    public function checkRelationDefinition(Model|string $parentModel, string $mayBeRelation): void
    {
        if (!$this->getRelationDefinition($parentModel, $mayBeRelation)) {
            throw new OctobroApiException(sprintf(
                'Unable to get relation definition for: %s.%s',
                get_class($parentModel),
                $mayBeRelation
            ));
        }
    }

    /**
     * @throws OctobroApiException
     * @throws ReflectionException
     */
    public function getRelationModelClassName(Model|string $parentModel, string $mayBeRelation): string
    {
        if ($relationDefinition = $this->getRelationDefinition($parentModel, $mayBeRelation)) {
            if (is_array($relationDefinition)) {
                $relationClassName = $relationDefinition[0];
            } else {
                $relationClassName = $relationDefinition;
            }

            return $relationClassName;
        }

        throw new OctobroApiException(sprintf(
            'Unable to get relation model class name for: %s.%s',
            is_string($parentModel) ? $parentModel : get_class($parentModel),
            $mayBeRelation,
        ));
    }

    /**
     * @throws OctobroApiException
     * @throws ReflectionException
     */
    public function getRelationModel(Model|string $parentModel, string $mayBeRelation): Model
    {
        $relationClassName = $this->getRelationModelClassName($parentModel, $mayBeRelation);

        return new $relationClassName;
    }

    /**
     * @throws OctobroApiException
     * @throws ReflectionException
     */
    public function hasPivotFields(Model|string $parentModel, string $mayBeRelation): bool
    {
        $this->checkRelationDefinition($parentModel, $mayBeRelation);

        $relationDefinition = $this->getRelationDefinition($parentModel, $mayBeRelation);

        return is_array($relationDefinition) && array_key_exists('pivot', $relationDefinition);
    }

    /**
     * @throws OctobroApiException
     * @throws ReflectionException
     */
    public function getPivotFields(Model|string $parentModel, string $mayBeRelation)
    {
        $this->checkRelationDefinition($parentModel, $mayBeRelation);

        $relationDefinition = $this->getRelationDefinition($parentModel, $mayBeRelation);

        return $relationDefinition['pivot'];
    }

    /**
     * @throws OctobroApiException
     * @throws ReflectionException
     */
    public function getPivotTable(Model|string $parentModel, string $mayBeRelation)
    {
        if ($relationDefinition = $this->getRelationDefinition($parentModel, $mayBeRelation)) {
            if (is_array($relationDefinition) && array_key_exists('table', $relationDefinition)) {
                return $relationDefinition['table'];
            } else {
                /**
                 * @see Model::getTable
                 * @see HasRelationships::joiningTable
                 */
                return $parentModel->joiningTable($this->getRelationModel($parentModel, $mayBeRelation));
            }
        }

        throw new OctobroApiException(sprintf(
            'Unable to get pivot table for: %s.%s',
            get_class($parentModel),
            $mayBeRelation
        ));
    }
}
