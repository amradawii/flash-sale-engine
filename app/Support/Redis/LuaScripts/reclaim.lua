-- Atomically reclaim expired reservations and return their units to the pool.
--
-- KEYS[1] stock   KEYS[2] holds   KEYS[3] reservations   KEYS[4] expirations
-- ARGV[1] batch limit
--
-- Returns: { expired_count, units_reclaimed, stock, active_reservations }

local now = tonumber(redis.call('TIME')[1])
local tokens = redis.call('ZRANGE', KEYS[4], '-inf', now, 'BYSCORE', 'LIMIT', 0, tonumber(ARGV[1]))

local units = 0
for _, token in ipairs(tokens) do
    local meta = redis.call('HGET', KEYS[3], token)
    if meta then
        local user_id, quantity = string.match(meta, '^(.-)|(%d+)$')
        units = units + tonumber(quantity)
        redis.call('HDEL', KEYS[3], token)
        if redis.call('HGET', KEYS[2], user_id) == token then
            redis.call('HDEL', KEYS[2], user_id)
        end
    end
    redis.call('ZREM', KEYS[4], token)
end

local stock
if units > 0 then
    stock = redis.call('INCRBY', KEYS[1], units)
else
    stock = tonumber(redis.call('GET', KEYS[1])) or 0
end

return { #tokens, units, stock, redis.call('ZCARD', KEYS[4]) }
