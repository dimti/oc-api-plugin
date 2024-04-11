<?php namespace Octobro\API\Classes;

use App;
use Input;
use Config;
use Closure;
use Cache;
use October\Rain\Database\Model;
use October\Rain\Extension\ExtendableTrait;
use Octobro\API\Classes\traits\EloquentModelRelationFinder;
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
    use ExtendableTrait, EloquentModelRelationFinder;

    const CODE_WRONG_ARGS = 'WRONG_ARGS';
    const CODE_NOT_FOUND = 'NOT_FOUND';
    const CODE_INTERNAL_ERROR = 'INTERNAL_ERROR';
    const CODE_UNAUTHORIZED = 'UNAUTHORIZED';
    const CODE_FORBIDDEN = 'FORBIDDEN';
    const CODE_INVALID_MIME_TYPE = 'INVALID_MIME_TYPE';

    public array $implement = [];

    public Manager $fractal;

    public InputBag $inputBag;

	protected $statusCode = 200;

    protected FractalInputBag $fractalInputBag;

    private bool $forceArrayOutput = false;

    private bool $forceInvalidateCache = false;

    private array $allowedHashData = [
        'page',
        'number',
    ];

    private int $cacheInvalidateInMinutes = 20;

    private string $mimeType;

    public function __construct(Manager $fractal, InputBag $inputBag)
    {
        $this->fractal = $fractal;

        $this->inputBag = $inputBag;

        if (app()->get('router')->getCurrentRoute()?->controller === null) {
            $this->inputBag->fillFromRequest();

            $errorHandler = function (\Exception $e) {
                $error = [
                    'errors' => [
                        'code' => 'INTERNAL_ERROR',
                        'http_code' => 500,
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ],
                ];

                if (Config::get('app.debug')) {
                    $error['errors']['trace'] = explode("\n", $e->getTraceAsString());
                }

                return $error;
            };

            /**
             * @desc Handle error (do not handle errors if running from cms controller)
             * CmsController has been defined on this point if ApiController call from OctoberCMS components
             * But if that api query with api routes - controller not defined on this point
             */
            App::error($errorHandler);
            App::fatal($errorHandler);
        }

        $this->initRepository();

        $this->extendableConstruct();
    }

    protected function initRepository(): void
    {

    }

    public function withInput(array $input): self
    {
        $this->inputBag->setInput($input);

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

    public function getEloquentWithFromIncludes(Model $startingModelClass, array $generalWith, ?array $excludeWith = []): array
    {
        $possibleWiths = [];

        $getIsRelationExistsInGeneralWiths = function (string $relationName) use ($generalWith) {
            $relationExists = false;

            foreach ($generalWith as $with => $maybeWithClosure) {
                if (starts_with(is_string($maybeWithClosure) ? $maybeWithClosure : $with, $relationName)) {
                    $relationExists = true;

                    break;
                }
            }

            return $relationExists;
        };

        $nerestPossibleWiths = function (Model $model, array &$includeParts, array $previousParts = []) use (&$possibleWiths, $getIsRelationExistsInGeneralWiths, &$nerestPossibleWiths) {
            $maybeRelation = array_shift($includeParts);

            $fullMaybeRelationWithPath = count($previousParts) ? implode('.', $previousParts) . '.' . $maybeRelation : $maybeRelation;

            if (count($includeParts) || (!$getIsRelationExistsInGeneralWiths($fullMaybeRelationWithPath)) && !in_array($fullMaybeRelationWithPath, $possibleWiths)) {
                if ($this->hasRelation($model, $maybeRelation)) {
                    if (!$getIsRelationExistsInGeneralWiths($fullMaybeRelationWithPath) && !in_array($fullMaybeRelationWithPath, $possibleWiths)) {
                        $possibleWiths[] = $fullMaybeRelationWithPath;
                    }

                    $previousParts[] = $maybeRelation;

                    if (count($includeParts)) {
                        $nerestPossibleWiths($this->getRelationModel($model, $maybeRelation), $includeParts, $previousParts);
                    }
                }
            }
        };

        $include = $this->getFractalInputBag()->getInclude();

        /*if (isset($extractPath) && $extractPath) {
            $include = array_filter(array_map(
                fn($includeItem) => ltrim(str_replace($extractPath, '', $includeItem), '.'),
                array_filter($include, fn($includeItem) => starts_with($includeItem, $extractPath))
            ));
        }*/

        if (isset($excludeWith) && $excludeWith) {
            $include = array_filter($include, fn(string $includeItem) => !in_array($includeItem, $excludeWith));
        }

        foreach ($include as $includeItem) {
            $includeParts = explode('.', $includeItem);

            $nerestPossibleWiths($startingModelClass, $includeParts);
        }

        unset($include, $includeItem, $includeParts);

        return array_merge($possibleWiths, $generalWith);
    }

    public function withAllowedHashData(array $allowedHashData, ?bool $force = false): self
    {
        $this->allowedHashData = isset($force) && $force ?
            $allowedHashData :
            array_merge($this->allowedHashData, $allowedHashData)
        ;

        return $this;
    }

    private function getHashedPayload(): string
    {
        return md5(serialize(array_merge(
            [$this->getMimeType(), $this->forceArrayOutput, $this->getFractalInputBag()->getInclude(), $this->getFractalInputBag()->getExclude()],
            array_filter($this->inputBag->all(), fn ($key) => in_array($key, $this->allowedHashData), ARRAY_FILTER_USE_KEY)
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

    public function cached(string $cacheKey, Closure $callback, ?array $cacheTags = [])
    {
        if (!Config::get('enable.api_cache')) {
            return $callback();
        } else {
            $cacheKey .= '::' . $this->getHashedPayload();

            $cacheManager = app('cache');

            $cacheStore = $cacheManager->store()->getStore();

            if (method_exists($cacheStore, 'tags')) {
                $cacheTags = array_merge($cacheTags, [ApiController::class]);

                $cache = Cache::tags($cacheTags);
            } else {
                if ($cacheTags) {
                    app('log')->warning(
                        'Your cache store doesnt support tags. Tags: ' . implode(', ', $cacheTags)
                    );
                }

                $cache = app()->get('cache');
            }

            if ($this->forceInvalidateCache) {
                $cache->forget($cacheKey);
            }

            return $cache->remember($cacheKey, $this->getCacheInvalidate(), $callback);
        }
    }

    public function cachedTags(array $cacheTags, string $cacheKey, Closure $callback)
    {
        return $this->cached($cacheKey, $callback, $cacheTags);
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
        if (method_exists($this, $method)) {
            return call_user_func_array([$this, $method], $parameters);
        } else {
            return $this->extendableCall($method, $parameters);
        }
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

    private function fractalizeInputBag()
    {
        // Including data
        if ($this->getFractalInputBag()->hasInclude()) {
            $this->fractal->parseIncludes($this->getFractalInputBag()->getInclude());
        }

        // Excluding data
        if ($this->getFractalInputBag()->hasExclude()) {
            $this->fractal->parseExcludes($this->getFractalInputBag()->getExclude());
        }
    }

    /**
     * @return FractalInputBag
     */
    public function getFractalInputBag(): FractalInputBag
    {
        return $this->fractalInputBag ?? new FractalInputBag;
    }

    public function hasFractalInputBag(): bool
    {
        return isset($this->fractalInputBag);
    }

    public function withFractalInputBag(FractalInputBag $fractalInputBag, ?bool $force = false): self
    {
        if ($force || !$this->hasFractalInputBag()) {
            $this->fractalInputBag = $fractalInputBag;
        }

        return $this;
    }

    protected function clearFractalInputBag(): void
    {
        unset($this->fractalInputBag);
    }

    public function respondWithItem($item, $callback, $key = null)
    {
        $resource = new Item($item, $callback, $key);

        $this->fractalizeInputBag();

        $rootScope = $this->fractal->createData($resource);

        $this->clearFractalInputBag();

        return $this->respondWithArray($rootScope->toArray());
    }

    public function respondWithCollection($collection, $callback, $key = null)
    {
        $resource = new Collection($collection, $callback, $key);

        $this->fractalizeInputBag();

        $rootScope = $this->fractal->createData($resource);

        $this->clearFractalInputBag();

        return $this->respondWithArray($rootScope->toArray());
    }

    public function respondWithPaginator($paginator, $callback, $key = null)
    {
        $collection = $paginator->getCollection();

        $resource = new Collection($collection, $callback, $key);

        $resource->setPaginator(new IlluminatePaginatorAdapter($paginator));

        $this->fractalizeInputBag();

        $rootScope = $this->fractal->createData($resource);

        $this->clearFractalInputBag();

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
