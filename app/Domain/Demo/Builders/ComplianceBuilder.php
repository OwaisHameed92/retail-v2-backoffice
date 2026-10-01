<?php

namespace App\Domain\Demo\Builders;

use App\Domain\Demo\Catalogue\DemoPeople;
use App\Domain\Demo\Catalogue\DemoProducts;
use App\Domain\Demo\Support\DemoBusiness;
use App\Domain\Demo\Support\DemoPush;
use App\Domain\Demo\Support\DemoStaff;
use App\Domain\Reporting\Demo\DemoShop;
use Random\Randomizer;

/**
 * The due-diligence records a licensed convenience store keeps: age refusals at the till, incident reports,
 * daily diary checks (fridge and freezer temperatures, opening and closing checks, date codes; a few missed, one
 * fridge too warm), the shop's licences (one renewal due soon) and two product recalls (one open, one closed).
 */
final class ComplianceBuilder
{
    /** Key => [name, category, schedule, hour, value maker]. */
    public const CHECKS = [
        'fridge' => ['Chiller temperature', 'Food safety', 'daily', 8],
        'freezer' => ['Freezer temperature', 'Food safety', 'daily', 8],
        'opening' => ['Opening checks (fire exits, alarm, floors)', 'Health and safety', 'daily', 7],
        'dates' => ['Date code check: chilled and bakery', 'Food safety', 'daily', 9],
        'closing' => ['Closing checks (safe, back door, chillers)', 'Security', 'daily', 21],
        'fire' => ['Weekly fire alarm test', 'Health and safety', 'weekly', 10],
    ];

    public function handle(DemoBusiness $b, DemoPush $push): void
    {
        $ageKeys = array_keys(array_filter(DemoProducts::all(), fn (array $p) => $p['ageRule'] !== 'none'));

        foreach ($b->shops as ['shop' => $shop]) {
            $rng = $b->rng("compliance|{$shop->branchId}");
            $this->refusals($b, $push, $shop, $rng, $ageKeys);
            $this->diary($b, $push, $shop, $rng);
            $this->incidents($b, $push, $shop);
            $this->licences($b, $push, $shop);
        }

        $this->recalls($b, $push);
    }

    /**
     * @param  list<string>  $keys
     */
    private function refusals(DemoBusiness $b, DemoPush $push, DemoShop $shop, Randomizer $rng, array $keys): void
    {
        $names = array_column(DemoStaff::at($b, $shop), 'name', 'id');
        $notes = ['No ID, looked about 16', 'Provisional licence photo did not match', 'Adult outside asked to buy for the group', 'Student card is not accepted ID', ''];

        for ($d = $b->history; $d >= 0; $d--) {
            for ($n = 0, $count = $rng->getInt(0, 3); $n < $count; $n++) {
                $at = $b->at($d, $rng->getInt(15, 21), $rng->getInt(0, 59));

                if ($at > $b->now) {
                    continue;
                }

                $p = DemoProducts::get($keys[$rng->getInt(0, count($keys) - 1)]);
                $user = $shop->cashiers[$rng->getInt(1, 2)];
                $push->add($shop, 'AgeRefusal', $shop->id("refusal|{$d}|{$n}"), [
                    'productId' => $b->id("product|{$p['key']}"), 'productName' => $p['name'], 'ageRule' => $p['ageRule'], 'userId' => $user,
                    'operatorName' => $names[$user] ?? '', 'note' => $notes[$rng->getInt(0, count($notes) - 1)], 'at' => DemoBusiness::iso($at),
                    'registerId' => $shop->registers[0]['id'], 'branchId' => $shop->branchId,
                ], $at);
            }
        }
    }

    private function diary(DemoBusiness $b, DemoPush $push, DemoShop $shop, Randomizer $rng): void
    {
        $position = 0;

        foreach (self::CHECKS as $key => [$name, $category, $schedule, $hour]) {
            $definitionId = $shop->id("diary|{$key}");
            $push->add($shop, 'DiaryCheckDefinition', $definitionId, [
                'name' => $name, 'category' => $category, 'schedule' => $schedule, 'isActive' => true, 'sortOrder' => ++$position, 'branchId' => $shop->branchId,
            ], $b->at($b->history + 20, 9));

            for ($d = $b->history; $d >= 0; $d--) {
                $at = $b->at($d, $hour, $rng->getInt(0, 40));

                if ($at > $b->now || ($schedule === 'weekly' && $at->dayOfWeekIso !== 1) || $rng->nextFloat() < 0.06) {
                    continue; // not due yet, not this week's day, or missed
                }

                [$value, $passed, $note] = match ($key) {
                    'fridge' => $d === 9 ? ['8.6°C', false, 'Door left ajar overnight; stock moved, engineer called'] : [sprintf('%.1f°C', $rng->getInt(18, 46) / 10), true, ''],
                    'freezer' => [sprintf('-%d°C', $rng->getInt(18, 22)), true, ''],
                    'fire' => ['Alarm sounded at all call points', true, ''],
                    default => ['Done', true, ''],
                };
                $push->add($shop, 'DiaryCheckRecord', $shop->id("diary|{$key}|{$d}"), [
                    'diaryCheckDefinitionId' => $definitionId, 'recordedAt' => DemoBusiness::iso($at), 'recordedByUserId' => $key === 'closing' ? $shop->cashiers[2] : $shop->cashiers[0],
                    'value' => $value, 'passed' => $passed, 'note' => $note, 'branchId' => $shop->branchId,
                ], $at);
            }
        }
    }

