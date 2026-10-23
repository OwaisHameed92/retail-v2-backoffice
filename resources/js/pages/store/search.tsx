import { Breadcrumbs } from '@/components/store/breadcrumbs';
import { ProductList } from '@/components/store/product-list';
import { StoreLayout } from '@/components/store/store-layout';
import { type ListFilters, type Pagination, type StoreProduct } from '@/components/store/types';
import { useStore } from '@/components/store/use-store';

interface Props {
    term: string;
    products: StoreProduct[];
    pagination: Pagination;
    filters: ListFilters;
}

/** Search results (or every product when there are no words), with sort, filters and pages. */
export default function StoreSearch({ term, products, pagination, filters }: Props) {
    const store = useStore();
    const title = term ? `Results for "${term}"` : 'All products';

    return (
        <StoreLayout title={title} active="search">
            <Breadcrumbs items={[{ label: 'Home', href: store?.urls.home }, { label: term ? 'Search' : 'All products' }]} />
            <div className="mb-6 flex flex-col gap-2 sm:mb-8">
                <h1 className="font-display text-4xl leading-[0.95] font-extrabold tracking-[-0.045em] break-words sm:text-6xl">
                    {term ? (
                        <>
                            Results for <span className="text-brand">“{term}”</span>
                        </>
                    ) : (
                        'All products'
                    )}
                </h1>
                {term && <p className="text-body">Searched by name, brand, code and barcode.</p>}
            </div>
            <ProductList
                products={products}
                pagination={pagination}
                filters={filters}
                base={term ? { q: term } : {}}
                departments={store?.departments ?? []}
                empty={
                    term
                        ? `We couldn't find "${term}". Check the spelling, try fewer words, or browse the aisles.`
                        : 'No products match these filters.'
                }
            />
        </StoreLayout>
    );
}
