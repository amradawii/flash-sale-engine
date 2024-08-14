-- Atomically cancel a reservation and return its units to the pool.
--
-- KEYS[1] stock   KEYS[2] holds   KEYS[3] reservations   KEYS[4] expirations
-- ARGV[1] user_id ARGV[2] token
--
-- Returns:
--   { 1, quantity, stock }   released
--   {-2, stock }             no such reservation for this shopper

local user_id = ARGV[1]
local token = ARGV[2]

if redis.call('HGET', KEYS[2], user_id) ~= token then
    return { -2, tonumber(redis.call('GET', KEYS[1])) or 0 }
end

local meta = redis.call('HGET', KEYS[3], token)
local quantity = tonumber(string.match(meta, '|(%d+)$'))

redis.call('HDEL', KEYS[2], user_id)
redis.call('HDEL', KEYS[3], token)
redis.call('ZREM', KEYS[4], token)

local stock = redis.call('INCRBY', KEYS[1], quantity)

return { 1, quantity, stock }
