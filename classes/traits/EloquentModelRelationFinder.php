<?php namespace Octobro\API\Classes\traits;

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
            'Unable to get relation model class name for relation: %s',
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
}
