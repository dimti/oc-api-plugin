<?php namespace Octobro\API\Classes\traits;

use October\Rain\Database\Model;
use Octobro\API\Classes\enums\Relation;

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

    public function getRelationModel(Model|\Winter\Storm\Database\Model|\Illuminate\Database\Eloquent\Model $parentModel, string $mayBeRelation): Model|\Winter\Storm\Database\Model|\Illuminate\Database\Eloquent\Model|null
    {
        if ($relationDefinition = $this->getRelationDefinition($parentModel, $mayBeRelation)) {
            if (is_array($relationDefinition)) {
                $relationClassName = $relationDefinition[0];
            } else {
                $relationClassName = $relationDefinition;
            }

            return new $relationClassName;
        }

        return null;
    }
}
