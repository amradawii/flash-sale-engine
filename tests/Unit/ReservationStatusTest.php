<?php

use App\Enums\ReservationStatus;

it('maps every outcome to an HTTP status', function (ReservationStatus $status, int $http, bool $succeeded) {
    expect($status->httpStatus())->toBe($http)
        ->and($status->succeeded())->toBe($succeeded)
        ->and($status->message())->not->toBeEmpty();
})->with([
    'reserved' => [ReservationStatus::Reserved, 201, true],
    'confirmed' => [ReservationStatus::Confirmed, 200, true],
    'released' => [ReservationStatus::Released, 200, true],
    'already_holding' => [ReservationStatus::AlreadyHolding, 409, false],
    'sold_out' => [ReservationStatus::SoldOut, 409, false],
    'limit_reached' => [ReservationStatus::LimitReached, 422, false],
    'not_found' => [ReservationStatus::NotFound, 404, false],
    'expired' => [ReservationStatus::Expired, 410, false],
    'sale_not_loaded' => [ReservationStatus::SaleNotLoaded, 503, false],
]);
