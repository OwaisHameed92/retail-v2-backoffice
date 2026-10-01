import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { showToast } from '@/components/shared/toaster';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { ArrowUp, History, Loader2, MessageSquarePlus, Plug, Sparkles, Store, Trash2 } from 'lucide-react';
import { type KeyboardEvent, useEffect, useRef, useState } from 'react';
import { AssistantTurn } from './assistant-turn';
import type { AssistantProposal, AssistantStatus } from './types';
import { useAssistant } from './use-assistant';

const MAX_QUESTION = 4000;

function UsageMeter({ status }: { status: AssistantStatus }) {
    if (!status.usage) {
        return null;
    }
    const { percent, resetsOn } = status.usage;

    return (
        <div className="space-y-1" title={`${status.usage.used.toLocaleString('en-GB')} of ${status.usage.limit.toLocaleString('en-GB')} tokens`}>
            <div className="text-muted-foreground flex justify-between text-xs">
                <span>This month's allowance: {percent}% used</span>
                <span>Resets {resetsOn}</span>
            </div>
            <div
                className="bg-muted h-1.5 overflow-hidden rounded-full"
                role="progressbar"
                aria-valuenow={percent}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-label="AI allowance used"
            >
                <div
                    className={cn('h-full rounded-full', percent >= 90 ? 'bg-destructive' : percent >= 75 ? 'bg-warning' : 'bg-primary')}
                    style={{ width: `${Math.max(2, percent)}%` }}
                />
            </div>
        </div>
    );
}

function Unavailable({ status }: { status: AssistantStatus }) {
    const setUp = status.reason === 'notConfigured';

    return (
        <div className="flex flex-1 flex-col items-center justify-center px-6 text-center">
            <span className="bg-muted text-muted-foreground flex size-12 items-center justify-center rounded-full">
                <Plug className="size-5" aria-hidden />
            </span>
            <p className="mt-4 text-base font-semibold">{setUp ? 'AI is not configured yet' : 'The assistant is not available'}</p>
            <p className="text-muted-foreground mt-1.5 max-w-xs text-sm">{status.message}</p>
            {setUp && (
                <p className="text-muted-foreground mt-3 max-w-xs text-xs">
                    Once Switch & Save switches it on, you can ask about sales, stock, cash and staff here.
                </p>
            )}
        </div>
    );
}

/**
 * The portal assistant (module 6.2): a side panel opened from the top bar's "Ask anything". Questions stream back
 * answers built only from this business's figures, with links to the matching reports; any change it proposes waits
 * for Confirm. History is the user's own and can be deleted.
 */
