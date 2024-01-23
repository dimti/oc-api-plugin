<?php namespace Octobro\API\Classes\enums;

enum Relation: string
{
    case RELATION_HAS_ONE = 'hasOne';
    case RELATION_HAS_MANY = 'hasMany';

    case RELATION_BELONGS_TO = 'belongsTo';
    case RELATION_BELONGS_TO_MANY = 'belongsToMany';

    case RELATION_ATTACH_ONE = 'attachOne';
    case RELATION_ATTACH_MANY = 'attachMany';

    case RELATION_MORPH_TO = 'morphTo';
    case RELATION_MORPH_ONE = 'morphOne';
    case RELATION_MORPH_MANY = 'morphMany';
}