    private function incidents(DemoBusiness $b, DemoPush $push, DemoShop $shop): void
    {
        // The first is the theft, claimed on the shop insurance.
        $incidents = [
            [26, 'Theft', 'Two males took four bottles of spirits from the shelf and left without paying. CCTV saved.', true],
            [17, 'Abuse', 'Customer became abusive after being refused alcohol without ID. Left when asked.', false],
            [9, 'Accident', 'Customer slipped on wet floor near the chiller; no injury. Wet floor sign put out, floor dried.', false],
            [3, 'Counterfeit', 'A counterfeit £20 note was found in the till at cash-up. Note kept for the police.', true],
        ];

        foreach ($incidents as $i => [$daysAgo, $category, $description, $police]) {
            $at = $b->at(min($daysAgo, $b->history), 14 + $i, 20);
            $push->add($shop, 'IncidentReport', $shop->id("incident|{$i}"), [
                'occurredAt' => DemoBusiness::iso($at), 'category' => $category, 'description' => $description, 'reportedByUserId' => DemoStaff::manager($shop),
                'policeReference' => $police ? 'WY'.(1300000000 + abs(crc32($shop->id("incident|{$i}"))) % 99999999) : '',
                'insurerReference' => $i === 0 ? 'CLM-'.(abs(crc32($shop->branchId)) % 900000 + 100000) : '', 'branchId' => $shop->branchId,
            ], $at->addHour());
        }
    }

    private function licences(DemoBusiness $b, DemoPush $push, DemoShop $shop): void
    {
        $owner = DemoPeople::owner($b->company->name);
        $licences = [
            ['Premises licence (alcohol)', 'PL/'.(1000 + abs(crc32($shop->branchId)) % 9000), $owner, 1900, null],
            ['Personal licence', 'PERS/'.(20000 + abs(crc32($shop->branchId.'p')) % 70000), $owner, 2400, null],
            ['Food business registration', 'FBR-'.(abs(crc32($shop->branchId.'f')) % 90000 + 10000), $owner, 1500, null],
            ['PPL PRS music licence', 'TML'.(abs(crc32($shop->branchId.'m')) % 9000000 + 1000000), $owner, 345, 20],
            ['National Lottery retailer agreement', 'NL-'.(abs(crc32($shop->branchId.'l')) % 900000 + 100000), $owner, 700, 400],
        ];

        foreach ($licences as $i => [$type, $number, $holder, $issuedAgo, $expiresIn]) {
            $push->add($shop, 'ComplianceLicence', $shop->id("licence|{$i}"), [
                'licenceType' => $type, 'number' => $number, 'holderName' => $holder, 'issuedOn' => $b->date($issuedAgo),
                'expiresOn' => $expiresIn === null ? null : $b->date(-$expiresIn), 'notes' => $expiresIn === 20 ? 'Renewal invoice received, pay before expiry' : '',
                'branchId' => $shop->branchId,
            ], $b->at($b->history + 20, 10));
        }
    }

    private function recalls(DemoBusiness $b, DemoPush $push): void
    {
        $recalls = [
            ['meat', 'peperami-original-25g', 'Possible presence of small pieces of plastic', 'open', 4],
            ['dairy', 'philadelphia-original-180g', 'Undeclared egg (allergen) on the label', 'closed', 30],
        ];

        foreach ($recalls as $i => [, $key, $reason, $status, $daysAgo]) {
            $p = DemoProducts::all()[$key] ?? DemoProducts::get('sandwich');
            $raised = $b->at(min($daysAgo, $b->history), 9, 5);
            $closed = $raised->addDays(5);
            $push->add($b->main(), 'ProductRecall', $b->id("recall|{$i}"), [
                'reference' => 'FSA-PRIN-'.(30 + $i).'-2026', 'productId' => $b->id("product|{$p['key']}"), 'productName' => $p['name'],
                'batchCode' => 'L'.(6200 + $i * 37), 'expiryFrom' => $b->date(-2), 'expiryTo' => $b->date(-12), 'source' => 'Food Standards Agency',
                'reason' => $reason, 'status' => $status, 'supplierId' => $b->id('supplier|booker'), 'returnedQty' => $status === 'closed' ? 6 : 0,
                'raisedByUserId' => $b->id('user|owner'), 'raisedAt' => DemoBusiness::iso($raised), 'closedByUserId' => $status === 'closed' ? $b->id('user|owner') : '',
                'closedAt' => $status === 'closed' ? DemoBusiness::iso($closed) : null,
                'note' => $status === 'closed' ? 'All affected stock returned to Booker for credit' : 'Shelves checked: 2 packs of the batch found and quarantined', 'scope' => 'company',
            ], $status === 'closed' ? $closed : $raised);
        }
    }
}
