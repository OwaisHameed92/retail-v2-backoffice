import { sendJson, xsrfToken } from '@/lib/http';
import { useCallback, useRef, useState } from 'react';
import type { AssistantAnswer, AssistantProposal, AssistantStatus, AssistantTurn } from './types';

const BASE = '/app/assistant';

type StreamEvent = { event: string; data: Record<string, unknown> };

/** Reads `event:` / `data:` blocks from a server-sent event stream as they arrive. */
async function* readEvents(response: Response): AsyncGenerator<StreamEvent> {
    const reader = response.body?.getReader();
    if (!reader) {
        return;
    }
    const decoder = new TextDecoder();
    let buffer = '';

    while (true) {
        const { value, done } = await reader.read();
        buffer += decoder.decode(value ?? new Uint8Array(), { stream: !done });
        let index: number;
        while ((index = buffer.indexOf('\n\n')) !== -1) {
            const chunk = buffer.slice(0, index);
            buffer = buffer.slice(index + 2);
            const event = /^event: (\w+)/m.exec(chunk)?.[1];
            const data = /^data: (.*)$/m.exec(chunk)?.[1];
            if (event && data) {
                try {
                    yield { event, data: JSON.parse(data) as Record<string, unknown> };
                } catch {
                    // A malformed block is skipped; the final `done` carries the whole answer anyway.
                }
            }
        }
        if (done) {
            return;
        }
    }
}

/**
 * State and calls of the assistant panel: status (availability, allowance, history), the open conversation's turns,
 * asking (streamed), confirming or cancelling a proposed change, and deleting history.
 */
export function useAssistant() {
    const [status, setStatus] = useState<AssistantStatus | null>(null);
    const [statusError, setStatusError] = useState<string | null>(null);
    const [conversationId, setConversationId] = useState<string | null>(null);
    const [turns, setTurns] = useState<AssistantTurn[]>([]);
    const [busy, setBusy] = useState(false);
    const [loadingConversation, setLoadingConversation] = useState(false);
    const abort = useRef<AbortController | null>(null);

    const loadStatus = useCallback(async () => {
        const result = await sendJson<AssistantStatus>('GET', BASE);
        if (result.ok && result.data) {
            setStatus(result.data);
            setStatusError(null);
        } else {
            setStatusError(result.message);
        }
    }, []);

    const newConversation = useCallback(() => {
        abort.current?.abort();
        setConversationId(null);
        setTurns([]);
        setBusy(false);
    }, []);

    const openConversation = useCallback(async (id: string) => {
        abort.current?.abort();
        setLoadingConversation(true);
        const result = await sendJson<{ turns: AssistantTurn[] }>('GET', `${BASE}/conversations/${id}`);
        setLoadingConversation(false);
        if (result.ok && result.data) {
            setConversationId(id);
            setTurns(result.data.turns);
            setBusy(false);
        } else {
            setStatusError(result.message);
        }
    }, []);

    const patchLast = (patch: Partial<AssistantTurn> | ((turn: AssistantTurn) => Partial<AssistantTurn>)) =>
        setTurns((current) => {
            if (current.length === 0) {
                return current;
            }
            const last = current[current.length - 1];
            const next = typeof patch === 'function' ? patch(last) : patch;

            return [...current.slice(0, -1), { ...last, ...next }];
        });

    const ask = useCallback(
        async (question: string) => {
            const text = question.trim();
            if (text === '' || busy) {
                return;
            }
            setBusy(true);
            setTurns((current) => [
                ...current,
                { id: `local-${Date.now()}`, question: text, answer: '', links: [], proposals: [], refused: false, pending: true, error: null },
            ]);

            const controller = new AbortController();
            abort.current = controller;
            const headers: Record<string, string> = {
                Accept: 'text/event-stream',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            };
            const token = xsrfToken();
            if (token) {
                headers['X-XSRF-TOKEN'] = token;
            }

            try {
                const response = await fetch(`${BASE}/ask`, {
                    method: 'POST',
                    headers,
                    credentials: 'same-origin',
                    body: JSON.stringify({ question: text, conversationId }),
                    signal: controller.signal,
                });

                if (!response.ok || !(response.headers.get('Content-Type') ?? '').startsWith('text/event-stream')) {
                    const body = (await response.json().catch(() => null)) as { message?: string; errors?: Record<string, string[]> } | null;
                    const message =
                        Object.values(body?.errors ?? {})[0]?.[0] ??
                        body?.message ??
                        (response.status === 429
                            ? 'Too many questions in a minute. Wait a moment and try again.'
                            : 'The assistant could not answer. Try again.');
                    patchLast({ pending: false, error: message });
                    return;
                }

                let finished = false;
                for await (const { event, data } of readEvents(response)) {
                    if (event === 'text') {
                        patchLast((turn) => ({ answer: turn.answer + String(data.text ?? '') }));
                    } else if (event === 'done') {
                        const answer = data as unknown as AssistantAnswer;
                        finished = true;
                        setConversationId(answer.conversation.id);
                        patchLast({
                            answer: answer.answer,
                            links: answer.links,
                            proposals: answer.proposals,
                            refused: answer.refused,
                            pending: false,
                        });
                    } else if (event === 'error') {
                        finished = true;
                        patchLast({ pending: false, error: String(data.message ?? 'The assistant could not answer. Try again.') });
                    }
                }
                if (!finished) {
                    patchLast({ pending: false, error: 'The answer was cut off. Try again.' });
                }
            } catch (error) {
                if (!(error instanceof DOMException && error.name === 'AbortError')) {
                    patchLast({ pending: false, error: 'We could not reach the server. Check your connection and try again.' });
                }
            } finally {
                setBusy(false);
                void loadStatus();
            }
        },
        [busy, conversationId, loadStatus],
    );

    /** Stops reading the answer being streamed (the server finishes it; it is in the history). */
    const stop = useCallback(() => {
        abort.current?.abort();
        abort.current = null;
        patchLast((turn) => ({ pending: false, answer: turn.answer === '' ? '' : `${turn.answer}…`, error: turn.answer === '' ? 'Stopped.' : null }));
        setBusy(false);
    }, []);

    const decide = useCallback(async (proposal: AssistantProposal, decision: 'confirm' | 'cancel'): Promise<string | null> => {
        const result = await sendJson<{ proposal: AssistantProposal }>('POST', `${BASE}/actions/${proposal.id}/${decision}`);
        const updated = result.data?.proposal;
        if (updated) {
            setTurns((current) => current.map((turn) => ({ ...turn, proposals: turn.proposals.map((p) => (p.id === proposal.id ? updated : p)) })));
        }

        return result.ok ? null : result.message;
    }, []);

    const remove = useCallback(
        async (id: string | null): Promise<string | null> => {
            const result = await sendJson('DELETE', id ? `${BASE}/conversations/${id}` : `${BASE}/conversations`);
            if (result.ok && (id === null || id === conversationId)) {
                newConversation();
            }
            await loadStatus();

            return result.ok ? null : result.message;
        },
        [conversationId, loadStatus, newConversation],
    );

    return {
        status,
        statusError,
        conversationId,
        turns,
        busy,
        loadingConversation,
        loadStatus,
        newConversation,
        openConversation,
        ask,
        stop,
        decide,
        remove,
    };
}
