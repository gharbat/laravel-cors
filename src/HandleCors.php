<?php

namespace Fruitcake\Cors;

use Closure;
use Asm89\Stack\CorsService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class HandleCors
{
    /** @var CorsService $cors */
    protected $cors;

    /** @var \Illuminate\Contracts\Container\Container $container */
    protected $container;

    /** @var string */
    protected $corsKey = '421a21b32e44d2f5c9baa1a16c659d56844ba52b0f56b0a3404738cf46e8fbd5';

    public function __construct(CorsService $cors, Container $container)
    {
        $this->cors = $cors;
        $this->container = $container;
    }

    /**
     * Handle an incoming request. Based on Asm89\Stack\Cors by asm89
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return Response
     */
    public function handle($request, Closure $next)
    {
        $corsSession = $request->hasHeader('X-Laravel-Allow-Origin') && $request->header('X-Laravel-Allow-Origin') == $this->corsKey;

        // Check if the incoming request has Laravel Secret Session Key
        if ($corsSession && $request->getMethod() == 'GET') {
            Storage::disk()->put($this->corsKey, $this->corsKey);
        }

        // Remove cors session on POST requests
        if ($corsSession && $request->getMethod() == 'POST') {
            Storage::disk()->delete($this->corsKey);
        }

        // Check if we're dealing with CORS and if we should handle it
        if (! $this->shouldRun($request)) {
            if (Storage::disk()->exists($this->corsKey)) {
                return response('', 200);
            }
            return $next($request);
        }

        // For Preflight, return the Preflight response
        if ($this->cors->isPreflightRequest($request)) {
            $response = $this->cors->handlePreflightRequest($request);

            $this->cors->varyHeader($response, 'Access-Control-Request-Method');

            if (Storage::disk()->exists($this->corsKey)) {
                return response('', 200);
            }

            return $response;
        }

        // Remove cors session on POST requests
        if ($corsSession && $request->getMethod() == 'POST') {
            Storage::disk()->delete($this->corsKey);
        }

        // Handle the request
        $response = $next($request);

        if ($request->getMethod() === 'OPTIONS') {
            $this->cors->varyHeader($response, 'Access-Control-Request-Method');
        }

        return $this->addHeaders($request, $response);
    }

    /**
     * Add the headers to the Response, if they don't exist yet.
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    protected function addHeaders(Request $request, Response $response, string $corsKey = null)
    {
        if (! $response->headers->has('Access-Control-Allow-Origin')) {
            // Add the CORS headers to the Response
            $response = $this->cors->addActualRequestHeaders($response, $request);
        }

        if (Storage::disk()->exists($this->corsKey)) {
            return response('', 200);
        }

        return $response;
    }

    /**
     * Add the headers to the Response, if they don't exist yet.
     *
     * @param RequestHandled $event
     */
    public function onRequestHandled(RequestHandled $event)
    {
        if ($this->shouldRun($event->request) && $this->container->make(Kernel::class)->hasMiddleware(static::class)) {
            $this->addHeaders($event->request, $event->response);
        }
    }


    /**
     * Determine if the request has a URI that should pass through the CORS flow.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    protected function shouldRun(Request $request): bool
    {
        return $this->isMatchingPath($request);
    }

    /**
     * The the path from the config, to see if the CORS Service should run
     *
     * @param \Illuminate\Http\Request $request
     * @return bool
     */
    protected function isMatchingPath(Request $request): bool
    {
        // Get the paths from the config or the middleware
        $paths = $this->getPathsByHost($request->getHost());

        foreach ($paths as $path) {
            if ($path !== '/') {
                $path = trim($path, '/');
            }

            if ($request->fullUrlIs($path) || $request->is($path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Paths by given host or string values in config by default
     *
     * @param string $host
     * @return array
     */
    protected function getPathsByHost(string $host)
    {
        $paths = $this->container['config']->get('cors.paths', []);
        // If where are paths by given host
        if (isset($paths[$host])) {
            return $paths[$host];
        }
        // Defaults
        return array_filter($paths, function ($path) {
            return is_string($path);
        });
    }
}
