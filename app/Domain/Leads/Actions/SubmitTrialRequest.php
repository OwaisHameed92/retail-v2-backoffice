<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Leads\Data\LeadDetails;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Support\Turnstile;
use App\Domain\Shared\Exceptions\ApiException;
use Illuminate\Cache\RateLimiter;

/**
 * A trial request from the public form API (module 1.10): Turnstile check, 3 requests a day per email address,
 * then CreateLead (source website) with repeat requests merged into the open lead.
 */
class SubmitTrialRequest
{
    public const EMAIL_LIMIT_PER_DAY = 3;

    public const RATE_LIMITED = 'You have already sent us a few requests. Please try again later, or call or email us and we will help straight away.';

    public const CAPTCHA_FAILED = 'We could not confirm you are not a robot. Please tick the check again and resend the form.';

    public function __construct(
        private readonly CreateLead $createLead,
        private readonly Turnstile $turnstile,
        private readonly RateLimiter $limiter,
    ) {}

    /**
     * @throws ApiException captcha.failed (422), rate_limited (429)
     */
    public function handle(LeadDetails $details, ?string $captchaToken): Lead
    {
        if (! $this->turnstile->passes($captchaToken, $details->ip)) {
            throw new ApiException('captcha.failed', self::CAPTCHA_FAILED, 422);
        }

        $bucket = 'trial-request:email:'.hash('sha256', (string) $details->email);

        if ($this->limiter->tooManyAttempts($bucket, self::EMAIL_LIMIT_PER_DAY)) {
            throw new ApiException('rate_limited', self::RATE_LIMITED, 429, $this->limiter->availableIn($bucket));
        }

        $lead = $this->createLead->handle($details, mergeIntoOpenLead: true);

        $this->limiter->hit($bucket, 86400);

        return $lead;
    }
}
