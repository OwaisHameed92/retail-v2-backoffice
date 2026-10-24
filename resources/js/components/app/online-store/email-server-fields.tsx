import { FormField, FormGrid } from '@/components/shared/form-section';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type InertiaFormProps } from '@inertiajs/react';
import { type EmailsForm, type SmtpEncryption, type StoreEmailSmtp } from './email-types';

const ENCRYPTIONS: { value: SmtpEncryption; label: string }[] = [
    { value: 'tls', label: 'TLS (STARTTLS), recommended' },
    { value: 'ssl', label: 'SSL' },
    { value: 'none', label: 'None (not recommended)' },
];

const PORT_HINTS: Record<number, string> = { 587: '587 (TLS)', 465: '465 (SSL)', 25: '25', 2525: '2525' };

interface EmailServerFieldsProps {
    form: InertiaFormProps<EmailsForm>;
    smtp: StoreEmailSmtp;
    ports: number[];
    disabled: boolean;
}

/**
 * OS-10: the store's own SMTP server. The saved password is never shown: the field stays empty ("Saved, ending ••1234")
 * and an empty field keeps it. Choosing port 465 picks SSL; leaving 465 moves SSL back to TLS.
 */
export function EmailServerFields({ form, smtp, ports, disabled }: EmailServerFieldsProps) {
    const errors = form.errors as Partial<Record<keyof EmailsForm, string>>;
    const setPort = (value: string) => {
        form.setData((data) => ({
            ...data,
            smtp_port: value,
            smtp_encryption: value === '465' ? 'ssl' : data.smtp_encryption === 'ssl' ? 'tls' : data.smtp_encryption,
        }));
    };

    return (
        <div className="grid gap-5">
            <FormGrid>
                <FormField id="smtp_host" label="Server" error={errors.smtp_host} help="From your email provider, e.g. smtp.office365.com.">
                    <Input
                        id="smtp_host"
                        value={form.data.smtp_host}
                        maxLength={253}
                        autoComplete="off"
                        spellCheck={false}
                        placeholder="smtp.yourdomain.co.uk"
                        disabled={disabled}
                        aria-invalid={!!errors.smtp_host}
                        aria-describedby={errors.smtp_host ? 'smtp_host-error' : 'smtp_host-help'}
                        onChange={(e) => form.setData('smtp_host', e.target.value.trim())}
                    />
                </FormField>
                <div className="grid grid-cols-2 gap-4">
                    <FormField id="smtp_port" label="Port" error={errors.smtp_port}>
                        <Select value={form.data.smtp_port} onValueChange={setPort} disabled={disabled}>
                            <SelectTrigger id="smtp_port" aria-invalid={!!errors.smtp_port}>
                                <SelectValue placeholder="Choose" />
                            </SelectTrigger>
                            <SelectContent>
                                {ports.map((port) => (
                                    <SelectItem key={port} value={String(port)}>
                                        {PORT_HINTS[port] ?? String(port)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </FormField>
                    <FormField id="smtp_encryption" label="Encryption" error={errors.smtp_encryption}>
                        <Select
                            value={form.data.smtp_encryption}
                            onValueChange={(v) => form.setData('smtp_encryption', v as SmtpEncryption)}
                            disabled={disabled}
                        >
                            <SelectTrigger id="smtp_encryption" aria-invalid={!!errors.smtp_encryption}>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {ENCRYPTIONS.map((e) => (
                                    <SelectItem key={e.value} value={e.value}>
                                        {e.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </FormField>
                </div>
                <FormField id="smtp_username" label="Username" error={errors.smtp_username} help="Usually your full email address.">
                    <Input
                        id="smtp_username"
                        value={form.data.smtp_username}
                        maxLength={255}
                        autoComplete="off"
                        spellCheck={false}
                        disabled={disabled}
                        aria-invalid={!!errors.smtp_username}
                        aria-describedby={errors.smtp_username ? 'smtp_username-error' : 'smtp_username-help'}
                        onChange={(e) => form.setData('smtp_username', e.target.value)}
                    />
                </FormField>
                <FormField
                    id="smtp_password"
                    label="Password"
                    error={errors.smtp_password}
                    help={smtp.passwordSaved ? 'Leave empty to keep the saved password.' : 'Some providers need an app password for this.'}
                >
                    <Input
                        id="smtp_password"
                        type="password"
                        value={form.data.smtp_password}
                        maxLength={255}
                        autoComplete="new-password"
                        placeholder={smtp.passwordSaved ? `Saved, ending ••${smtp.passwordLast4 ?? ''}` : undefined}
                        disabled={disabled}
                        aria-invalid={!!errors.smtp_password}
                        aria-describedby={errors.smtp_password ? 'smtp_password-error' : 'smtp_password-help'}
                        onChange={(e) => form.setData('smtp_password', e.target.value)}
                    />
                </FormField>
            </FormGrid>
            <FormField
                id="smtp_from_address"
                label="Send from address"
                error={errors.smtp_from_address}
                help="The address customers see. Your email account must be allowed to send from it."
            >
                <Input
                    id="smtp_from_address"
                    type="email"
                    className="sm:max-w-sm"
                    value={form.data.smtp_from_address}
                    maxLength={255}
                    autoComplete="off"
                    placeholder="orders@yourshop.co.uk"
                    disabled={disabled}
                    aria-invalid={!!errors.smtp_from_address}
                    aria-describedby={errors.smtp_from_address ? 'smtp_from_address-error' : 'smtp_from_address-help'}
                    onChange={(e) => form.setData('smtp_from_address', e.target.value)}
                />
            </FormField>
        </div>
    );
}
