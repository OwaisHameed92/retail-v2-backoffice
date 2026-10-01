<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\ContractReplyGuard;
use Tests\Support\SqliteTestSchema;

abstract class TestCase extends BaseTestCase
{
    /** Module 2.6: every till API reply of every test is checked against the EPOS contract after the test. */
    private ?ContractReplyGuard $contractReplies = null;

    /** Test speed: SQLite `:memory:` loads a cached dump of the migrated database instead of migrating. */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        SqliteTestSchema::prime($this->app, $this);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->contractReplies = ContractReplyGuard::watch($this->app);
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
