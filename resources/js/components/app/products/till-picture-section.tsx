import { FormSection } from '@/components/shared/form-section';
import { StatusPill, type StatusTone } from '@/components/shared/status-badge';
import { relativeTime } from '@/lib/relative-time';
import { type TillImage } from './types';

/** IMG-2: the origin's tone; the online store shows `own` and `library` pictures, the others only with "Show found pictures". */
const ORIGIN_TONES: Record<NonNullable<TillImage['origin']>, StatusTone> = { own: 'success', library: 'info', found: 'warning', unknown: 'neutral' };

/**
 * IMG-1: the picture the till sent for this product (read only: it is changed on the till). IMG-2: with a small label of
 * where the till got it.
 */
export function TillPictureSection({ image, name }: { image: TillImage; name: string }) {
    return (
        <FormSection
            title="Picture"
            description={
                image.origin === 'found' || image.origin === 'unknown'
                    ? 'From your till. Change it on the till. Your online store shows it only if “Show found pictures” is on in the store builder.'
                    : 'From your till. Change it on the till; your online store uses it when the product has no online photo.'
            }
        >
            <div className="flex flex-wrap items-end gap-4">
                <a
                    href={image.url}
                    target="_blank"
                    rel="noreferrer"
                    className="ring-border focus-visible:ring-ring block size-40 overflow-hidden rounded-xl bg-white ring-1 focus-visible:ring-2 focus-visible:outline-none"
                >
                    <img src={image.url} alt={`Picture of ${name}`} className="size-full object-contain" />
                </a>
                <p className="text-muted-foreground text-sm leading-6 tabular-nums">
                    {image.origin && image.originLabel && (
                        <>
                            <StatusPill tone={ORIGIN_TONES[image.origin]}>{image.originLabel}</StatusPill>
                            <br />
                        </>
                    )}
                    {image.width} × {image.height} px
                    {image.updatedAt && (
                        <>
                            <br />
                            Sent {relativeTime(image.updatedAt)}
                        </>
                    )}
                </p>
            </div>
        </FormSection>
    );
}
