<?php

namespace Spectator;

use cebe\openapi\exceptions\TypeErrorException;
use cebe\openapi\exceptions\UnresolvableReferenceException;
use cebe\openapi\spec\PathItem;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Str;
use Spectator\Exceptions\InvalidMethodException;
use Spectator\Exceptions\InvalidPathException;
use Spectator\Exceptions\MissingSpecException;
use Spectator\Exceptions\RequestValidationException;
use Spectator\Exceptions\ResponseValidationException;
use Spectator\Validation\RequestValidator;
use Spectator\Validation\ResponseValidator;

class Middleware
{
    protected $exceptionHandler;

    protected $spectator;

    protected $version = '3.0';

    public function __construct(RequestFactory $spectator, ExceptionHandler $exceptionHandler)
    {
        $this->spectator = $spectator;
        $this->exceptionHandler = $exceptionHandler;
    }

    /**
     * @param Request $request
     * @param Closure $next
     * @return JsonResponse|Request
     */
    public function handle(Request $request, Closure $next)
    {
        if (! $this->spectator->getSpec()) {
            return $next($request);
        }

        try {
            $response = $this->validate($request, $next);
        } catch (InvalidPathException $exception) {
            return $this->formatResponse($exception, 422);
        } catch (RequestValidationException | ResponseValidationException $exception) {
            return $this->formatResponse($exception, 400);
        } catch (InvalidMethodException $exception) {
            return $this->formatResponse($exception, 405);
        } catch (MissingSpecException | UnresolvableReferenceException | TypeErrorException $exception) {
            return $this->formatResponse($exception, 500);
        } catch (\Throwable $exception) {
            if ($this->exceptionHandler->shouldReport($exception)) {
                return $this->formatResponse($exception, 500);
            }

            throw $exception;
        }

        return $response;
    }

    /**
     * @param $exception
     * @param $code
     * @return JsonResponse
     */
    protected function formatResponse($exception, $code): JsonResponse
    {
        $errors = method_exists($exception, 'getErrors')
            ? ['specErrors' => $exception->getErrors()]
            : [];

        return Response::json(array_merge([
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
        ], $errors), $code);
    }

    /**
     * @param Request $request
     * @param Closure $next
     * @return mixed
     * @throws InvalidPathException
     * @throws MissingSpecException
     */
    protected function validate(Request $request, Closure $next)
    {
        $pathItem = $this->pathItem($request);

        // Resolve the path from the JsonPointer in the spec document as
        // this can be either the request's path directly or the uri
        // that is defined as a route with a dynamic parameter.
        $request_path = $pathItem->getDocumentPosition()->getPath()[1];

        RequestValidator::validate($request, $pathItem, $request->method());

        $response = $next($request);

        ResponseValidator::validate($request_path, $response, $pathItem->{strtolower($request->method())}, $this->version);

        $this->spectator->reset();

        return $response;
    }

    /**
     * @param Request $request
     * @return PathItem
     * @throws InvalidPathException
     * @throws MissingSpecException
     */
    protected function pathItem(Request $request): PathItem
    {
        $openapi = $this->spectator->resolve();

        $this->version = $openapi->openapi;

        /** @var PathItem */
        $pathItem = collect($openapi->paths)->first(function (PathItem $pathItem, string $path) use ($request) {
            $resolvedPath = $this->resolvePath($path);

            return $resolvedPath === $this->prefixPath($request->path())
                || $resolvedPath === $this->prefixPath($request->route()->uri());
        }, function () use ($request) {
            throw new InvalidPathException("Path [{$request->method()} {$this->prefixPath($request->route()->uri())}] not found in spec.", 404);
        });

        $methods = array_keys($pathItem->getOperations());

        // Check if the method exists for this path, and if so return the full PathItem
        if (in_array(strtolower($request->method()), $methods, true)) {
            return $pathItem;
        }

        throw new InvalidPathException("[{$request->method()}] not a valid method for [{$this->prefixPath($request->route()->uri())}].", 405);
    }

    /**
     * @param $path
     * @return string
     */
    protected function resolvePath($path): string
    {
        $separator = '/';

        $parts = array_filter(array_map(function ($part) use ($separator) {
            return trim($part, $separator);
        }, [$this->spectator->getPathPrefix(), $path]));

        return $separator.implode($separator, $parts);
    }

    /**
     * @param string $path
     * @return string
     */
    protected function prefixPath($path): string
    {
        if (! Str::startsWith($path, '/')) {
            return '/'. $path;
        }

        return $path;
    }
}
