<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NoStoreApiResponses
{
    /**
     * The only routes whose responses may be kept by the browser.
     *
     * A facility's logo and its report seal: an image the centre prints on
     * its documents, behind a short-lived signed link, which sets its own
     * `private, max-age=1800`. A route earns caching by being named here. A
     * max-age set anywhere else is overwritten, not kept.
     *
     * @var array<int, string>
     */
    public const CACHEABLE_ROUTES = [
        'blood-center.facility.logo',
        'blood-center.facility.seal',
    ];

    /**
     * Forbid storing any other API response, in the browser or on the way.
     *
     * The API carries donor records, health questionnaires, test results,
     * bills and exports. With no directive, Symfony sends `no-cache, private`,
     * which still lets a browser write the response to its disk cache, where
     * it outlives the session on a shared clinic computer. `no-store` does not.
     *
     * On the whole api group, like EnsurePasswordChanged, so no route can
     * forget it. Error responses are covered too: an exception is rendered
     * inside the route pipeline and comes back out through here.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (in_array($request->route()?->getName(), self::CACHEABLE_ROUTES, true)) {
            return $response;
        }

        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
