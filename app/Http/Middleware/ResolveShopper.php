<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Demo identity: the shopper is taken from the X-User-Id header.
 * A production deployment would authenticate with Sanctum instead.
 */
class ResolveShopper
{
    public const ATTRIBUTE = 'shopper_id';

    public function handle(Request $request, Closure $next): Response
    {
        $userId = filter_var($request->header('X-User-Id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        $exists = $userId !== false && Cache::remember(
            "shopper:{$userId}:exists",
            3600,
            fn () => User::query()->whereKey($userId)->exists(),
        );

        if (! $exists) {
            return response()->json([
                'status' => 'unauthenticated',
                'message' => 'Send a valid shopper id in the X-User-Id header.',
            ], 401);
        }

        $request->attributes->set(self::ATTRIBUTE, $userId);

        return $next($request);
    }
}
