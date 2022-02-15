<?php namespace Octobro\API\Classes;

use App;
use Input;
use Config;
use Closure;
use Cache;
use October\Rain\Extension\ExtendableTrait;
use Response;
use SimpleXMLElement;
use Illuminate\Routing\Controller;
use Symfony\Component\Yaml\Dumper as YamlDumper;
use League\Fractal\Manager;
use League\Fractal\Resource\Item;
use League\Fractal\Resource\Collection;
use League\Fractal\Pagination\IlluminatePaginatorAdapter;

class ApiController extends Controller
{
    use ExtendableTrait;

    public $implement;

    protected $input;

    protected $data;

	protected $statusCode = 200;

    private bool $forceArrayOutput = false;

    private bool $forceInvalidateCache = false;

    private array $allowedHashData = [];

    private int $cacheInvalidateInMinutes = 20;

    private string $mimeType;

	const CODE_WRONG_ARGS = 'WRONG_ARGS';
    const CODE_NOT_FOUND = 'NOT_FOUND';
    const CODE_INTERNAL_ERROR = 'INTERNAL_ERROR';
    const CODE_UNAUTHORIZED = 'UNAUTHORIZED';
    const CODE_FORBIDDEN = 'FORBIDDEN';
    const CODE_INVALID_MIME_TYPE = 'INVALID_MIME_TYPE';

    public function __construct(Manager $fractal)
    {
        $this->fractal = $fractal;

        // Including data
        if (Input::get('include')) {
            $this->fractal->parseIncludes(Input::get('include'));
        }

        // Excluding data
        if (Input::get('exclude')) {
            $this->fractal->parseExcludes(Input::get('exclude'));
        }

        $this->input = request()->isJson() ? request()->json() : request();
        $this->data = $this->input->all();

        if (app()->get('router')->getCurrentRoute()->controller === null) {
            /**
             * @desc Handle error (do not handle errors if running from cms controller)
             * CmsController has been defined on this point if ApiController call from OctoberCMS components
             * But if that api query with api routes - controller not defined on this point
             */
            App::error(function (\Exception $e) {
                header("Access-Control-Allow-Origin: *");
                $trace = $e->getTraceAsString();

                $error = [
                    'error' => [
                        'code' => 'INTERNAL_ERROR',
                        'http_code' => 500,
                        'message' => $e->getMessage(),
                    ],
                ];

                if (Config::get('app.debug'))
                    $error['trace'] = $e->getTrace();

                return $error;
            });
        }

        $this->extendableConstruct();
    }

    public function withData(array $data): self
    {
        $this->data = $data;

        return $this;
    }

    public function withForceArrayOutput(?bool $forceArrayOutput = true): self
    {
        $this->forceArrayOutput = true;

        return $this;
    }

    public function withCacheInvalidate(int $cacheInvalidateInMinutes): self
    {
        $this->cacheInvalidateInMinutes = $cacheInvalidateInMinutes;

        return $this;
    }

    public function withForceInvalidateCache(bool $forceInvalidateCache = true): self
    {
        $this->forceInvalidateCache = $forceInvalidateCache;

        return $this;
    }

    private function getCacheInvalidate(): int
    {
        return $this->cacheInvalidateInMinutes * 60;
    }

    public function withAllowedHashData(array $allowedHashData): self
    {
        $this->allowedHashData = $allowedHashData;

        return $this;
    }

    private function getHashedPayload(): string
    {
        return md5(serialize(array_merge(
            [$this->getMimeType(), $this->forceArrayOutput, input('includes'), input('excludes')],
            array_filter($this->data, fn ($key) => in_array($key, $this->allowedHashData), ARRAY_FILTER_USE_KEY)
        )));
    }

    private function hasMimeType(): bool
    {
        return isset($this->mimeType) && $this->mimeType;
    }

    private function getMimeType(): string
    {
        if (!$this->hasMimeType()) {
            switch ($mimeType = $this->getMimeTypeFromServerHeader()) {
                case 'application/json':
                case 'application/x-yaml':
                case 'application/xml':
                    $this->mimeType = $mimeType;
                    break;
            }
        }

        return $this->hasMimeType() ? $this->mimeType : '';
    }

    public function cached(Closure $callback, string $cacheKey, ?array $cacheTags = null)
    {
        $cacheKey .= '::' . $this->getHashedPayload();

        $cache = isset($cacheTags) && $cacheTags ? Cache::tags($cacheTags) : app()->get('cache');

        if ($this->forceInvalidateCache) {
            $cache->forget($cacheKey);
        }

        return $cache->remember($cacheKey, $this->getCacheInvalidate(), $callback);
    }

    /**
     * Extend this object properties upon construction.
     */
    public static function extend(Closure $callback)
    {
        self::extendableExtendCallback($callback);
    }

    /**
     * Perform dynamic methods
     */
    public function __call($method, $parameters)
    {
        if (isset($this->extensionData['dynamicMethods'][$method])) {
            return call_user_func_array($this->extensionData['dynamicMethods'][$method], $parameters);
        }

        return call_user_func_array([$this, $method], $parameters);
    }

