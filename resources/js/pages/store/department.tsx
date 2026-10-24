import { Breadcrumbs } from '@/components/store/breadcrumbs';
import { departmentIcon } from '@/components/store/department-icon';
import { ProductList } from '@/components/store/product-list';
import { StoreLayout } from '@/components/store/store-layout';
import { type ListFilters, type Pagination, type StoreProduct } from '@/components/store/types';
import { useStore } from '@/components/store/use-store';

interface Props {
    department: { id: string; name: string; count: number; photoUrl: string | null };
    products: StoreProduct[];
    pagination: Pagination;
    filters: ListFilters;
}

/** One department's products, with sort, filters and pages, under a banner in the store's colour with its photo. */
export default function StoreDepartment({ department, products, pagination, filters }: Props) {
    const store = useStore();
    const Icon = departmentIcon(department.name);

    return (
        <StoreLayout title={department.name}>
            <Breadcrumbs items={[{ label: 'Home', href: store?.urls.home }, { label: department.name }]} />
            <section className="bg-brand text-on-brand relative mb-6 flex items-center justify-between gap-4 overflow-hidden rounded-2xl px-5 py-6 sm:px-8 sm:py-8">
                <span aria-hidden="true" className="bg-lime absolute -bottom-20 -left-10 size-52 rounded-full opacity-20 blur-2xl" />
                <div className="relative flex min-w-0 flex-col gap-1">
                    <span className="text-xs font-semibold tracking-[0.08em] uppercase opacity-80">Aisle</span>
                    <h1 className="font-display text-[28px] leading-tight font-bold tracking-[-0.02em] break-words sm:text-[36px]">
                        {department.name}
                    </h1>
                    <span className="text-sm opacity-90">{department.count === 1 ? '1 product' : `${department.count} products`} online</span>
                </div>
                <span
                    aria-hidden="true"
                    className="relative flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-full bg-white shadow-lg sm:size-28"
                >
                    {department.photoUrl ? (
                        <img src={department.photoUrl} alt="" className="size-full object-contain p-[14%]" decoding="async" />
                    ) : (
                        <span className="bg-brand-soft text-brand-text flex size-full items-center justify-center">
                            <Icon className="size-9 sm:size-12" strokeWidth={1.5} />
                        </span>
                    )}
                </span>
            </section>
            <ProductList
                products={products}
                pagination={pagination}
                filters={filters}
                departments={store?.departments ?? []}
                currentDepartment={department.id}
                empty="No products match these filters. Try a wider price range, or show items that are out of stock too."
            />
        </StoreLayout>
    );
}
