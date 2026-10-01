<?php

namespace App\Domain\Ai\MorningSummary\Actions;

use App\Domain\Ai\MorningSummary\Data\CompanyFacts;
use App\Domain\Ai\MorningSummary\Data\SummaryAudience;
use App\Domain\Ai\MorningSummary\Models\MorningSummary;
use App\Domain\Ai\MorningSummary\Support\SummaryView;
use App\Domain\Notifications\Data\Recipient;
use App\Domain\Notifications\Enums\AlertType;
use App\Domain\Notifications\Support\AlertLinks;
use App\Domain\Tenancy\Models\Company;

/**
 * One user's morning summary for the 07:00 email (module 6.3): the business's facts cut to their shops and role
 * (SummaryView), skipped when there is no news; the AI paragraph written once per distinct set of facts (a re-run, or
 * a second user seeing the same shops, reuses it: one metered call, not one per user); stored for the dashboard.
 */
class BuildMorningSummary
{
    /** @var array<string, array{text: string|null, status: string, model: string|null}> */
    private array $written = [];

    public function __construct(private readonly WriteMorningNarrative $writer) {}

    /**
     * @return array<string, mixed>|null View + narrative, url, unsubscribeUrl; null when there is nothing to say
     */
    public function handle(Company $company, CompanyFacts $facts, Recipient $recipient): ?array
    {
        $audience = SummaryAudience::forRecipient($recipient);
        $view = SummaryView::build($facts, $audience);

        if ($view === null || ! SummaryView::hasNews($view)) {
            return null;
        }

        $modelFacts = SummaryView::forModel($view);
        $hash = hash('sha256', (string) json_encode($modelFacts));
        $narrative = $this->narrative($company, $facts->day, $hash, $modelFacts);

        MorningSummary::withoutCompanyScope()->updateOrCreate(
            ['company_id' => $company->id, 'user_id' => $recipient->userId, 'trading_day' => $facts->day],
            ['scope_key' => $audience->scopeKey(), 'facts_hash' => $hash, 'facts' => $view, 'narrative' => $narrative['text'],
                'narrative_status' => $narrative['status'], 'model' => $narrative['model']],
        );

        return [
            ...$view,
            'narrative' => $narrative['text'],
            'url' => AlertLinks::portal('/app'),
            'unsubscribeUrl' => AlertLinks::unsubscribe($company->id, $recipient->userId, AlertType::MorningSummary),
        ];
    }

    /**
     * @param  array<string, mixed>  $modelFacts
     * @return array{text: string|null, status: string, model: string|null}
     */
    private function narrative(Company $company, string $day, string $hash, array $modelFacts): array
    {
        $key = $company->id.'|'.$day.'|'.$hash;

        if (isset($this->written[$key])) {
            return $this->written[$key];
        }

        $stored = MorningSummary::withoutCompanyScope()->where('company_id', $company->id)->where('trading_day', $day)
            ->where('facts_hash', $hash)->where('narrative_status', MorningSummary::WRITTEN)->whereNotNull('narrative')->first();

        return $this->written[$key] = $stored !== null
            ? ['text' => $stored->narrative, 'status' => MorningSummary::WRITTEN, 'model' => $stored->model]
            : $this->writer->handle($company, $modelFacts);
    }
}
