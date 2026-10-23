import { Link } from '@inertiajs/react';
import { Fragment } from 'react';

/** "Home / Fruit & veg / Bananas": the last item is the current page. */
export function Breadcrumbs({ items }: { items: { label: string; href?: string }[] }) {
    return (
        <nav aria-label="Breadcrumb" className="mb-5 sm:mb-6">
            <ol className="text-muted flex min-w-0 flex-wrap items-center gap-x-2 text-sm [overflow-wrap:anywhere]">
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
                                    className="text-ink inline-flex min-h-11 items-center font-medium underline-offset-4 hover:underline"
                                >
                                    {item.label}
                                </Link>
                            ) : (
                                <span aria-current="page" className="text-ink font-semibold">
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
