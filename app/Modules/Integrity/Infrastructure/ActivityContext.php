<?php

namespace App\Modules\Integrity\Infrastructure;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Where the current request or command comes from: a correlation id (ties together every
 * entry written by one operation), IP, browser, method and route. One instance per request
 * or job (scoped binding).
 */
final class ActivityContext
{
    public const string HEADER = 'X-Request-Id';

    public string $requestId;

    public ?string $ip = null;

    public ?string $userAgent = null;

    public ?string $method = null;

    public ?string $route = null;

    public function __construct()
    {
        $this->requestId = (string) Str::uuid7();
    }

    public function fromRequest(Request $request): void
    {
        // An external correlation id is only accepted when it is a UUID: nothing else reaches the log.
        $incoming = (string) $request->headers->get(self::HEADER, '');
        $this->requestId = Str::isUuid($incoming) ? strtolower($incoming) : $this->requestId;
        $this->ip = $request->ip();
        $this->userAgent = Str::limit((string) $request->userAgent(), 509) ?: null;
        $this->method = $request->method();
        $this->route = Str::limit($request->route()?->getName() ?? '/'.ltrim($request->path(), '/'), 157);
    }

    public function fromCommand(string $command): void
    {
        $this->method = 'CLI';
        $this->route = Str::limit("artisan {$command}", 157);
    }
}
