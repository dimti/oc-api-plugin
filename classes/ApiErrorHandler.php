<?php

namespace Octobro\API\Classes;

use Config;
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
            'http_code' => 500,
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
}