export function AssistantPanel({
    open,
    onOpenChange,
    initialQuestion,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    initialQuestion?: string;
}) {
    const assistant = useAssistant();
    const { status, turns, busy } = assistant;
    const [question, setQuestion] = useState('');
    const [showHistory, setShowHistory] = useState(false);
    const [confirmClear, setConfirmClear] = useState(false);
    const scroller = useRef<HTMLDivElement>(null);
    const input = useRef<HTMLTextAreaElement>(null);
    const { loadStatus } = assistant;

    useEffect(() => {
        if (open) {
            void loadStatus();
            setTimeout(() => input.current?.focus(), 50);
        }
    }, [open, loadStatus]);

    useEffect(() => {
        if (open && initialQuestion) {
            setQuestion(initialQuestion);
        }
    }, [open, initialQuestion]);

    useEffect(() => {
        scroller.current?.scrollTo({ top: scroller.current.scrollHeight, behavior: 'smooth' });
    }, [turns]);

    const send = (text: string = question) => {
        if (text.trim() === '' || busy || !status?.available) {
            return;
        }
        setShowHistory(false);
        setQuestion('');
        void assistant.ask(text);
    };

    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            send();
        }
    };

    const decide = async (proposal: AssistantProposal, decision: 'confirm' | 'cancel') => {
        const error = await assistant.decide(proposal, decision);
        if (error) {
            showToast(error, 'error');
            if (assistant.conversationId) {
                void assistant.openConversation(assistant.conversationId);
            }
        } else {
            showToast(decision === 'confirm' ? 'Change made.' : 'Change cancelled.');
        }
    };

    const remove = async (id: string | null) => {
        const error = await assistant.remove(id);
        showToast(error ?? (id ? 'Conversation deleted.' : 'History cleared.'), error ? 'error' : 'success');
    };

    const close = () => onOpenChange(false);
    const available = status?.available === true;

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent side="right" className="flex w-full flex-col gap-0 p-0 sm:max-w-xl">
                <div className="border-border space-y-3 border-b px-5 pt-5 pb-4">
                    <div className="flex items-start gap-3 pr-8">
                        <span className="bg-primary text-primary-foreground flex size-9 shrink-0 items-center justify-center rounded-xl">
                            <Sparkles className="size-4" aria-hidden />
                        </span>
                        <div className="min-w-0">
                            <SheetTitle>Ask anything</SheetTitle>
                            <SheetDescription>Answers from your own sales, stock, cash and staff figures.</SheetDescription>
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => {
                                assistant.newConversation();
                                setShowHistory(false);
                            }}
                            disabled={busy}
                        >
                            <MessageSquarePlus aria-hidden /> New chat
                        </Button>
                        <Button
                            variant={showHistory ? 'secondary' : 'outline'}
                            size="sm"
                            onClick={() => setShowHistory((v) => !v)}
                            aria-pressed={showHistory}
                        >
                            <History aria-hidden /> History{status && status.conversations.length > 0 ? ` (${status.conversations.length})` : ''}
                        </Button>
                        {status?.shop && (
                            <span className="text-muted-foreground ml-auto inline-flex items-center gap-1 text-xs">
                                <Store className="size-3.5" aria-hidden /> {status.shop} only
                            </span>
                        )}
                    </div>
                    {status && available && <UsageMeter status={status} />}
                </div>

                <div ref={scroller} className="flex min-h-0 flex-1 flex-col overflow-y-auto px-5 py-5">
                    {!status && !assistant.statusError && (
                        <div className="space-y-3">
                            <Skeleton className="h-5 w-2/3" />
                            <Skeleton className="h-20 w-full" />
                        </div>
                    )}
                    {assistant.statusError && <p className="text-destructive text-sm">{assistant.statusError}</p>}

                    {status && showHistory && (
                        <div className="space-y-1">
                            {status.conversations.length === 0 ? (
                                <p className="text-muted-foreground py-8 text-center text-sm">No conversations yet. They are kept for 90 days.</p>
                            ) : (
                                <>
                                    {status.conversations.map((c) => (
                                        <div
                                            key={c.id}
                                            className={cn(
                                                'group hover:bg-muted flex items-center gap-2 rounded-lg px-2 py-1.5',
                                                c.id === assistant.conversationId && 'bg-muted',
                                            )}
                                        >
                                            <button
                                                type="button"
                                                className="min-w-0 flex-1 text-left"
                                                onClick={() => {
                                                    void assistant.openConversation(c.id);
                                                    setShowHistory(false);
                                                }}
                                            >
                                                <span className="block truncate text-sm font-medium">{c.title}</span>
                                                {c.lastMessageAt && (
                                                    <span className="text-muted-foreground text-xs">
                                                        {new Date(c.lastMessageAt).toLocaleString('en-GB', {
                                                            timeZone: 'Europe/London',
                                                            day: 'numeric',
                                                            month: 'short',
                                                            hour: '2-digit',
                                                            minute: '2-digit',
                                                        })}
                                                    </span>
                                                )}
                                            </button>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="text-muted-foreground size-8"
                                                aria-label={`Delete "${c.title}"`}
                                                onClick={() => void remove(c.id)}
                                            >
                                                <Trash2 aria-hidden />
                                            </Button>
                                        </div>
                                    ))}
                                    <div className="pt-3 text-right">
                                        <Button variant="ghost" size="sm" className="text-destructive" onClick={() => setConfirmClear(true)}>
                                            <Trash2 aria-hidden /> Delete all history
                                        </Button>
                                    </div>
                                </>
                            )}
                        </div>
                    )}

                    {status && !showHistory && !available && <Unavailable status={status} />}

                    {status && !showHistory && available && (
                        <>
                            {assistant.loadingConversation && (
                                <Loader2 className="text-muted-foreground mx-auto size-5 animate-spin" aria-label="Loading" />
                            )}
                            {!assistant.loadingConversation && turns.length === 0 && (
                                <div className="my-auto space-y-4 py-6 text-center">
                                    <p className="text-muted-foreground text-sm">Ask about your business in plain English. Try one of these:</p>
                                    <div className="flex flex-col items-stretch gap-2">
                                        {status.examples.map((example) => (
                                            <button
                                                key={example}
                                                type="button"
                                                onClick={() => send(example)}
                                                className="border-border bg-card hover:border-primary/40 hover:bg-info-soft focus-visible:ring-ring/30 rounded-xl border px-3.5 py-2.5 text-left text-sm focus-visible:ring-[3px] focus-visible:outline-none"
                                            >
                                                {example}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            )}
                            <div className="space-y-6">
                                {turns.map((turn) => (
                                    <AssistantTurn key={turn.id} turn={turn} onDecide={decide} onNavigate={close} />
                                ))}
                            </div>
                        </>
                    )}
                </div>

                <div className="border-border border-t px-5 py-4">
                    <div className="flex items-end gap-2">
                        <Textarea
                            ref={input}
                            value={question}
                            onChange={(e) => setQuestion(e.target.value.slice(0, MAX_QUESTION))}
                            onKeyDown={onKeyDown}
                            rows={2}
                            disabled={!available}
                            placeholder={available ? 'e.g. Top sellers in Leeds last week' : 'The assistant is not available'}
                            aria-label="Your question"
                            className="max-h-40 min-h-[44px] resize-none"
                        />
                        <Button
                            size="icon"
                            className="size-11 shrink-0 rounded-xl"
                            onClick={() => send()}
                            disabled={!available || busy || question.trim() === ''}
                            aria-label="Send"
                        >
                            {busy ? <Loader2 className="animate-spin" aria-hidden /> : <ArrowUp aria-hidden />}
                        </Button>
                    </div>
                    <p className="text-muted-foreground mt-2 text-xs">
                        Answers can be wrong: check the linked report. Changes only happen when you confirm them.
                    </p>
                </div>

                <ConfirmDialog
                    open={confirmClear}
                    onOpenChange={setConfirmClear}
                    title="Delete all your assistant history?"
                    description="Every conversation you have had with the assistant is deleted. Changes waiting for confirmation are cancelled. This cannot be undone."
                    confirmLabel="Delete all"
                    destructive
                    onConfirm={() => remove(null)}
                />
            </SheetContent>
        </Sheet>
    );
}
