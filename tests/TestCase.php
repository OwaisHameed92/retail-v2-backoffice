<?php

namespace Tests;

use App\Domain\Security\Contracts\TwoFactorUser;
use App\Domain\Security\Enums\TwoFactorArea;
use App\Domain\Security\Support\TwoFactorSession;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\ContractReplyGuard;

abstract class TestCase extends BaseTestCase
{
    /** Module 2.6: every till API reply of every test is checked against the EPOS contract after the test. */
    private ?ContractReplyGuard $contractReplies = null;

    /** actingAs() also marks the session as past the two-factor step, unless a test turns it off. */
    private bool $passTwoFactor = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contractReplies = ContractReplyGuard::watch($this->app);
    }

    /**
     * Signed-in test users skip the second sign-in step (as if they had just entered their code), so feature tests
     * of other modules are not sent to the two-factor pages. Two-factor tests call withoutTwoFactorPass() first.
     */
    public function be(Authenticatable $user, $guard = null)
    {
        parent::be($user, $guard);

        $area = TwoFactorArea::tryFrom((string) ($guard ?? config('auth.defaults.guard')));

        if ($this->passTwoFactor && $area !== null && $user instanceof TwoFactorUser) {
            $this->passTwoFactorFor($user, $area);
        }

        return $this;
    }

    /** Marks the session as past the two-factor step for this account (e.g. after Auth::guard()->login()). */
    public function passTwoFactorFor(TwoFactorUser $user, TwoFactorArea|string $area): static
    {
        $area = is_string($area) ? TwoFactorArea::from($area) : $area;
        $this->session([TwoFactorSession::PASSED_KEY.'.'.$area->value => ['id' => (string) $user->getKey(), 'stamp' => $user->twoFactorStamp()]]);

        return $this;
    }

    /** The next actingAs() leaves the two-factor step to be done, like a real password sign-in. */
    public function withoutTwoFactorPass(): static
    {
        $this->passTwoFactor = false;

        return $this;
    }

    protected function tearDown(): void
    {
        $violations = $this->contractReplies?->violations() ?? [];
        $this->contractReplies = null;

        parent::tearDown();

        if ($violations !== []) {
            throw new AssertionFailedError("Till API replies break the EPOS contract (tests/Support/ContractReplyGuard.php):\n".implode("\n", $violations));
        }
    }
}
