<?php namespace Octobro\API\Classes;

use Illuminate\Support\Collection;
use League\Fractal\Manager;

class ApiResponder
{
    private Manager $fractal;

    public function __construct(Manager $fractal)
    {
        $this->fractal = $fractal;
    }

    public function respondWithCollection(Collection $collection, Transformer $transformer): array
    {
        return $this
            ->fractal
            ->createData(new \League\Fractal\Resource\Collection($collection, $transformer))
            ->toArray()['data'];
    }
}
