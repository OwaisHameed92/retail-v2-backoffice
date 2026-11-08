<?php

namespace App\Domain\Pharmacy\Enums;

/** What a run of the weekly dm+d fetch did (W4). */
enum DmdFetchOutcome: string
{
    /** A new week was stored and the manifest now names it. */
    case Published = 'published';

    /** TRUD's latest release is the one we already hold. */
    case Unchanged = 'unchanged';

    /** TRUD could not be read, or the zip failed its checks: nothing changed. */
    case Failed = 'failed';

    /** Not run: the switch is off, no TRUD key is set, or another run holds the lock. Never stored. */
    case Skipped = 'skipped';
}
