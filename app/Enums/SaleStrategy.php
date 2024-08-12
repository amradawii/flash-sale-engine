<?php

namespace App\Enums;

enum SaleStrategy: string
{
    case Naive = 'naive';
    case DbLock = 'db_lock';
    case RedisLua = 'redis_lua';

    public function label(): string
    {
        return match ($this) {
            self::Naive => 'Naive (read → check → write)',
            self::DbLock => 'MySQL row lock (SELECT ... FOR UPDATE)',
            self::RedisLua => 'Redis atomic Lua script',
        };
    }
}
