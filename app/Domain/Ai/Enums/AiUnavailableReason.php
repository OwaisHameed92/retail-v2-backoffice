<?php

namespace App\Domain\Ai\Enums;

/**
 * Why an AI call could not be made. The UI can branch on the code; the message is safe to show.
 */
enum AiUnavailableReason: string
{
    case Disabled = 'disabled';
    case NotConfigured = 'notConfigured';
    case CompanyInactive = 'companyInactive';
    case NotInPlan = 'notInPlan';
    case BudgetExhausted = 'budgetExhausted';
    case RateLimited = 'rateLimited';
    case ProviderError = 'providerError';
}
