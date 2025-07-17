<?php namespace Octobro\API\Classes;

use October\Rain\Exception\ValidationException;
use Validator;
use Input;

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
        $this->input = Input::get();
    }

    public function has(string $key): bool
    {
        return array_has($this->input, $key);
    }

    /**
     * @param string $key
     * @param string|array|null $default
     * @return mixed
     */
    public function get(string $key, string|array $default = null)
    {
        return array_get($this->input, $key, $default);
    }

    public function all(): array
    {
        return $this->input;
    }

    /**
     * @param array $rules
     * @param string|array|null $nestedKey
     * @param array|null $messages
     * @return \Illuminate\Validation\Validator
     * @throws ValidationException
     */
    public function validate(array $rules, $nestedKey = null, ?array $messages = []): \Illuminate\Validation\Validator
    {

        $validator = Validator::make(
            $nestedKey
                ? array_get($this->input, is_array($nestedKey) ? implode('.', $nestedKey) : $nestedKey)
                : $this->input,
            $rules,
            $messages
        );

        if ($validator->fails()) {
            assert($validator instanceof \Illuminate\Validation\Validator);

            throw new ValidationException($validator);
        }

        return $validator;
    }
}
