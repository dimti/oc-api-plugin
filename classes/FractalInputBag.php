<?php namespace Octobro\API\Classes;

use Illuminate\Http\Request;
use Input;

/**
 * @desc Used for include,exclude
 */
class FractalInputBag
{
    protected array $include;

    protected array $exclude;

    public function __construct(array $include = [], array $exclude = [])
    {
        $this->include = Input::has('include') ? explode(',', Input::get('include')) : $include;

        $this->exclude = Input::has('exclude') ? explode(',', Input::get('exclude')) : $exclude;
    }

    /**
     * @return array|mixed
     */
    public function getInclude()
    {
        return $this->include;
    }

    /**
     * @return array|mixed
     */
    public function getExclude()
    {
        return $this->exclude;
    }

    /**
     * @return array|mixed
     */
    public function hasInclude()
    {
        return isset($this->include) && $this->include;
    }

    /**
     * @return array|mixed
     */
    public function hasExclude()
    {
        return isset($this->exclude) && $this->exclude;
    }
}
