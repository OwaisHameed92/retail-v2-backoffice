import { GroupDialog, type GroupTarget } from '@/components/app/products/group-dialog';
import { type CatalogueOptions, type CategoryNode, type DepartmentNode } from '@/components/app/products/types';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { RowActions } from '@/components/shared/row-actions';
import { SectionCard } from '@/components/shared/section-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatNumber } from '@/lib/country';
import { cn } from '@/lib/utils';
import { Head, Link, router } from '@inertiajs/react';
import { CornerDownRight, FolderPlus, FolderTree, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface Props {
    departments: DepartmentNode[];
    options: CatalogueOptions;
    canManage: boolean;
}

type Deleting = { kind: 'department' | 'category'; id: string; name: string } | null;

function Swatch({ colour }: { colour: string }) {
    return <span className="size-3 shrink-0 rounded-full border" style={{ backgroundColor: colour }} aria-hidden />;
}

function Flags({ isActive, isVisibleOnTill }: { isActive: boolean; isVisibleOnTill: boolean }) {
    return (
        <>
            {!isActive && <Badge variant="neutral">Off</Badge>}
            {isActive && !isVisibleOnTill && <Badge variant="neutral">Hidden on till</Badge>}
        </>
    );
}

function Count({ n, href }: { n: number; href: string }) {
    return (
        <Link
            href={href}
            className="text-muted-foreground hover:text-foreground text-sm whitespace-nowrap tabular-nums"
            onClick={(e) => e.stopPropagation()}
        >
            {formatNumber(n)} {n === 1 ? 'product' : 'products'}
        </Link>
    );
}

export default function Categories({ departments, options, canManage }: Props) {
    const [target, setTarget] = useState<GroupTarget | null>(null);
    const [deleting, setDeleting] = useState<Deleting>(null);

    const categoryRow = (category: CategoryNode, department: DepartmentNode, depth: 0 | 1) => (
        <li key={category.id} className={cn('flex items-center gap-3 px-5 py-3', depth === 1 && 'pl-12')}>
            {depth === 1 && <CornerDownRight className="text-muted-foreground size-4 shrink-0" aria-hidden />}
            <Swatch colour={category.colourHex} />
            <span className="min-w-0 flex-1 truncate text-sm font-medium">{category.name}</span>
            <Flags isActive={category.isActive} isVisibleOnTill={category.isVisibleOnTill} />
            <Count
                n={category.productCount}
                href={route('app.products.index', { department: department.id, category: category.id, status: 'all' })}
            />
            {canManage && (
                <RowActions
                    label={`Actions for ${category.name}`}
                    actions={[
                        {
                            label: 'Edit',
                            icon: Pencil,
                            onSelect: () => setTarget({ kind: 'category', category, departmentId: department.id, parentId: category.parentId }),
                        },
                        {
                            label: 'Add sub-category',
                            icon: FolderPlus,
                            hidden: depth === 1,
                            onSelect: () => setTarget({ kind: 'category', category: null, departmentId: department.id, parentId: category.id }),
                        },
                        {
                            label: 'Delete',
                            icon: Trash2,
                            destructive: true,
                            onSelect: () => setDeleting({ kind: 'category', id: category.id, name: category.name }),
                        },
                    ]}
                />
            )}
        </li>
    );

    return (
        <AppLayout>
            <Head title="Departments and categories" />
            <div className="mx-auto grid w-full max-w-5xl gap-6">
                <PageHeader
                    title="Departments and categories"
                    description="How your products file on the till and in reports. Every till gets changes at its next sync."
                    back={{ href: route('app.products.index'), label: 'Products' }}
                    actions={
                        canManage ? (
                            <>
                                {departments.length > 0 && (
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            setTarget({ kind: 'category', category: null, departmentId: departments[0].id, parentId: null })
                                        }
                                    >
                                        <FolderPlus />
                                        Add category
                                    </Button>
                                )}
                                <Button onClick={() => setTarget({ kind: 'department', department: null })}>
                                    <Plus />
                                    Add department
                                </Button>
                            </>
                        ) : undefined
                    }
                />

                {departments.length === 0 && (
                    <EmptyState
                        bordered
                        icon={FolderTree}
                        title="No departments yet"
                        body="Departments and categories from your tills appear here after they sync. You can also add them here."
                        action={
                            canManage ? (
                                <Button onClick={() => setTarget({ kind: 'department', department: null })}>Add department</Button>
                            ) : undefined
                        }
                    />
                )}

                {departments.map((department) => (
                    <SectionCard
                        key={department.id}
                        flush
                        title={
                            <span className="flex items-center gap-2.5">
                                <Swatch colour={department.colourHex} />
                                {department.name}
                                <Flags isActive={department.isActive} isVisibleOnTill={department.isVisibleOnTill} />
                            </span>
                        }
                        description={`${formatNumber(department.productCount)} ${department.productCount === 1 ? 'product' : 'products'} · ${department.categories.length} ${department.categories.length === 1 ? 'category' : 'categories'}`}
                        actions={
                            canManage ? (
                                <RowActions
                                    label={`Actions for ${department.name}`}
                                    actions={[
                                        { label: 'Edit department', icon: Pencil, onSelect: () => setTarget({ kind: 'department', department }) },
                                        {
                                            label: 'Add category',
                                            icon: FolderPlus,
                                            onSelect: () =>
                                                setTarget({ kind: 'category', category: null, departmentId: department.id, parentId: null }),
                                        },
                                        {
                                            label: 'Delete',
                                            icon: Trash2,
                                            destructive: true,
                                            onSelect: () => setDeleting({ kind: 'department', id: department.id, name: department.name }),
                                        },
                                    ]}
                                />
                            ) : undefined
                        }
                    >
                        {department.categories.length === 0 ? (
                            <p className="text-muted-foreground px-5 py-4 text-sm">No categories yet. A product needs a category to be filed here.</p>
                        ) : (
                            <ul className="divide-y">
                                {department.categories.map((category) => [
                                    categoryRow(category, department, 0),
                                    ...(category.children ?? []).map((child) => categoryRow(child, department, 1)),
                                ])}
                            </ul>
                        )}
                    </SectionCard>
                ))}
            </div>

            {target && <GroupDialog key={JSON.stringify(target)} target={target} options={options} onClose={() => setTarget(null)} />}

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title={`Delete ${deleting?.name ?? ''}?`}
                description="Only an empty one can be deleted: move its products and categories first, or switch it off instead. Every till removes it at its next sync."
                confirmLabel="Delete"
                destructive
                onConfirm={() =>
                    new Promise((resolve) => {
                        if (!deleting) return resolve(null);
                        const url =
                            deleting.kind === 'department'
                                ? route('app.products.departments.destroy', deleting.id)
                                : route('app.products.categories.destroy', deleting.id);
                        router.delete(url, {
                            preserveScroll: true,
                            onFinish: () => {
                                setDeleting(null);
                                resolve(null);
                            },
                        });
                    })
                }
            />
        </AppLayout>
    );
}
