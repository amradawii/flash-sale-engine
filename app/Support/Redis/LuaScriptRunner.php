<?php

namespace App\Support\Redis;

use Illuminate\Redis\RedisManager;
use RuntimeException;

/**
 * Runs the Lua scripts in LuaScripts/ with EVALSHA, falling back to EVAL
 * (which also caches the script server-side) when Redis answers NOSCRIPT.
 */
class LuaScriptRunner
{
    /** @var array<string, array{source: string, sha: string}> */
    private static array $scripts = [];

    public function __construct(private readonly RedisManager $redis) {}

    /**
     * @param  list<string>  $keys
     * @param  list<string|int>  $arguments
     */
    public function run(string $script, array $keys, array $arguments = []): array
    {
        ['source' => $source, 'sha' => $sha] = $this->load($script);

        /** @var \Redis $client */
        $client = $this->redis->connection()->client();
        $args = array_merge($keys, array_map('strval', $arguments));

        $result = $client->evalSha($sha, $args, count($keys));

        if ($result === false && str_starts_with((string) $client->getLastError(), 'NOSCRIPT')) {
            $client->clearLastError();
            $result = $client->eval($source, $args, count($keys));
        }

        if (! is_array($result)) {
            $error = $client->getLastError();
            $client->clearLastError();

            throw new RuntimeException("Lua script [{$script}] failed: ".($error ?? 'unexpected result'));
        }

        return $result;
    }

    /** @return array{source: string, sha: string} */
    private function load(string $script): array
    {
        if (! isset(self::$scripts[$script])) {
            $path = __DIR__."/LuaScripts/{$script}.lua";
            $source = file_get_contents($path) ?: throw new RuntimeException("Lua script [{$script}] not found.");

            self::$scripts[$script] = ['source' => $source, 'sha' => sha1($source)];
        }

        return self::$scripts[$script];
    }
}
