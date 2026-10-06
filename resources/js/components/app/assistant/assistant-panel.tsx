import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { showToast } from '@/components/shared/toaster';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { timeZone } from '@/lib/country';
import { cn } from '@/lib/utils';
import { History, Loader2, MessageSquarePlus, Sparkles, Store, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { AssistantComposer, AssistantEmptyState, AssistantUnavailableState, AssistantUsageMeter } from './assistant-parts';
import { AssistantTurn } from './assistant-turn';
import type { AssistantProposal } from './types';
import { useAssistant } from './use-assistant';

const MAX_QUESTION = 4000;

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
                    {status?.usage && available && <AssistantUsageMeter {...status.usage} />}
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
                                                            timeZone: timeZone(),
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

                    {status && !showHistory && !available && <AssistantUnavailableState reason={status.reason} message={status.message} />}

                    {status && !showHistory && available && (
                        <>
                            {assistant.loadingConversation && (
                                <Loader2 className="text-muted-foreground mx-auto size-5 animate-spin" aria-label="Loading" />
                            )}
                            {!assistant.loadingConversation && turns.length === 0 && (
                                <AssistantEmptyState examples={status.examples} onPick={(example) => send(example)} />
                            )}
                            <div className="space-y-6">
                                {turns.map((turn) => (
                                    <AssistantTurn key={turn.id} turn={turn} onDecide={decide} onNavigate={close} />
                                ))}
                            </div>
                        </>
                    )}
                </div>

                <AssistantComposer
                    inputRef={input}
                    value={question}
                    onChange={setQuestion}
                    onSend={() => send()}
                    onStop={assistant.stop}
                    busy={busy}
                    disabled={!available}
                    maxLength={MAX_QUESTION}
                    placeholder={available ? 'e.g. Top sellers in Leeds last week' : 'The assistant is not available'}
                />

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
