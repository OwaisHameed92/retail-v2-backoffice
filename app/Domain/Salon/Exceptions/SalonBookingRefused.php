<?php

namespace App\Domain\Salon\Exceptions;

use DomainException;

/**
 * A portal change to a `SalonOnlineBooking` row that the field ownership forbids (SalonBookingFields): the till's
 * answer members, an answer status, a request the till has already answered (or that has ended), or a request moved
 * to another shop. W3's booking page catches it and tells the client to ring the shop.
 */
final class SalonBookingRefused extends DomainException {}
