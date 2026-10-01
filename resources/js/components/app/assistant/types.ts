/** The portal AI assistant (module 6.2): shapes returned by /app/assistant. */

export interface AssistantLink {
    label: string;
    href: string;
    shopId: string | null;
    shopName: string;
    /** Report pages read the shop from the top-bar switcher: switch to `shopId` (null = all shops) before opening. */
    switchShop: boolean;
}

export type ProposalStatus = 'pending' | 'confirmed' | 'cancelled' | 'expired' | 'failed';

export interface AssistantProposal {
    id: string;
    preview: string;
    status: ProposalStatus;
    expiresAt: string | null;
    error: string | null;
    href: string | null;
}

export interface AssistantConversation {
    id: string;
    title: string;
    lastMessageAt: string | null;
}

export interface AssistantTurn {
    id: string;
    question: string;
    answer: string;
    links: AssistantLink[];
    proposals: AssistantProposal[];
    refused: boolean;
    /** Client-only: still streaming, or the request failed. */
    pending?: boolean;
    error?: string | null;
}

export interface AssistantUsage {
    used: number;
    limit: number;
    percent: number;
    resetsOn: string;
}

export interface AssistantStatus {
    available: boolean;
    reason: string | null;
    message: string | null;
    usage: AssistantUsage | null;
    shop: string | null;
    examples: string[];
    conversations: AssistantConversation[];
}

export interface AssistantAnswer {
    conversation: AssistantConversation;
    answer: string;
    refused: boolean;
    stoppedEarly: boolean;
    links: AssistantLink[];
    proposals: AssistantProposal[];
}
