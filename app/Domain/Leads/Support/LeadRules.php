<?php

namespace App\Domain\Leads\Support;

use App\Domain\Leads\Data\LeadDetails;
use App\Domain\Leads\Models\Lead;
use Illuminate\Validation\ValidationException;

/**
 * Invariants of lead details that hold whoever calls the actions (admin form, public form, tests, AI tools).
 * Form requests check formats first; these are the rules the actions refuse to break.
 */
final class LeadRules
{
    /**
     * @throws ValidationException
     */
    public static function ensureValid(LeadDetails $details): void
    {
        $errors = [];

        if ($details->businessName === '') {
            $errors['business_name'] = 'Enter the business name.';
        }

        if ($details->contactName === '') {
            $errors['contact_name'] = 'Enter the contact’s name.';
        }

        if ($details->email === null && $details->phone === null) {
            $errors['email'] = 'Enter an email address or a phone number so we can reach them.';
        }

        if ($details->email !== null && filter_var($details->email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter a valid email address.';
        }

        if ($details->shopsCount < 1 || $details->shopsCount > Lead::MAX_SHOPS) {
            $errors['shops_count'] = 'Enter between 1 and '.Lead::MAX_SHOPS.' shops.';
        }

        if ($details->tillsCount < 1 || $details->tillsCount > Lead::MAX_TILLS) {
            $errors['tills_count'] = 'Enter between 1 and '.Lead::MAX_TILLS.' tills.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
