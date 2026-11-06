<?php

namespace App\Modules\Integrity\Http\Middleware;

use App\Modules\Integrity\Infrastructure\ActivityContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fills the activity context with the request (correlation id, IP, browser, route) and
 * returns the correlation id in the response, so a report can be matched to its log entries.
 */
class CaptureActivityContext
{
    public function __construct(private readonly ActivityContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->fromRequest($request);

        $response = $next($request);
        $response->headers->set(ActivityContext::HEADER, $this->context->requestId);

        return $response;
    }
}
