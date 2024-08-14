-- Atomically turn a live reservation into a confirmed order.
-- The order is appended to the outbox stream in the same step that removes the
-- reservation, so a crash can never lose a confirmed order or leak stock.
--
-- KEYS[1] stock         KEYS[2] holds        KEYS[3] reservations
-- KEYS[4] expirations   KEYS[5] purchased    KEYS[6] orders (stream)
-- ARGV[1] user_id       ARGV[2] token        ARGV[3] sale_id
--
-- Returns:
--   { 1, quantity, stream_entry_id, stock }   confirmed
--   {-2, stock }                              no such reservation for this shopper
--   {-5, stock }                              reservation expired (reclaim returns the stock)

local user_id = ARGV[1]
local token = ARGV[2]
local stock = tonumber(redis.call('GET', KEYS[1])) or 0

if redis.call('HGET', KEYS[2], user_id) ~= token then
    return { -2, stock }
end

local now = tonumber(redis.call('TIME')[1])
local expires_at = tonumber(redis.call('ZSCORE', KEYS[4], token))
if expires_at == nil or expires_at <= now then
    return { -5, stock }
end

local meta = redis.call('HGET', KEYS[3], token)
local quantity = tonumber(string.match(meta, '|(%d+)$'))

redis.call('HDEL', KEYS[2], user_id)
redis.call('HDEL', KEYS[3], token)
redis.call('ZREM', KEYS[4], token)
redis.call('HINCRBY', KEYS[5], user_id, quantity)

local entry_id = redis.call('XADD', KEYS[6], '*',
    'sale_id', ARGV[3],
    'user_id', user_id,
    'token', token,
    'quantity', quantity,
    'confirmed_at', now)

return { 1, quantity, entry_id, stock }
