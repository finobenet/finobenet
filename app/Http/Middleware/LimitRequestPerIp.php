<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

class LimitRequestPerIp
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $excludedPaths = [
            'video/thumb/*',
            'asset*'
        ];

        foreach ($excludedPaths as $path) {
            if ($request->is($path)) {
                return $next($request);
            }
        }

        $ip = $request->ip();
        $lockKey = "request_lock:$ip";
        $maxWaitTime = 10;
        $waited = 0;

        while (!Redis::setnx($lockKey, now()->timestamp)) {
            sleep(1);
            $waited++;

            if ($waited >= $maxWaitTime) {
                abort(429, "Too many requests. Please wait.");
            }
        }

        Redis::expire($lockKey, $maxWaitTime + 5);

        try {
            return $next($request);
        } finally {
            Redis::del($lockKey);
        }
    }
}
