<?php

namespace Tests\Feature\Ai;

use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Reporting\BusinessDashboardHelpers as H;
use Tests\Feature\Reporting\ReportsHelpers as R;
use Tests\Feature\TillData\TillFixtures;

/**
 * Module 6.2 test data. "Now" is Wed 23 Sept 2026 18:00 London. Kirkgate (AI plan): Leeds one basket on Tue 22 and one
 * on Wed 23, Bradford one on Wed 23; Other Stores (AI plan) one basket on Wed 23. Every basket is the sample's
 * (net £4.53, gross £5.15). No test touches the network: the model is FakeAiClient.
 */
trait PortalAssistantHelpers
{
    use AiTestHelpers;

    public Company $kirkgate;

    public Branch $leeds;

    public Branch $bradford;

    public Company $other;

    public Branch $otherShop;

    public User $owner;

    public function setUpPortal(): void
    {
        Cache::flush();
        $this->travelTo(CarbonImmutable::parse('2026-09-23 18:00', 'Europe/London'));
        $this->installFakeAi();

        [$this->kirkgate, $this->leeds, $this->bradford] = TillFixtures::tenant();
        $this->kirkgate->forceFill(['plan_id' => $this->aiPlan(code: 'ai-kirkgate')->id])->save();
        $this->other = $this->aiCompany(name: 'Other Stores', branchCode: 'OTH', branchName: 'Other shop');
        $this->otherShop = $this->branchOf($this->other);
        $otherTill = Register::withoutCompanyScope()->where('branch_id', $this->otherShop->id)->firstOrFail();

        H::basket($this->kirkgate, $this->leeds, TillFixtures::TILL_1, 610001, '2026-09-22T09:10:00Z');
        H::basket($this->kirkgate, $this->leeds, TillFixtures::TILL_2, 610002, '2026-09-23T10:10:00Z');
        H::basket($this->kirkgate, $this->bradford, TillFixtures::BRADFORD_TILL, 610003, '2026-09-23T11:10:00Z');
        H::basket($this->other, $this->otherShop, $otherTill->id, 610004, '2026-09-23T12:10:00Z');

        $this->owner = $this->portalMember(CompanyRole::Owner);
    }

    public function portalMember(CompanyRole $role, ?Branch $shop = null, ?Company $company = null): User
    {
        return R::member($company ?? $this->kirkgate, $role, $shop?->id);
    }

    /**
     * POST a question and read the server-sent events.
     *
     * @return array{events: list<array{0: string, 1: array<string, mixed>}>, done: array<string, mixed>|null, error: array<string, mixed>|null, text: string}
     */
    public function ask(User $user, string $question, ?string $conversationId = null): array
    {
        $response = $this->actingAs($user)->post('/app/assistant/ask', array_filter(['question' => $question, 'conversationId' => $conversationId]), ['Accept' => 'text/event-stream']);
        $response->assertOk();
        expect($response->headers->get('Content-Type'))->toStartWith('text/event-stream');

        $events = [];

        foreach (preg_split("/\n\n/", trim((string) $response->streamedContent())) ?: [] as $chunk) {
            if (preg_match('/^event: (\w+)\ndata: (.*)$/s', $chunk, $m) === 1) {
                $events[] = [$m[1], (array) json_decode($m[2], true)];
            }
        }

        $find = fn (string $name) => collect($events)->first(fn (array $e) => $e[0] === $name)[1] ?? null;

        return [
            'events' => $events,
            'done' => $find('done'),
            'error' => $find('error'),
            'text' => implode('', array_map(fn (array $e) => $e[1]['text'] ?? '', array_filter($events, fn (array $e) => $e[0] === 'text'))),
        ];
    }

    /**
     * The decoded JSON of the n-th tool result sent back to the model (inside `<tool_data>`).
     *
     * @return array<string, mixed>
     */
    public function toolData(int $index = 0, ?int $request = null): array
    {
        $results = $this->fake->toolResultsIn($request === null ? null : $this->fake->requests[$request]);
        $content = (string) $results[$index]['content'];
        expect($results[$index]['is_error'])->toBeFalse($content);

        return (array) json_decode((string) preg_replace('/^<tool_data tool="[a-z_]+">\n|\n<\/tool_data>$/', '', $content), true);
    }
}
