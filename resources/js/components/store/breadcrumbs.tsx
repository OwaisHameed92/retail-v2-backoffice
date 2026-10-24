import { Link } from '@inertiajs/react';
import { Fragment } from 'react';

/** "Home / Fruit & veg / Bananas": the last item is the current page. */
export function Breadcrumbs({ items }: { items: { label: string; href?: string }[] }) {
    return (
        <nav aria-label="Breadcrumb" className="mb-3 sm:mb-4">
            <ol className="text-muted flex min-w-0 flex-wrap items-center gap-x-2 text-[13px] [overflow-wrap:anywhere]">
                {items.map((item, i) => (
                    <Fragment key={`${item.label}-${i}`}>
                        {i > 0 && (
                            <li aria-hidden="true" className="select-none">
                                /
                            </li>
                        )}
                        <li>
                            {item.href ? (
                                <Link
                                    href={item.href}
                                    className="text-body hover:text-ink inline-flex min-h-11 items-center underline-offset-4 hover:underline"
                                >
                                    {item.label}
                                </Link>
                            ) : (
                                <span aria-current="page" className="text-ink font-medium">
                                    {item.label}
                                </span>
                            )}
                        </li>
                    </Fragment>
                ))}
            </ol>
        </nav>
    );
}
