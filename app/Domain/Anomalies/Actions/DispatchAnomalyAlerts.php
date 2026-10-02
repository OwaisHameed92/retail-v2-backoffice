<?php

namespace App\Domain\Anomalies\Actions;

use App\Domain\Anomalies\Enums\AnomalySeverity;
use App\Domain\Anomalies\Enums\AnomalyStatus;
use App\Domain\Anomalies\Models\Anomaly;
use App\Domain\Anomalies\Support\AnomalyVisibility;
use App\Domain\Mail\Data\AnomalyAlertData;
use App\Domain\Mail\Mailables\AnomalyAlertMail;
use App\Domain\Notifications\Enums\AlertDelivery;
use App\Domain\Notifications\Enums\AlertType;
use App\Domain\Notifications\Models\AlertDispatch;
use App\Domain\Notifications\Support\AlertInbox;
use App\Domain\Notifications\Support\AlertLinks;
use App\Domain\Notifications\Support\AlertRecipients;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

/**
 * Straight-away alerts for serious findings (module 6.6). For each high-severity finding not yet alerted and every
 * member who has "Unusual activity" on, covers the shop and may see it (staff-level: owners and managers): a bell
 * entry, plus an email for "straight away" users. Once per finding and user (`alert_dispatches`, key
 * `anomaly|<id>`). Everything below high waits for the 07:00 digest.
 */
class DispatchAnomalyAlerts
{
    /**
     * @param  list<Anomaly>  $anomalies
     * @return array{notified: int, emailed: int}
     */
    public function handle(Company $company, array $anomalies, CarbonImmutable $now): array
    {
        $totals = ['notified' => 0, 'emailed' => 0];
        $due = array_filter($anomalies, fn (Anomaly $a) => $a->severity === AnomalySeverity::High && $a->notified_at === null && $a->status !== AnomalyStatus::Dismissed);

        if ($due === []) {
            return $totals;
        }

        $recipients = AlertRecipients::for($company->id);
        $shops = Branch::withoutCompanyScope()->where('company_id', $company->id)->pluck('name', 'id')->all();

        foreach ($due as $anomaly) {
            $sent = AlertDispatch::withoutCompanyScope()->where('company_id', $company->id)->where('subject_key', 'anomaly|'.$anomaly->id)->pluck('user_id')->all();

            foreach ($recipients as $r) {
                if (in_array($r->userId, $sent, true) || ! $r->wants(AlertType::UnusualActivity) || ! $r->covers($anomaly->branch_id)
                    || ! AnomalyVisibility::canSee($anomaly, $r->role, $r->restrictedBranchId)) {
                    continue;
                }

                $url = AlertLinks::portal('/app/anomalies/'.$anomaly->id);
                AlertInbox::add($company->id, $r->userId, AlertType::UnusualActivity, 'danger', $anomaly->title, $anomaly->summary, $url);
                $totals['notified']++;

                if ($r->delivery(AlertType::UnusualActivity) === AlertDelivery::Immediate) {
                    Mail::to($r->email, $r->name)->queue(new AnomalyAlertMail(new AnomalyAlertData(
                        businessName: $company->name,
                        recipientName: $r->name,
                        title: $anomaly->title,
                        summary: $anomaly->summary,
                        shopName: (string) ($shops[$anomaly->branch_id] ?? 'your shop'),
                        kindLabel: $anomaly->kind->label(),
                        facts: self::facts($anomaly),
                        url: $url,
                        unsubscribeUrl: AlertLinks::unsubscribe($company->id, $r->userId, AlertType::UnusualActivity),
                        settingsUrl: AlertLinks::settings(),
                        companyId: $company->id,
                    )));
                    $totals['emailed']++;
                }

                AlertDispatch::withoutCompanyScope()->create([
                    'company_id' => $company->id, 'user_id' => $r->userId, 'alert_type' => AlertType::UnusualActivity->value,
                    'subject_key' => 'anomaly|'.$anomaly->id, 'state' => AlertDispatch::SENT, 'notified_at' => $now,
                ]);
            }

            $anomaly->forceFill(['notified_at' => $now])->save();
        }

        return $totals;
    }

    /**
     * @return array<string, string>
     */
    private static function facts(Anomaly $anomaly): array
    {
        $out = [];

        foreach ($anomaly->facts as $fact) {
            $usual = array_filter(['usual '.($fact['usual'] ?? ''), 'team '.($fact['peers'] ?? '')], fn (string $s) => ! str_ends_with($s, ' '));
            $out[$fact['label']] = $fact['value'].($usual === [] ? '' : ' ('.implode(', ', $usual).')');
        }

        return $out;
    }
}
