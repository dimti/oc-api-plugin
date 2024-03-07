<?php namespace Octobro\API\Classes\traits;

use Illuminate\Database\Eloquent\Concerns\HasRelationships;
use October\Rain\Database\Model;
use Octobro\API\Classes\enums\Relation;
use Octobro\API\Classes\Exceptions\OctobroApiException;

trait EloquentModelRelationFinder
{
    public function getRelationType(Model|\Winter\Storm\Database\Model|\Illuminate\Database\Eloquent\Model $parentModel, string $mayBeRelation): ?Relation
    {
        $relationTypes = collect(Relation::cases());

        return $relationTypes->filter(fn($relationTypeName) => array_key_exists($mayBeRelation, $parentModel->{$relationTypeName->value}))->first();
    }

    public function getRelationDefinition(Model|\Winter\Storm\Database\Model|\Illuminate\Database\Eloquent\Model $parentModel, string $mayBeRelation): array|string|null
    {
        $relationType = $this->getRelationType($parentModel, $mayBeRelation);

        return $relationType ? $parentModel->{$relationType->value}[$mayBeRelation] : null;
    }

    public function hasRelation(Model|\Winter\Storm\Database\Model|\Illuminate\Database\Eloquent\Model $parentModel, string $mayBeRelation): bool
    {
        return $this->getRelationType($parentModel, $mayBeRelation) !== null;
    }

    /**
     * @throws OctobroApiException
     */
    public function checkRelationDefinition(Model|\Winter\Storm\Database\Model|\Illuminate\Database\Eloquent\Model $parentModel, string $mayBeRelation): void
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
     */
    public function getRelationModelClassName(Model|\Winter\Storm\Database\Model|\Illuminate\Database\Eloquent\Model $parentModel, string $mayBeRelation): string
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
            get_class($parentModel),
            $mayBeRelation,
        ));
    }

    /**
     * @throws OctobroApiException
     */
    public function getRelationModel(Model|\Winter\Storm\Database\Model|\Illuminate\Database\Eloquent\Model $parentModel, string $mayBeRelation): Model|\Winter\Storm\Database\Model|\Illuminate\Database\Eloquent\Model
    {
        $relationClassName = $this->getRelationModelClassName($parentModel, $mayBeRelation);

        return new $relationClassName;
    }

    /**
     * @throws OctobroApiException
     */
    public function hasPivotFields(Model|\Winter\Storm\Database\Model|\Illuminate\Database\Eloquent\Model $parentModel, string $mayBeRelation): bool
    {
        $this->checkRelationDefinition($parentModel, $mayBeRelation);

        $relationDefinition = $this->getRelationDefinition($parentModel, $mayBeRelation);

        return is_array($relationDefinition) && array_key_exists('pivot', $relationDefinition);
    }

    /**
     * @throws OctobroApiException
     */
    public function getPivotFields(Model|\Winter\Storm\Database\Model|\Illuminate\Database\Eloquent\Model $parentModel, string $mayBeRelation)
    {
        $this->checkRelationDefinition($parentModel, $mayBeRelation);

        $relationDefinition = $this->getRelationDefinition($parentModel, $mayBeRelation);

        return $relationDefinition['pivot'];
    }

    /**
     * @throws OctobroApiException
     */
    public function getPivotTable(Model|\Winter\Storm\Database\Model|\Illuminate\Database\Eloquent\Model $parentModel, string $mayBeRelation)
    {
        if ($relationDefinition = $this->getRelationDefinition($parentModel, $mayBeRelation)) {
            if (is_array($relationDefinition) && array_key_exists('table', $relationDefinition)) {
                return $relationDefinition['table'];
            } else {
                /**
                 * @see \Illuminate\Database\Eloquent\Model::getTable
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
