<?php

namespace App\Enums;

enum InventoryEventType: string
{
    case Reserved = 'reserved';
    case Released = 'released';
    case Expired = 'expired';
    case Confirmed = 'confirmed';
    case Persisted = 'persisted';
    case SoldOut = 'sold_out';
    case Sync = 'sync';

    /** High-frequency events are rate limited during bursts; the rest always go out. */
    public function throttled(): bool
    {
        return in_array($this, [self::Reserved, self::Confirmed, self::Persisted], true);
    }
}
