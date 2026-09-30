import { Alert, AlertDescription } from '@/components/ui/alert';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Eye } from 'lucide-react';
import { type ReactNode } from 'react';
import { type Option } from './types';

/** A checkbox with its label and an optional line of help, the whole row clickable. */
export function CheckField({
    id,
    label,
    help,
    checked,
    onChange,
    disabled = false,
}: {
    id: string;
    label: ReactNode;
    help?: ReactNode;
    checked: boolean;
    onChange: (checked: boolean) => void;
    disabled?: boolean;
}) {
    return (
        <div className="flex items-start gap-3">
            <Checkbox id={id} checked={checked} onCheckedChange={(value) => onChange(value === true)} disabled={disabled} className="mt-0.5" />
            <div className="grid gap-0.5">
                <Label htmlFor={id} className="leading-5 font-medium">
                    {label}
                </Label>
                {help && <p className="text-muted-foreground text-xs leading-5">{help}</p>}
            </div>
        </div>
    );
}

/** A toolbar filter: "all" clears the URL param. */
export function FilterSelect({
    value,
    onChange,
    all,
    options,
    label,
    width = 'sm:w-44',
}: {
    value: string | null;
    onChange: (value: string | undefined) => void;
    all: string;
    options: Option[];
    label: string;
    width?: string;
}) {
    return (
        <Select value={value ?? 'all'} onValueChange={(next) => onChange(next === 'all' ? undefined : next)}>
            <SelectTrigger className={`h-9 w-full ${width}`} aria-label={label}>
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value="all">{all}</SelectItem>
                {options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

/** Shown to a one-shop user: these lists are shared by every shop, so they can look but not change. */
export function ReadOnlyNotice({ what }: { what: string }) {
    return (
        <Alert>
            <Eye className="size-4" />
            <AlertDescription>
                {what} are shared by every shop. You can look, but only someone who manages all shops can change them.
            </AlertDescription>
        </Alert>
    );
}

export const STATUS_OPTIONS: Option[] = [
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
];
