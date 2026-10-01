import { SectionCard } from '@/components/shared/section-card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { sendJson } from '@/lib/http';
import { Info, Loader2, Sparkles } from 'lucide-react';
import { useState } from 'react';
import { FLAGS, qty } from './suggestion-format';
import { type SuggestionLine } from './suggestion-types';

interface NotesProps {
    lines: SuggestionLine[];
    total: number;
    aiAvailable: boolean;
    /** The page's filters, sent with the AI request so the note covers the same lines. */
    query: Record<string, string>;
    showShop: boolean;
}

/** "Worth a look": the lines our own checks flagged, and an optional short AI note on them (module 6.4). */
export function SuggestionNotes({ lines, total, aiAvailable, query, showShop }: NotesProps) {
    const [note, setNote] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);

    if (lines.length === 0) {
        return null;
    }

    const summarise = async () => {
        setLoading(true);
        setError(null);
        const url = route('app.purchasing.suggestions.note', query);
        const result = await sendJson<{ note: string | null; message?: string }>('POST', url);
        setLoading(false);
        if (result.ok && result.data?.note) {
            setNote(result.data.note);
        } else {
            setError(result.data?.message ?? result.message ?? 'The AI note could not be written. Try again later.');
        }
    };

    return (
        <SectionCard
            title="Worth a look"
            description={`${total} ${total === 1 ? 'line stands' : 'lines stand'} out: check these before you order.`}
            actions={
                aiAvailable && (
                    <Button type="button" variant="outline" size="sm" onClick={summarise} disabled={loading}>
                        {loading ? <Loader2 className="animate-spin" /> : <Sparkles />}
                        {note ? 'Summarise again' : 'Summarise with AI'}
                    </Button>
                )
            }
        >
            <div className="grid gap-4">
                {note && (
                    <div className="border-primary/15 bg-primary-soft rounded-md border p-3 text-sm">
                        <p className="text-primary mb-1 flex items-center gap-1.5 text-xs font-medium">
                            <Sparkles className="size-3.5" /> AI note · check the figures below before acting on it
                        </p>
                        <p className="whitespace-pre-line">{note}</p>
                    </div>
                )}
                {error && (
                    <Alert variant="info">
                        <Info />
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}
                <ul className="divide-y">
                    {lines.map((line) => (
                        <li key={line.key} className="flex flex-col gap-1 py-2 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between">
                            <div className="min-w-0">
                                <span className="font-medium">{line.name}</span>
                                <span className="text-muted-foreground">
                                    {showShop ? ` · ${line.shopName}` : ''} · {line.supplierName}
                                </span>
                                <div className="text-muted-foreground text-xs">
                                    {qty(line.onHand)} on hand
                                    {line.coverDays !== null ? `, about ${qty(line.coverDays)} days of cover` : ''}
                                    {line.suggestedCases > 0 ? ` · suggested ${line.suggestedCases} × ${line.caseQty}` : ' · nothing suggested'}
                                </div>
                            </div>
                            <div className="flex flex-wrap gap-1">
                                {line.flags
                                    .filter((f) => f !== 'noHistory' && f !== 'capped' && f !== 'outOfStock')
                                    .map((flag) => (
                                        <Badge key={flag} variant={FLAGS[flag].tone} className="text-[11px]">
                                            {FLAGS[flag].label}
                                        </Badge>
                                    ))}
                            </div>
                        </li>
                    ))}
                </ul>
            </div>
        </SectionCard>
    );
}
