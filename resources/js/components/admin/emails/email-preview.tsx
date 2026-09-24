import { Skeleton } from '@/components/ui/skeleton';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';
import { FileText, Monitor, Smartphone } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

type Width = 'desktop' | 'phone';
type Format = 'html' | 'text';

const MIN_HEIGHT = 480;

/**
 * Live preview of one template, rendered by the server with sample data in a sandboxed iframe.
 * Desktop or phone width, HTML or plain text. The iframe grows to the email's height.
 */
export function EmailPreview({ templateKey, label }: { templateKey: string; label: string }) {
    const [width, setWidth] = useState<Width>('desktop');
    const [format, setFormat] = useState<Format>('html');
    const [loading, setLoading] = useState(true);
    const [height, setHeight] = useState(MIN_HEIGHT);
    const frame = useRef<HTMLIFrameElement>(null);

    const src = route('admin.emails.templates.preview', { template: templateKey, ...(format === 'text' ? { format: 'text' } : {}) });

    useEffect(() => {
        setLoading(true);
    }, [src]);

    const measure = () => {
        const doc = frame.current?.contentDocument;
        if (doc?.documentElement) {
            setHeight(Math.max(MIN_HEIGHT, doc.documentElement.scrollHeight + 8));
        }
    };

    useEffect(() => {
        // Re-measure after a width change reflows the email.
        const timer = window.setTimeout(measure, 50);

        return () => window.clearTimeout(timer);
    }, [width]);

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <ToggleGroup
                    type="single"
                    variant="outline"
                    size="sm"
                    value={width}
                    onValueChange={(value) => value && setWidth(value as Width)}
                    aria-label="Preview width"
                >
                    <ToggleGroupItem value="desktop" aria-label="Desktop width" className="gap-1.5 px-3">
                        <Monitor className="size-4" />
                        <span className="hidden sm:inline">Desktop</span>
                    </ToggleGroupItem>
                    <ToggleGroupItem value="phone" aria-label="Phone width" className="gap-1.5 px-3">
                        <Smartphone className="size-4" />
                        <span className="hidden sm:inline">Phone</span>
                    </ToggleGroupItem>
                </ToggleGroup>

                <ToggleGroup
                    type="single"
                    variant="outline"
                    size="sm"
                    value={format}
                    onValueChange={(value) => value && setFormat(value as Format)}
                    aria-label="Preview format"
                >
                    <ToggleGroupItem value="html" className="px-3">
                        HTML
                    </ToggleGroupItem>
                    <ToggleGroupItem value="text" className="gap-1.5 px-3">
                        <FileText className="size-4" />
                        Plain text
                    </ToggleGroupItem>
                </ToggleGroup>
            </div>

            {/* The email is always shown on its own light canvas: emails are light only. */}
            <div className="bg-muted/40 relative overflow-hidden rounded-lg border">
                {loading && (
                    <div className="bg-card absolute inset-0 z-10 flex flex-col gap-4 p-8" aria-hidden>
                        <Skeleton className="mx-auto h-8 w-44" />
                        <Skeleton className="h-6 w-2/3" />
                        <Skeleton className="h-4 w-full" />
                        <Skeleton className="h-4 w-5/6" />
                        <Skeleton className="h-24 w-full" />
                        <Skeleton className="mx-auto h-10 w-48" />
                    </div>
                )}
                <div className={cn('mx-auto transition-[max-width] duration-300', width === 'phone' ? 'max-w-[375px] border-x' : 'max-w-full')}>
                    <iframe
                        ref={frame}
                        key={src}
                        src={src}
                        title={`Preview of ${label}`}
                        sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                        className="block w-full border-0"
                        style={{ height, colorScheme: 'light' }}
                        onLoad={() => {
                            measure();
                            setLoading(false);
                        }}
                    />
                </div>
            </div>
        </div>
    );
}

export default EmailPreview;
