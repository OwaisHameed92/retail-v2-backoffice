import { type FeedLinkReply } from '@/components/app/salon/types';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { CalendarPlus, Check, Copy, ShieldAlert } from 'lucide-react';
import { useState } from 'react';

interface FeedLinkDialogProps {
    /** The stylist's name and the one-time reply, or null when closed. */
    link: { name: string; reply: FeedLinkReply } | null;
    onClose: () => void;
}

/**
 * Shows a stylist's calendar feed address once, right after it was turned on or renewed: copy, open in the phone's
 * calendar app (webcal://) and a QR code to scan from the stylist's phone. The address is never shown again.
 */
export function FeedLinkDialog({ link, onClose }: FeedLinkDialogProps) {
    return (
        <Dialog open={link !== null} onOpenChange={(open) => !open && onClose()}>
            {link && <FeedLinkBody key={link.reply.url} name={link.name} reply={link.reply} onClose={onClose} />}
        </Dialog>
    );
}

function FeedLinkBody({ name, reply, onClose }: { name: string; reply: FeedLinkReply; onClose: () => void }) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(reply.url);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            setCopied(false);
        }
    };

    return (
        <DialogContent className="sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>{reply.regenerated ? `New calendar address for ${name}` : `${name}’s calendar feed is on`}</DialogTitle>
                <DialogDescription>
                    Add this address to {name}’s phone calendar once. Their appointments then appear and update by themselves, about every 15 minutes.
                    It shows the service and the client’s first name only.
                </DialogDescription>
            </DialogHeader>

            <div className="grid gap-4">
                <div className="flex flex-col items-center gap-4 rounded-lg border p-4 sm:flex-row sm:items-start">
                    <div
                        role="img"
                        aria-label={`QR code of ${name}’s calendar address`}
                        className="size-[160px] shrink-0 rounded-md bg-white p-2 [&_svg]:size-full"
                        dangerouslySetInnerHTML={{ __html: reply.qrSvg }}
                    />
                    <div className="grid w-full min-w-0 gap-2 text-sm">
                        <p className="font-medium">On {name}’s phone</p>
                        <ul className="text-muted-foreground grid gap-1.5">
                            <li>iPhone: scan the code with the camera, or tap “Open in calendar app”, then Subscribe.</li>
                            <li>
                                Android (Google Calendar): on a computer open calendar.google.com, Other calendars, From URL, and paste the address.
                            </li>
                        </ul>
                    </div>
                </div>

                <div className="grid gap-2">
                    <label htmlFor="salon-feed-url" className="text-sm font-medium">
                        Calendar address
                    </label>
                    <div className="flex flex-col gap-2 sm:flex-row">
                        <Input
                            id="salon-feed-url"
                            readOnly
                            value={reply.url}
                            onFocus={(e) => e.currentTarget.select()}
                            className="font-mono text-xs"
                        />
                        <Button type="button" variant="outline" onClick={copy} className="shrink-0">
                            {copied ? <Check className="text-success" /> : <Copy />}
                            {copied ? 'Copied' : 'Copy address'}
                        </Button>
                    </div>
                </div>

                <Alert variant="warning">
                    <ShieldAlert />
                    <AlertDescription>
                        You see this address only now. Anyone who has it can read {name}’s diary, so share it only with them. Lost it? Give the feed a
                        new address: the old one stops working at once.
                    </AlertDescription>
                </Alert>
            </div>

            <DialogFooter className="gap-2">
                <Button type="button" variant="outline" asChild>
                    <a href={reply.webcalUrl}>
                        <CalendarPlus />
                        Open in calendar app
                    </a>
                </Button>
                <Button type="button" onClick={onClose}>
                    Done
                </Button>
            </DialogFooter>
        </DialogContent>
    );
}
