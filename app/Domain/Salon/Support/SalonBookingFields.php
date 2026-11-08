<?php

namespace App\Domain\Salon\Support;

use App\Domain\TillData\Registry\EntityDefinition;

/**
 * Who writes which member of a `SalonOnlineBooking` row (salon pack 2026-11-08, salon/README.md §5; W2). The row is
 * hub-owned: the portal makes one per online request for one shop and sends it in that shop's pull; the till, the
 * diary of record, answers on the same row and pushes it back.
 *
 * - The portal writes the request: id, reference, kind, requestedStartsAt, requestedUserId, servicesJson, the client's
 *   name / phone / e-mail, customerId, notes, answersJson, termsAcceptedAt, the three marketing ticks, depositPaid,
 *   paymentReference, expiresAt, and `status` pending / cancelled / expired. For `reschedule`, `cancel` and
 *   `preferences` requests it also names the appointmentId.
 * - The till writes the answer: appointmentId (for `kind: new`), decidedAt, decidedByUserId, decisionReason, and
 *   `status` accepted / declined.
 * - Once the till has answered (or the request ended: cancelled, expired), the row is final: the till's version
 *   wins, and a later portal change is refused (SalonOnlineBookingRules); the till would refuse it too and flag it.
 *
 * Columns are the portal's snake_case names.
 */
final class SalonBookingFields
{
    public const ENTITY = 'SalonOnlineBooking';

    /** Statuses only the till sets: its answer. */
    public const TILL_STATUSES = ['accepted', 'declined'];

    /** Statuses the portal may set. */
    public const PORTAL_STATUSES = ['pending', 'cancelled', 'expired'];

    /** The till's answer members (appointment_id only for `kind: new`). */
    public const TILL_COLUMNS = ['decided_at', 'decided_by_user_id', 'decision_reason'];

    /** The only status in which the portal may still change its request. */
    public const OPEN_STATUS = 'pending';

    public static function applies(EntityDefinition $def): bool
    {
        return $def->entity === self::ENTITY;
    }

    /** The row carries the till's answer (accepted / declined). */
    public static function isAnswer(mixed $status): bool
    {
        return in_array($status, self::TILL_STATUSES, true);
    }

    /**
     * A till's push of the row that carries its answer: always taken, even over a portal change made after the till
     * read the request (the till is the diary of record; README §5 "Take the till's version"). EntityWriter skips its
     * hub-conflict check for it. Any other till change of the row follows the ordinary hub rules.
     *
     * @param  array<string, mixed>|null  $row  the mapped incoming row
     */
    public static function takesTillAnswer(EntityDefinition $def, ?array $row): bool
    {
        return self::applies($def) && $row !== null && self::isAnswer($row['status'] ?? null);
    }

    /**
     * Members the portal may not set on a request of this kind.
     *
     * @return list<string>
     */
    public static function tillColumns(?string $kind): array
    {
        return $kind === 'new' || $kind === null ? [...self::TILL_COLUMNS, 'appointment_id'] : self::TILL_COLUMNS;
    }
}
