<?php

namespace Tests\Feature\Shared;

use App\Domain\Shared\Support\TableQuery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class TableQueryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $params
     */
    private function table(array $params): TableQuery
    {
        return TableQuery::from(Request::create('/admin/users', 'GET', $params))
            ->searchable(['name', 'email'])
            ->sortable(['name', 'email'])
            ->defaultSort('name');
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Cara', 'Anna', 'Ben'] as $name) {
            User::factory()->create(['name' => $name, 'email' => strtolower($name).'@example.test']);
        }
    }

    public function test_it_sorts_by_a_whitelisted_column(): void
    {
        $result = $this->table(['sort' => 'name', 'direction' => 'desc'])->paginate(User::query(), fn (User $u) => $u->name);

        $this->assertSame(['Cara', 'Ben', 'Anna'], $result['data']);
        $this->assertSame('name', $result['meta']['sort']);
        $this->assertSame('desc', $result['meta']['direction']);
    }

    public function test_unknown_sort_columns_are_ignored(): void
    {
        $table = $this->table(['sort' => 'password', 'direction' => 'desc']);
        $result = $table->paginate(User::query(), fn (User $u) => $u->name);

        $this->assertNull($table->sort());
        $this->assertSame(['Anna', 'Ben', 'Cara'], $result['data']);
        $this->assertStringNotContainsString('password', $table->apply(User::query())->toSql());
    }

    public function test_search_and_pagination_meta(): void
    {
        $result = $this->table(['search' => 'an', 'perPage' => 10, 'page' => 1])->paginate(User::query(), fn (User $u) => $u->name);

        $this->assertSame(['Anna'], $result['data']);
        $this->assertSame(['page' => 1, 'perPage' => 10, 'total' => 1, 'lastPage' => 1, 'search' => 'an', 'sort' => null, 'direction' => 'asc'], $result['meta']);
    }

    public function test_per_page_is_limited_to_allowed_values(): void
    {
        $this->assertSame(25, $this->table(['perPage' => 5000])->perPage());
        $this->assertSame(50, $this->table(['perPage' => 50])->perPage());
        $this->assertSame(1, $this->table(['page' => -3])->page());
    }
}
