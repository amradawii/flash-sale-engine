<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case Reserved = 'reserved';
    case AlreadyHolding = 'already_holding';
    case SoldOut = 'sold_out';
    case LimitReached = 'limit_reached';
    case SaleNotLoaded = 'sale_not_loaded';
    case Confirmed = 'confirmed';
    case Released = 'released';
    case NotFound = 'not_found';
    case Expired = 'expired';

    public function succeeded(): bool
    {
        return in_array($this, [self::Reserved, self::Confirmed, self::Released], true);
    }

    public function httpStatus(): int
    {
        return match ($this) {
            self::Reserved => 201,
            self::Confirmed, self::Released => 200,
            self::AlreadyHolding, self::SoldOut => 409,
            self::LimitReached => 422,
            self::NotFound => 404,
            self::Expired => 410,
            self::SaleNotLoaded => 503,
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::Reserved => 'Units reserved. Confirm before the reservation expires.',
            self::AlreadyHolding => 'You already hold a reservation for this sale.',
            self::SoldOut => 'Sold out.',
            self::LimitReached => 'Quantity exceeds the per-customer limit for this sale.',
            self::SaleNotLoaded => 'This sale is not open yet.',
            self::Confirmed => 'Purchase confirmed. Your order is being saved.',
            self::Released => 'Reservation cancelled and units returned to the pool.',
            self::NotFound => 'Reservation not found.',
            self::Expired => 'Reservation expired; its units are being returned to the pool.',
        };
    }
}
