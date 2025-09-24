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
        if (empty($include)) {
            $include = Input::get('include', '');

            if (is_array($include)) {
                $include = implode(',', $include);
            }

            $include = Transformer::containsBracketNotation($include)
                ? Transformer::transformBracketToDotNotation($include)
                : $include;

            $include = $include ? explode(',', $include) : [];
        }

        $this->include = $include;
        $this->exclude = $this->getInputAsArray('exclude') ?: $exclude;
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

    public function getIsExistsIncludeItem(string $includePath): bool
    {
        $existsIncludeItem = false;

        foreach ($this->include as $includeItem) {
            if (starts_with($includeItem, $includePath)) {
                $existsIncludeItem = true;

                break;
            }
        }

        return $existsIncludeItem;
    }

    /**
     * Parse input by key and create an array of data
     *
     * @return array
     */
    private function getInputAsArray(string $key): array
    {
        $data = Input::get($key, []);

        if (is_array($data)) {
            return $data;
        }

        if (is_string($data)) {
            return explode(',', $data);
        }

        return [$data];
    }
}
