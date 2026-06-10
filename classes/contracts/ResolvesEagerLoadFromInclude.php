<?php namespace Octobro\API\Classes\Contracts;

interface ResolvesEagerLoadFromInclude
{
    /**
     * @return array<int|string, string|\Closure> Eager-load definitions for a requested virtual include.
     */
    public function getEagerLoadFromInclude(string $include): array;
}
