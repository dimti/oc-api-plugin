<?php namespace Octobro\API\Classes;

use October\Rain\Exception\ValidationException;
use Validator;

class InputBag
{
    private array $input;

    public function __construct(?array $input = [])
    {
        $this->input = $input;
    }

    public function setInput(array $input): void
    {
        $this->input = $input;
    }

    public function fillFromRequest(): void
    {
        $this->input = (request()->isJson() ? request()->json() : request())->all();
    }

    public function has(string $key): bool
    {
        return array_has($this->input, $key);
    }

    /**
     * @param string $key
     * @param string|null $default
     * @return mixed
     */
    public function get(string $key, ?string $default = null)
    {
        return array_get($this->input, $key, $default);
    }

    public function all(): array
    {
        return $this->input;
    }

    /**
     * @param array $rules
     * @return \Illuminate\Validation\Validator
     * @throws ValidationException
     */
    public function validate(array $rules): \Illuminate\Validation\Validator
    {
        $validator = Validator::make($this->input, $rules);

        if ($validator->fails()) {
            assert($validator instanceof \Illuminate\Validation\Validator);

            throw new ValidationException($validator);
        }

        return $validator;
    }
}
