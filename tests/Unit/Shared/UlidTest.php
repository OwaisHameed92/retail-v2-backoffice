<?php

namespace Tests\Unit\Shared;

use App\Domain\Shared\Rules\ValidUlid;
use App\Domain\Shared\Support\Ulid;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class UlidTest extends TestCase
{
    public function test_new_ulids_are_valid_and_unique(): void
    {
        $a = Ulid::new();
        $b = Ulid::new();

        $this->assertSame(26, strlen($a));
        $this->assertTrue(Ulid::isValid($a));
        $this->assertNotSame($a, $b);
    }

    public function test_it_validates_till_ids(): void
    {
        $this->assertTrue(Ulid::isValid('01K5VB000000000SR001000482'));

        foreach (['', '01K5VB000000000SR00100048', '01K5VB000000000SR0010004822', '01k5vb000000000sr001000482', '01K5VB000000000SR00100048I', '81K5VB000000000SR001000482', null, 123] as $bad) {
            $this->assertFalse(Ulid::isValid($bad), var_export($bad, true));
        }
    }

    public function test_the_validation_rule(): void
    {
        $ok = Validator::make(['id' => '01K5VB000000000SR001000482'], ['id' => [new ValidUlid]]);
        $bad = Validator::make(['id' => 'not-a-ulid'], ['id' => [new ValidUlid]]);

        $this->assertTrue($ok->passes());
        $this->assertTrue($bad->fails());
        $this->assertSame('The id must be a 26-character ULID.', $bad->errors()->first('id'));
    }
}
