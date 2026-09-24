<?php

namespace Database\Seeders;

use App\Domain\Leads\Enums\BusinessType;
use App\Domain\Leads\Enums\LeadNoteKind;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadNote;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Local demo leads (module 1.6): realistic UK convenience prospects in every status, with timelines. Local and
 * testing only; no emails are sent (rows are written directly, not through CreateLead).
 */
class LeadSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing') || Lead::query()->withTrashed()->exists()) {
            return;
        }

        $now = CarbonImmutable::now();

        $leads = [
            ['Patel News & Booze', 'Imran Patel', 'imran@patelnews.co.uk', '07700 900123', 'Leeds', 'LS6 2AB', 2, 3, BusinessType::OffLicence, 'Paper till roll and a cash drawer', LeadSource::Website, LeadStatus::New, 0, null, 'We are moving from our old EPOS next month and would like to try it in the Leeds shop first.'],
            ['Singh Family Stores', 'Harpreet Singh', 'harpreet@singhstores.co.uk', '07700 900456', 'Wolverhampton', 'WV1 4AN', 1, 2, BusinessType::Convenience, 'ICR Touch', LeadSource::Phone, LeadStatus::New, 1, 1, null],
            ['Corner Shop Hebden Bridge', 'Rachel Moore', 'rachel@cornershophb.co.uk', '07700 900789', 'Hebden Bridge', 'HX7 8AD', 1, 1, BusinessType::Newsagent, null, LeadSource::Referral, LeadStatus::Contacted, 4, -1, 'Recommended by Khan Mini Mart.'],
            ['Ahmed Food & Wine', 'Yusuf Ahmed', 'yusuf@ahmedfoodandwine.co.uk', '07700 900321', 'Bradford', 'BD1 1SQ', 3, 5, BusinessType::Convenience, 'Booker EPOS', LeadSource::Website, LeadStatus::Contacted, 6, 2, 'Three shops in Bradford and Keighley. Need stock control across all three.'],
            ['Green Lane Service Station', 'Tom Hughes', 'tom@greenlaneservices.co.uk', '07700 900654', 'Sheffield', 'S3 8SS', 1, 2, BusinessType::Forecourt, 'Fuel POS', LeadSource::WalkIn, LeadStatus::Rejected, 12, null, null],
            ['Dhillon Grocers', 'Manpreet Dhillon', 'manpreet@dhillongrocers.co.uk', '07700 900987', 'Leicester', 'LE1 3PL', 1, 2, BusinessType::Grocery, null, LeadSource::Website, LeadStatus::New, 0, 0, 'Can you do scales integration?'],
        ];

        foreach ($leads as [$business, $contact, $email, $phone, $town, $postcode, $shops, $tills, $type, $system, $source, $status, $daysAgo, $followUpDays, $message]) {
            $created = $now->subDays($daysAgo)->subHours(3);
            $contacted = $status === LeadStatus::Contacted;
            $rejected = $status === LeadStatus::Rejected;

            $lead = new Lead([
                'business_name' => $business,
                'contact_name' => $contact,
                'email' => $email,
                'phone' => $phone,
                'town' => $town,
                'postcode' => $postcode,
                'shops_count' => $shops,
                'tills_count' => $tills,
                'business_type' => $type,
                'current_system' => $system,
                'message' => $message,
                'source' => $source,
                'consent_marketing' => $source === LeadSource::Website,
            ]);
            $lead->status = $status;
            $lead->follow_up_at = $followUpDays === null ? null : $now->addDays($followUpDays)->setTime(10, 0);
            $lead->created_at = $created;
            $lead->updated_at = $created;

            if ($contacted) {
                $lead->contacted_at = $created->addDay();
                $lead->last_contacted_at = $created->addDay();
            }

            if ($rejected) {
                $lead->rejection_reason = 'Needs fuel pump integration, which SSPOS does not do.';
                $lead->rejected_at = $created->addDays(2);
            }

            $lead->save();

            $this->note($lead, LeadNoteKind::Created, 'Trial request received from the '.mb_strtolower($source->label()), $created);

            if ($contacted) {
                $this->note($lead, LeadNoteKind::StatusChanged, 'Marked as contacted: spoke to '.explode(' ', $contact)[0].', keen to start after the bank holiday', $created->addDay());
            }

            if ($rejected) {
                $this->note($lead, LeadNoteKind::StatusChanged, 'Rejected: '.$lead->rejection_reason, $created->addDays(2));
            }
        }
    }

    private function note(Lead $lead, LeadNoteKind $kind, string $body, CarbonImmutable $at): void
    {
        $note = new LeadNote(['lead_id' => $lead->id, 'kind' => $kind, 'body' => $body]);
        $note->created_at = $at;
        $note->save();
    }
}
