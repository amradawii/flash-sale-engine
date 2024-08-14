-- Atomically reserve units of a flash sale for one shopper.
--
-- KEYS[1] stock         KEYS[2] holds        KEYS[3] reservations
-- KEYS[4] expirations   KEYS[5] purchased
-- ARGV[1] user_id       ARGV[2] quantity     ARGV[3] ttl_seconds
-- ARGV[4] token         ARGV[5] max_per_user
--
-- Returns:
--   { 1, remaining_stock, token, expires_at }   reserved
--   {-1, stock, existing_token, expires_at }    shopper already holds a reservation
--   { 0, stock }                                sold out
--   {-3, stock }                                quantity exceeds the per-user limit
--   {-4, 0 }                                    sale is not loaded into Redis

local user_id = ARGV[1]
local quantity = tonumber(ARGV[2])
local ttl = tonumber(ARGV[3])
local token = ARGV[4]
local max_per_user = tonumber(ARGV[5])

local stock = tonumber(redis.call('GET', KEYS[1]))
if stock == nil then
    return { -4, 0 }
end

local existing = redis.call('HGET', KEYS[2], user_id)
if existing then
    local expires_at = tonumber(redis.call('ZSCORE', KEYS[4], existing)) or 0
    return { -1, stock, existing, expires_at }
end

local purchased = tonumber(redis.call('HGET', KEYS[5], user_id)) or 0
if quantity < 1 or purchased + quantity > max_per_user then
    return { -3, stock }
end

if stock < quantity then
    return { 0, stock }
end

local now = tonumber(redis.call('TIME')[1])
local expires_at = now + ttl

local remaining = redis.call('DECRBY', KEYS[1], quantity)
redis.call('HSET', KEYS[2], user_id, token)
redis.call('HSET', KEYS[3], token, user_id .. '|' .. quantity)
redis.call('ZADD', KEYS[4], expires_at, token)

return { 1, remaining, token, expires_at }
