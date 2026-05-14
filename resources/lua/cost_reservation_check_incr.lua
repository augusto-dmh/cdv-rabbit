-- Atomic cost reservation check-and-increment.
-- KEYS[1] = workspace token bucket key (e.g. "workspace:42:tokens:20260513")
-- ARGV[1] = tokens to reserve (integer)
-- ARGV[2] = daily cap (integer)
-- ARGV[3] = TTL in seconds (26 hours = 93600)
--
-- Returns: {status, current}
--   status 1  = success (reservation granted, counter incremented)
--   status 0  = failure (cap exceeded, counter unchanged)
--   current   = value of the counter after the operation

local key     = KEYS[1]
local reserve = tonumber(ARGV[1])
local cap     = tonumber(ARGV[2])
local ttl     = tonumber(ARGV[3])

local current = tonumber(redis.call('GET', key) or 0)

if (current + reserve) > cap then
    return {0, current}
end

local newval = redis.call('INCRBY', key, reserve)
redis.call('EXPIRE', key, ttl)

return {1, newval}
