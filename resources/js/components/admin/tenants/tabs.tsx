import { PageTabs } from '@/components/shared/page-tabs';
import { type ReactNode } from 'react';

export interface TabItem<T extends string> {
    value: T;
    label: string;
    count?: number;
    badge?: ReactNode;
}

interface TabsProps<T extends string> {
    tabs: TabItem<T>[];
    value: T;
    onChange: (value: T) => void;
    label: string;
}

/** Typed wrapper over the shared PageTabs (button tabs). Panels use id `tab-panel-<value>`. Prefer PageTabs in new code. */
export function Tabs<T extends string>({ tabs, value, onChange, label }: TabsProps<T>) {
    return <PageTabs tabs={tabs} value={value} onChange={(next) => onChange(next as T)} label={label} />;
}
