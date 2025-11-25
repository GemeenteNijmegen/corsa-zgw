<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LogIncomingRequest
{
    public function handle(Request $request, Closure $next)
    {
        Log::info('Incoming request', [
            'method'  => $request->method(),
            'url'     => $request->fullUrl(),
            'headers' => $request->headers->all(),
            'rawheaders' => $request->headers->toString(),
            'query'   => $request->query(),
            'body'    => $request->all(),
            'ip'      => $request->ip(),
        ]);

        return $next($request);
    }
}
