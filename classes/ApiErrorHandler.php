<?php

namespace Octobro\API\Classes;

use Config;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Class ApiErrorRenderer
 */
class ApiErrorHandler {

    /**
     * Simple error handling: wrap an error in array for any error/exception to be rendered in same structure
     *
     * @param Throwable $e
     * @return array
     */
    public function render(Throwable $e): array
    {
        $error = [
            'code' => 'INTERNAL_ERROR: ' . class_basename($e),
            'http_code' => $this->resolveStatusCode($e),
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

        // put under 'errors' key and return
        return [
            'errors' => $error
        ];
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

        return $e->getCode() ?? 500;
    }
}
