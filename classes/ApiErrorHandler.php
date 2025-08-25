<?php

namespace Octobro\API\Classes;

use Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Class ApiErrorRenderer
 */
class ApiErrorHandler {
    public const ON_RENDER_EVENT = 'octobro.api.onRenderApiError';

    /**
     * Simple error handling: wrap an error in array for any error/exception to be rendered in same structure
     *
     * @param Throwable $e
     * @return array
     */
    public function render(Throwable $e): JsonResponse
    {
        $statusCode = $this->resolveStatusCode($e);

        $error = [
            'code' => 'INTERNAL_ERROR: ' . class_basename($e),
            'http_code' => $statusCode,
            'message' => $e->getMessage(),
        ];

        // append title to exception
        if (method_exists($e, 'getTitle')) {
            $error['title'] = $e->getTitle();
        }

        if (Config::get('app.debug')) {
            $error['file'] = $e->getFile();
            $error['line'] = $e->getLine();
            $error['trace'] = explode("\n", $e->getTraceAsString());
        }

        \Event::fire(static::ON_RENDER_EVENT, [&$error, $e], true);

        return response()->json([
            'errors' => $error
        ], $statusCode);
    }

    /**
     * Resolve HTTP status code
     *
     * @param Throwable $e
     *
     * @return int
     */
    protected function resolveStatusCode(Throwable $e): int
    {
        if ($e instanceof HttpExceptionInterface) {
            return $e->getStatusCode();
        }

        if ($e instanceof \Winter\Storm\Exception\ValidationException) {
            return 422;
        }

        $code = $e->getCode();

        /**
         * @desc This is not redundant
         * @see Throwable::getCode
         */
        if (intval($code) == $code && ($code >= 400 && $code < 500)) {
            return $code;
        }

        return 500;
    }
}
