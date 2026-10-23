import { Breadcrumbs } from '@/components/store/breadcrumbs';
import { initialOf } from '@/components/store/format';
import { ProductList } from '@/components/store/product-list';
import { StoreLayout } from '@/components/store/store-layout';
import { type ListFilters, type Pagination, type StoreProduct } from '@/components/store/types';
import { useStore } from '@/components/store/use-store';

interface Props {
    department: { id: string; name: string; count: number };
    products: StoreProduct[];
    pagination: Pagination;
    filters: ListFilters;
}

/** One department's products, with sort, filters and pages. */
export default function StoreDepartment({ department, products, pagination, filters }: Props) {
    const store = useStore();

    return (
        <StoreLayout title={department.name}>
            <Breadcrumbs items={[{ label: 'Home', href: store?.urls.home }, { label: department.name }]} />
            <section className="bg-brand text-on-brand relative mb-6 flex min-h-[160px] items-center overflow-hidden rounded-[28px] sm:mb-8 sm:min-h-[220px] sm:rounded-[32px]">
                <span
                    aria-hidden="true"
                    className="bg-lime font-display text-ink/80 absolute top-1/2 -right-10 flex size-56 -translate-y-1/2 items-center justify-center rounded-full text-[120px] font-extrabold sm:right-10 sm:size-72 sm:text-[160px]"
                >
                    {initialOf(department.name)}
                </span>
                <div className="relative flex max-w-[680px] flex-col gap-2 p-6 pr-40 sm:p-10 sm:pr-10 md:p-12">
                    <span className="text-[13px] font-bold tracking-[0.08em] uppercase opacity-85">Aisle</span>
                    <h1 className="font-display text-4xl leading-[0.92] font-extrabold tracking-[-0.045em] sm:text-6xl lg:text-[72px]">
                        {department.name}
                    </h1>
                    <span className="text-base opacity-90 sm:text-[17px]">
                        {department.count === 1 ? '1 product' : `${department.count} products`} online
                    </span>
                </div>
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
