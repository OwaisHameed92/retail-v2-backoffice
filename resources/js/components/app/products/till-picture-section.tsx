import { FormSection } from '@/components/shared/form-section';
import { relativeTime } from '@/lib/relative-time';
import { type TillImage } from './types';

/** IMG-1: the picture the till sent for this product (read only: it is changed on the till). */
export function TillPictureSection({ image, name }: { image: TillImage; name: string }) {
    return (
        <FormSection
            title="Picture"
            description="From your till. Change it on the till; your online store uses it when the product has no online photo."
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
