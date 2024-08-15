<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Makes unsafe requests safe to retry.
 *
 * The first request with a given X-Idempotency-Key claims the key atomically
 * (SET NX). Concurrent duplicates get 409 while it runs; later duplicates get
 * the stored response replayed, so a double-click or a network retry can
 * never reserve or confirm twice.
 */
class IdempotencyMiddleware
{
    public const HEADER = 'X-Idempotency-Key';

    public const REPLAYED_HEADER = 'Idempotent-Replayed';

    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->header(self::HEADER);

        if (! preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $key)) {
            return response()->json([
                'status' => 'invalid_idempotency_key',
                'message' => 'Send a unique '.self::HEADER.' header (8-100 characters: letters, digits, . _ : -).',
            ], 400);
        }

        $storageKey = 'idempotency:'.sha1(implode('|', [
            $request->attributes->get(ResolveShopper::ATTRIBUTE),
            $request->method(),
            $request->path(),
            $key,
        ]));
        $fingerprint = sha1($request->getContent());

        $claimed = Redis::set(
            $storageKey,
            json_encode(['state' => 'processing', 'fingerprint' => $fingerprint]),
            'EX',
            config('flash_sale.idempotency.lock_ttl'),
            'NX',
        );

        if (! $claimed) {
            return $this->duplicate($storageKey, $fingerprint);
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            Redis::del($storageKey);

            throw $e;
        }

        if ($response->getStatusCode() >= 500) {
            Redis::del($storageKey);
        } else {
            Redis::setex($storageKey, config('flash_sale.idempotency.ttl'), json_encode([
                'state' => 'done',
                'fingerprint' => $fingerprint,
                'status' => $response->getStatusCode(),
                'body' => $response->getContent(),
            ]));
        }

        $response->headers->set(self::REPLAYED_HEADER, 'false');

        return $response;
    }

    private function duplicate(string $storageKey, string $fingerprint): Response
    {
        $stored = json_decode((string) Redis::get($storageKey), true);

        if (! is_array($stored) || $stored['state'] === 'processing') {
            return response()->json([
                'status' => 'request_in_progress',
                'message' => 'A request with this idempotency key is still being processed.',
            ], 409, ['Retry-After' => 1]);
        }

        if ($stored['fingerprint'] !== $fingerprint) {
            return response()->json([
                'status' => 'idempotency_key_reused',
                'message' => 'This idempotency key was already used with a different payload.',
            ], 422);
        }

        return response($stored['body'], $stored['status'], [
            'Content-Type' => 'application/json',
            self::REPLAYED_HEADER => 'true',
        ]);
    }
}
