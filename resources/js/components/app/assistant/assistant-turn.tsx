import { StatusBadge } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { AlertCircle, ArrowUpRight, Loader2, ShieldCheck, Sparkles } from 'lucide-react';
import { useState } from 'react';
import { AssistantCopyButton } from './assistant-parts';
import { AssistantText } from './assistant-text';
import type { AssistantLink, AssistantProposal, AssistantTurn as Turn } from './types';

const PROPOSAL_TONES = { pending: 'warning', confirmed: 'success', cancelled: 'neutral', expired: 'neutral', failed: 'danger' } as const;
const PROPOSAL_LABELS = { pending: 'Waiting for you', confirmed: 'Done', cancelled: 'Cancelled', expired: 'Expired', failed: 'Failed' } as const;

/** Opens a linked page. Report pages read the shop from the top-bar switcher, so switch it first when needed. */
export function openLink(link: AssistantLink, onNavigate: () => void) {
    onNavigate();
    if (link.switchShop) {
        router.post('/app/branch/switch', { branch_id: link.shopId ?? '' }, { preserveScroll: true, onFinish: () => router.visit(link.href) });
    } else {
        router.visit(link.href);
    }
}

function Proposal({
    proposal,
    onDecide,
    onNavigate,
}: {
    proposal: AssistantProposal;
    onDecide: (p: AssistantProposal, d: 'confirm' | 'cancel') => Promise<void>;
    onNavigate: () => void;
}) {
    const [working, setWorking] = useState<'confirm' | 'cancel' | null>(null);
    const decide = async (decision: 'confirm' | 'cancel') => {
        setWorking(decision);
        await onDecide(proposal, decision);
        setWorking(null);
    };

    return (
        <div className="border-warning/40 bg-warning-soft/40 rounded-xl border p-3.5">
            <div className="flex items-start justify-between gap-3">
                <p className="text-muted-foreground flex items-center gap-1.5 text-xs font-semibold tracking-wide uppercase">
                    <ShieldCheck className="size-3.5" aria-hidden /> Change to confirm
                </p>
                <StatusBadge status={proposal.status} tone={PROPOSAL_TONES[proposal.status]} label={PROPOSAL_LABELS[proposal.status]} />
            </div>
            <p className="text-foreground mt-2 text-sm">{proposal.preview}</p>
            {proposal.error && <p className="text-destructive mt-2 text-sm">{proposal.error}</p>}
            {proposal.status === 'pending' && (
                <div className="mt-3 flex flex-wrap justify-end gap-2">
                    <Button variant="outline" size="sm" disabled={working !== null} onClick={() => void decide('cancel')}>
                        {working === 'cancel' && <Loader2 className="animate-spin" aria-hidden />}
                        Cancel
                    </Button>
                    <Button size="sm" disabled={working !== null} onClick={() => void decide('confirm')}>
                        {working === 'confirm' && <Loader2 className="animate-spin" aria-hidden />}
                        Confirm
                    </Button>
                </div>
            )}
            {proposal.status === 'confirmed' && proposal.href && (
                <Button
                    variant="link"
                    size="sm"
                    className="mt-2"
                    onClick={() => openLink({ label: '', href: proposal.href ?? '', shopId: null, shopName: '', switchShop: false }, onNavigate)}
                >
                    Open it <ArrowUpRight aria-hidden />
                </Button>
            )}
        </div>
    );
}

/** One question and the assistant's answer: streamed text, the report links behind it and any change to confirm. */
export function AssistantTurn({
    turn,
    onDecide,
    onNavigate,
}: {
    turn: Turn;
    onDecide: (p: AssistantProposal, d: 'confirm' | 'cancel') => Promise<void>;
    onNavigate: () => void;
}) {
    return (
        <div className="space-y-3">
            <div className="flex justify-end">
                <p className="bg-primary text-primary-foreground max-w-[85%] rounded-2xl rounded-br-md px-3.5 py-2 text-sm whitespace-pre-wrap">
                    {turn.question}
                </p>
            </div>
            <div className="flex gap-2.5">
                <span className="bg-info-soft text-primary mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full" aria-hidden>
                    <Sparkles className="size-3.5" />
                </span>
                <div className="min-w-0 flex-1 space-y-3">
                    {turn.answer !== '' && <AssistantText text={turn.answer} />}
                    {turn.pending && turn.answer !== '' && (
                        <span className="bg-primary/60 -mt-2 inline-block h-4 w-1.5 animate-pulse rounded-sm align-middle" aria-hidden />
                    )}
                    {turn.answer === '' && turn.refused && <AssistantText text="Sorry, I can't help with that request." />}
                    {turn.pending && (
                        <p className="text-muted-foreground flex items-center gap-2 text-sm" role="status">
                            <Loader2 className="size-4 animate-spin" aria-hidden />
                            {turn.answer === '' ? 'Looking at your figures…' : 'Writing…'}
                        </p>
                    )}
                    {turn.error && (
                        <p className="bg-danger-soft text-danger-foreground flex items-start gap-2 rounded-lg px-3 py-2 text-sm" role="alert">
                            <AlertCircle className="mt-0.5 size-4 shrink-0" aria-hidden /> {turn.error}
                        </p>
                    )}
                    {!turn.pending && !turn.error && turn.answer === '' && !turn.refused && (
                        <p className="text-muted-foreground text-sm">No answer was given.</p>
                    )}
                    {turn.proposals.map((proposal) => (
                        <Proposal key={proposal.id} proposal={proposal} onDecide={onDecide} onNavigate={onNavigate} />
                    ))}
                    {!turn.pending && !turn.error && turn.answer !== '' && !turn.refused && (
                        <div className="-mt-1 -ml-2">
                            <AssistantCopyButton text={turn.answer} />
                        </div>
                    )}
                    {turn.links.length > 0 && (
                        <div className="flex flex-wrap gap-1.5">
                            {turn.links.map((link) => (
                                <button
                                    key={link.href + (link.shopId ?? '')}
                                    type="button"
                                    onClick={() => openLink(link, onNavigate)}
                                    className={cn(
                                        'border-border bg-card text-primary inline-flex max-w-full items-center gap-1 rounded-full border px-2.5 py-1 text-xs font-medium',
                                        'hover:border-primary/40 hover:bg-info-soft focus-visible:ring-ring/30 focus-visible:ring-[3px] focus-visible:outline-none',
                                    )}
                                >
                                    <span className="truncate">{link.label}</span>
                                    <ArrowUpRight className="size-3.5 shrink-0" aria-hidden />
                                </button>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
