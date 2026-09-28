<?php

namespace App\Domain\Ai\Enums;

use App\Domain\Plans\Enums\Feature;

/**
 * What an AI call is for. Stored on usage rows and conversations (camelCase, like the other enums), used to pick
 * the model tier and settings (`config('ai.features')`) and to gate on the company's plan.
 */
enum AiFeature: string
{
    case Assistant = 'assistant';
    case MorningSummary = 'morningSummary';
    case ReorderSuggestions = 'reorderSuggestions';
    case InvoiceImport = 'invoiceImport';
    case AnomalyAlerts = 'anomalyAlerts';
    case AdminAssistant = 'adminAssistant';

    public function label(): string
    {
        return match ($this) {
            self::Assistant => 'AI assistant',
            self::MorningSummary => 'Morning summary',
            self::ReorderSuggestions => 'Reorder suggestions',
            self::InvoiceImport => 'Invoice import',
            self::AnomalyAlerts => 'Anomaly alerts',
            self::AdminAssistant => 'Admin AI',
        };
    }

    /**
     * The plan feature a company needs for this AI feature. Null for staff-only features (no plan gate).
     */
    public function planFeature(): ?Feature
    {
        return match ($this) {
            self::Assistant => Feature::AssistQuestions,
            self::InvoiceImport => Feature::AssistInvoiceScan,
            self::MorningSummary, self::ReorderSuggestions, self::AnomalyAlerts => Feature::Assist,
            self::AdminAssistant => null,
        };
    }

    public function isAdmin(): bool
    {
        return $this === self::AdminAssistant;
    }
}