    /**
     * Getter for statusCode
     *
     * @return mixed
     */
    public function getStatusCode()
    {
        return $this->statusCode;
    }

    /**
     * Setter for statusCode
     *
     * @param int $statusCode Value to set
     *
     * @return self
     */
    public function setStatusCode($statusCode)
    {
        $this->statusCode = $statusCode;
        return $this;
    }

    protected function respondWithItem($item, $callback, $key = null)
    {
        $resource = new Item($item, $callback, $key);

        $rootScope = $this->fractal->createData($resource);

        return $this->respondWithArray($rootScope->toArray());
    }

    protected function respondWithCollection($collection, $callback, $key = null)
    {
        $resource = new Collection($collection, $callback, $key);

        $rootScope = $this->fractal->createData($resource);

        return $this->respondWithArray($rootScope->toArray());
    }

    protected function respondWithPaginator($paginator, $callback, $key = null)
    {
        $collection = $paginator->getCollection();

        $resource = new Collection($collection, $callback, $key);

        $resource->setPaginator(new IlluminatePaginatorAdapter($paginator));

        $rootScope = $this->fractal->createData($resource);

        return $this->respondWithArray($rootScope->toArray());
    }

    private function getMimeTypeFromServerHeader()
    {
        $mimeTypeRaw = Input::server('HTTP_ACCEPT', '*/*');

        if ($mimeTypeRaw === '*/*') {
            return 'application/json';
        } else {
            $mimeParts = (array) preg_split( "/(,|;)/", $mimeTypeRaw);

            return strtolower(trim($mimeParts[0]));
        }
    }

    protected function respondWithArray(array $array, array $headers = [])
    {
        if ($this->forceArrayOutput) {
            return $array;
        }

        $mimeType = $this->getMimeType();

        switch ($mimeType) {
            case 'application/json':
                $content = json_encode($array);
                break;

            case 'application/x-yaml':
                $dumper = new YamlDumper();
                $content = $dumper->dump($array, 2);
                break;

            case 'application/xml':
                $xml = new SimpleXMLElement('<response/>');
                $this->arrayToXml($array, $xml);
                $content = $xml->asXML();
                break;
            default:
                $content = json_encode([
                    'error' => [
                        'code' => static::CODE_INVALID_MIME_TYPE,
                        'http_code' => 415,
                        'message' => sprintf('Content of type %s is not supported.', $this->getMimeTypeFromServerHeader()),
                    ]
                ]);
                $mimeType = 'application/json';
        }

        $response = Response::make($content, $this->statusCode, $headers);
        $response->header('Content-Type', $mimeType);

        return $response;
    }

    /**
     * Convert an array to XML
     * @param array $array
     * @param SimpleXMLElement $xml
     */
    protected function arrayToXml($array, &$xml){
        foreach ($array as $key => $value) {
            if(is_array($value)){
                if(is_int($key)){
                    $key = "item";
                }
                $label = $xml->addChild($key);
                $this->arrayToXml($value, $label);
            }
            else {
                $xml->addChild($key, $value);
            }
        }
    }

    protected function respondWithError($message, $errorCode)
    {
        if ($this->statusCode === 200) {
            trigger_error(
                "You better have a really good reason for erroring on a 200...",
                E_USER_WARNING
            );
        }

        return $this->respondWithArray([
            'error' => [
                'code' => $errorCode,
                'http_code' => $this->statusCode,
                'message' => $message,
            ]
        ]);
    }

    /**
     * Generates a Response with a 403 HTTP header and a given message.
     *
     * @return  Response
     */
    public function errorForbidden($message = 'Forbidden')
    {
        return $this->setStatusCode(403)->respondWithError($message, self::CODE_FORBIDDEN);
    }

    /**
     * Generates a Response with a 500 HTTP header and a given message.
     *
     * @return  Response
     */
    public function errorInternalError($message = 'Internal Error')
    {
        return $this->setStatusCode(500)->respondWithError($message, self::CODE_INTERNAL_ERROR);
    }

    /**
     * Generates a Response with a 404 HTTP header and a given message.
     *
     * @return  Response
     */
    public function errorNotFound($message = 'Resource Not Found')
    {
        return $this->setStatusCode(404)->respondWithError($message, self::CODE_NOT_FOUND);
    }

    /**
     * Generates a Response with a 401 HTTP header and a given message.
     *
     * @return  Response
     */
    public function errorUnauthorized($message = 'Unauthorized')
    {
        return $this->setStatusCode(401)->respondWithError($message, self::CODE_UNAUTHORIZED);
    }

    /**
     * Generates a Response with a 400 HTTP header and a given message.
     *
     * @return  Response
     */
    public function errorWrongArgs($message = 'Wrong Arguments')
    {
        return $this->setStatusCode(400)->respondWithError($message, self::CODE_WRONG_ARGS);
    }
}
