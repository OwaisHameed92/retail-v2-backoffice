import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { SectionCard } from '@/components/shared/section-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import { LayoutTemplate, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { TemplateDialog } from './template-dialog';
import { type LabelStock, type LabelTemplate } from './types';

interface Props {
    templates: LabelTemplate[];
    stocks: LabelStock[];
    shop: { id: string; name: string };
    restrictedShop: string | null;
}

/** The templates this shop can print with: its own, every shop's, and the built-in one. */
export function TemplatesCard({ templates, stocks, shop, restrictedShop }: Props) {
    const [editing, setEditing] = useState<LabelTemplate | null>(null);
    const [open, setOpen] = useState(false);
    const stockName = (key: string) => stocks.find((s) => s.key === key)?.name ?? key;
    const canChange = (t: LabelTemplate) => t.id !== 'builtin' && (restrictedShop === null || t.branchId === restrictedShop);

    return (
        <SectionCard
            title="Templates"
            description="The label stock each template prints on and what the labels show. The first one is used unless you pick another."
            actions={
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => {
                        setEditing(null);
                        setOpen(true);
                    }}
                >
                    <Plus className="size-4" aria-hidden />
                    New template
                </Button>
            }
            flush
        >
            <ul className="divide-y">
                {templates.map((t) => (
                    <li key={t.id} className="flex flex-wrap items-center gap-3 px-4 py-3 sm:px-6">
                        <LayoutTemplate className="text-muted-foreground size-4 shrink-0" aria-hidden />
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2 text-sm font-medium">
                                {t.name}
                                {t.isDefault && <Badge variant="info">Default</Badge>}
                                <Badge variant="neutral">{t.id === 'builtin' ? 'Built in' : t.branchId === null ? 'Every shop' : shop.name}</Badge>
                            </div>
                            <div className="text-muted-foreground truncate text-xs">{stockName(t.stock)}</div>
                        </div>
                        {canChange(t) && (
                            <div className="flex gap-1">
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    className="size-8"
                                    aria-label={`Edit ${t.name}`}
                                    onClick={() => {
                                        setEditing(t);
                                        setOpen(true);
                                    }}
                                >
                                    <Pencil className="size-4" />
                                </Button>
                                <ConfirmDialog
                                    title={`Delete "${t.name}"?`}
                                    description="Labels already printed are not affected. Shops using it print on their next template."
                                    confirmLabel="Delete template"
                                    destructive
                                    onConfirm={() =>
                                        new Promise((resolve) => router.delete(route('app.labels.templates.destroy', t.id), { preserveScroll: true, onFinish: resolve }))
                                    }
                                    trigger={
                                        <Button size="icon" variant="ghost" className="size-8" aria-label={`Delete ${t.name}`}>
                                            <Trash2 className="size-4" />
                                        </Button>
                                    }
                                />
                            </div>
                        )}
                    </li>
                ))}
            </ul>
            <TemplateDialog open={open} onOpenChange={setOpen} template={editing} stocks={stocks} shop={shop} shopOnly={restrictedShop !== null} />
        </SectionCard>
    );
}
