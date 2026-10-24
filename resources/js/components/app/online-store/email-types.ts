/** Matches App\Domain\OnlineStore\Queries\StoreEmailsPage::for() (OS-10). */
export type SmtpEncryption = 'tls' | 'ssl' | 'none';

export interface StoreEmailSmtp {
    enabled: boolean;
    host: string | null;
    port: number | null;
    encryption: SmtpEncryption | null;
    username: string | null;
    fromAddress: string | null;
    /** The password itself never comes to the browser: only whether one is saved and its last 4 characters. */
    passwordSaved: boolean;
    passwordLast4: string | null;
    /** Server details are saved (even while switched off). */
    saved: boolean;
}

export interface StoreEmailStatus {
    /** platform: Switch & Save's service in the shop's name; needsTest: own server saved, waiting for a test email. */
    mode: 'platform' | 'needsTest' | 'ownServer';
    verifiedAt: string | null;
    lastError: string | null;
    lastErrorAt: string | null;
    lastTestAt: string | null;
}

export interface StoreEmailSettings {
    businessName: string;
    orderAddress: string | null;
    /** OSA-14: where orderAddress comes from: the store's order email, else the business email. */
    orderAddressSource: 'orderEmail' | 'businessEmail' | null;
    platformFrom: string;
    senderName: string | null;
    replyTo: string | null;
    smtp: StoreEmailSmtp;
    status: StoreEmailStatus;
}

export interface StoreEmailTemplate {
    key: string;
    label: string;
    description: string;
    subject: string;
}

export interface EmailsProps {
    /** Null until Switch & Save switches the online store on. */
    store: StoreEmailSettings | null;
    templates: StoreEmailTemplate[];
    ports: number[];
    testRecipient: string;
    canEdit: boolean;
}

export type EmailsForm = {
    sender_name: string;
    reply_to: string;
    smtp_enabled: boolean;
    smtp_host: string;
    smtp_port: string;
    smtp_encryption: SmtpEncryption;
    smtp_username: string;
    smtp_password: string;
    smtp_from_address: string;
};
