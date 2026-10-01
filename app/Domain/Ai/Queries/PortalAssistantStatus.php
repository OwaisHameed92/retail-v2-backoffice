<?php

namespace App\Domain\Ai\Queries;

use App\Domain\Ai\AiContext;
use App\Domain\Ai\Exceptions\AiUnavailable;
use App\Domain\Ai\Models\AiConversation;
use App\Domain\Ai\Support\AiGate;
use App\Domain\Ai\Support\Portal\PortalPresenter;
use App\Domain\Ai\Tools\ToolRegistry;
use App\Domain\Tenancy\Models\Branch;

/**
 * What the assistant panel shows before a question (module 6.2): whether it can answer (and if not, why, in words
 * safe to show: not set up yet, not in the plan, allowance used...), this month's allowance, the user's recent
 * conversations, the shop a one-shop user is limited to, and example questions for the tools this user may use.
 */
final class PortalAssistantStatus
{
    private const EXAMPLES = [
        'get_sales' => 'How did sales this week compare with last week?',
        'get_product_sales' => 'What were my top 10 sellers in the last 30 days?',
        'get_stock' => 'What is low on stock right now?',
        'get_refunds_and_voids' => 'Any unusual refunds or voids yesterday?',
        'get_cash_variances' => 'Were any tills short at cash-up this week?',
        'get_customers_owing' => 'Which customers owe us money?',
        'get_staff_hours' => 'How many hours did staff work last week?',
        'get_vat_summary' => 'What is the VAT on sales for last month?',
        'get_till_health' => 'Are all my tills online and syncing?',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function for(AiContext $context): array
    {
        $company = $context->company;
        $available = true;
        $reason = null;
        $message = null;

        try {
            app(AiGate::class)->ensureAvailable($context);
        } catch (AiUnavailable $e) {
            [$available, $reason, $message] = [false, $e->reason->value, $e->getMessage()];
        }

        $tools = array_map(fn ($tool) => $tool->name(), app(ToolRegistry::class)->availableFor($context));
        $restricted = $context->restrictedBranchId();

        return [
            'available' => $available,
            'reason' => $reason,
            'message' => $message,
            'usage' => $company === null ? null : array_intersect_key(AiUsageSummary::for($company), array_flip(['used', 'limit', 'percent', 'resetsOn'])),
            'shop' => $restricted === null ? null : (Branch::query()->withTrashed()->find($restricted)->name ?? 'Your shop'),
            'examples' => array_values(array_intersect_key(self::EXAMPLES, array_flip($tools))),
            'conversations' => AiConversation::query()->ownedBy($context)->orderByDesc('last_message_at')->limit(30)->get()
                ->map(fn (AiConversation $c) => PortalPresenter::conversation($c))->values()->all(),
        ];
    }
}
