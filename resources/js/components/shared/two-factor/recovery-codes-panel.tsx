import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Check, Copy, Download, KeyRound } from 'lucide-react';
import { useState } from 'react';

interface RecoveryCodesPanelProps {
    codes: string[];
    /** Shown on the finish button; omit to hide the button (e.g. inside a dialog with its own footer). */
    doneLabel?: string;
    onDone?: () => void;
    /** Called when the person ticks "I have saved these codes". */
    onSavedChange?: (saved: boolean) => void;
}

/**
 * The one-time view of the ten recovery codes: copy, download as a text file, and an acknowledgement before going on.
 * The codes are never shown again (only hashes are kept).
 */
export function RecoveryCodesPanel({ codes, doneLabel, onDone, onSavedChange }: RecoveryCodesPanelProps) {
    const [copied, setCopied] = useState(false);
    const [saved, setSaved] = useState(false);
    const text = `Switch & Save recovery codes\nEach code works once. Keep them somewhere safe.\n\n${codes.join('\n')}\n`;

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(codes.join('\n'));
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            setCopied(false);
        }
    };

    const download = () => {
        const url = URL.createObjectURL(new Blob([text], { type: 'text/plain' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = 'switch-and-save-recovery-codes.txt';
        link.click();
        URL.revokeObjectURL(url);
    };

    const markSaved = (value: boolean) => {
        setSaved(value);
        onSavedChange?.(value);
    };

    return (
        <div className="grid gap-5">
            <Alert variant="warning">
                <KeyRound className="size-4" />
                <AlertDescription>
                    Save these codes now. If you lose your phone, each one lets you sign in once. You will not see them again.
                </AlertDescription>
            </Alert>

            <ul
                className="bg-muted/50 grid grid-cols-2 gap-x-6 gap-y-2 rounded-lg border p-4 font-mono text-sm tracking-wide tabular-nums"
                aria-label="Recovery codes"
            >
                {codes.map((code) => (
                    <li key={code} className="select-all">
                        {code}
                    </li>
                ))}
            </ul>

            <div className="flex flex-wrap gap-2">
                <Button type="button" variant="outline" onClick={copy}>
                    {copied ? <Check className="text-success" /> : <Copy />}
                    {copied ? 'Copied' : 'Copy codes'}
                </Button>
                <Button type="button" variant="outline" onClick={download}>
                    <Download />
                    Download
                </Button>
            </div>

            <div className="flex items-center gap-2.5">
                <Checkbox id="codes-saved" checked={saved} onCheckedChange={(checked) => markSaved(checked === true)} />
                <Label htmlFor="codes-saved" className="font-normal">
                    I have saved these codes somewhere safe
                </Label>
            </div>

            {doneLabel && onDone && (
                <Button type="button" size="lg" className="w-full" disabled={!saved} onClick={onDone}>
                    {doneLabel}
                </Button>
            )}
        </div>
    );
}
