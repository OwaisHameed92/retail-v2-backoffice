<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Exceptions\CompanyMismatch;
use App\Domain\Tenancy\Exceptions\MissingCurrentCompany;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\Note;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase, TenancyTestHelpers;

    private Company $companyA;

    private Company $companyB;

    private Note $noteA;

    private Note $noteB;

    protected function setUp(): void
    {
        parent::setUp();

        Note::createTable();

        $this->companyA = Company::factory()->create(['name' => 'Alpha Stores']);
        $this->companyB = Company::factory()->create(['name' => 'Bravo Mart']);

        $this->noteA = Note::create(['company_id' => $this->companyA->id, 'body' => 'A note']);
        $this->noteB = Note::create(['company_id' => $this->companyB->id, 'body' => 'B note']);

        Route::middleware(['web', 'auth', 'company'])->group(function () {
            Route::get('/_test/notes', fn () => Note::query()->orderBy('body')->pluck('body'));
            Route::get('/_test/notes/{id}', fn (string $id) => Note::findOrFail($id)->body);
            Route::post('/_test/notes', fn () => Note::create(['body' => request('body')])->company_id);
        });
    }

    public function test_user_of_company_a_only_sees_company_a_rows(): void
    {
        $user = $this->memberOf($this->companyA);

        $this->actingAs($user)->get('/_test/notes')->assertOk()->assertExactJson(['A note']);
    }

    public function test_user_of_company_a_cannot_load_company_b_row_by_id(): void
    {
        $user = $this->memberOf($this->companyA);

        $this->actingAs($user)->get('/_test/notes/'.$this->noteB->id)->assertNotFound();
        $this->actingAs($user)->get('/_test/notes/'.$this->noteA->id)->assertOk()->assertSee('A note');
    }

    public function test_create_auto_fills_company_id_from_current_company(): void
    {
        $user = $this->memberOf($this->companyB, CompanyRole::Staff);

        $this->actingAs($user)->post('/_test/notes', ['body' => 'New'])->assertOk()->assertSee($this->companyB->id);

        $this->assertSame($this->companyB->id, Note::withoutCompanyScope()->where('body', 'New')->value('company_id'));
    }

    public function test_query_without_current_company_throws(): void
    {
        $this->expectException(MissingCurrentCompany::class);

        Note::query()->get();
    }

    public function test_create_without_current_company_or_company_id_throws(): void
    {
        $this->expectException(MissingCurrentCompany::class);

        Note::create(['body' => 'Orphan']);
    }

    public function test_cannot_create_a_row_for_another_company_while_a_company_is_current(): void
    {
        $this->expectException(CompanyMismatch::class);

        app(CurrentCompany::class)->runAs($this->companyA, fn () => Note::create([
            'company_id' => $this->companyB->id,
            'body' => 'Sneaky',
        ]));
    }

    public function test_cannot_move_a_row_to_another_company(): void
    {
        $this->expectException(CompanyMismatch::class);

        app(CurrentCompany::class)->runAs($this->companyA, function () {
            $note = Note::findOrFail($this->noteA->id);
            $note->company_id = $this->companyB->id;
            $note->save();
        });
    }

    public function test_without_company_scope_sees_all_rows(): void
    {
        $this->assertSame(2, Note::withoutCompanyScope()->count());

        app(CurrentCompany::class)->runAs($this->companyA, function () {
            $this->assertSame(1, Note::count());
            $this->assertSame(2, Note::withoutCompanyScope()->count());
        });
    }

    public function test_run_as_scopes_to_the_company_and_restores_the_previous_state(): void
    {
        $current = app(CurrentCompany::class);

        $bodies = $current->runAs($this->companyB, fn () => Note::pluck('body')->all());

        $this->assertSame(['B note'], $bodies);
        $this->assertFalse($current->has());
    }
}
