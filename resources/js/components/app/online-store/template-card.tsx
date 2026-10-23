import { type TemplateOption } from './types';

/** The store's look, read only: its preview photo, name and who it is for. Switch & Save sets it. */
export function TemplateCard({ template }: { template: TemplateOption }) {
    return (
        <div className="bg-card flex flex-col gap-4 rounded-xl border p-3 sm:flex-row sm:items-center">
            <img src={template.photo} alt="" className="bg-muted aspect-[16/9] w-full shrink-0 rounded-lg object-cover sm:w-56" />
            <div className="grid min-w-0 gap-1 sm:pr-3">
                <p className="text-muted-foreground text-xs font-medium">Your store's look</p>
                <p className="text-base font-semibold">{template.label}</p>
                <p className="text-muted-foreground text-sm leading-6">
                    {template.for}. Your store's look is set by Switch &amp; Save. Ask us if you want to change it.
                </p>
            </div>
        </div>
    );
}
