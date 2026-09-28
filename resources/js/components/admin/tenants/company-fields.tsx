import { Field, Textarea } from '@/components/admin/tenants/field';
import { type Option } from '@/components/admin/tenants/types';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

export type CompanyFieldsData = {
    name: string;
    legal_name: string;
    vat_number: string;
    company_number: string;
    email: string;
    phone: string;
    contact_name: string;
    address: string;
    /** Module 1.11: the licence key's shop details. */
    business_type: string;
    town: string;
    postcode: string;
    receipt_footer: string;
};

interface CompanyFieldsProps<T extends CompanyFieldsData> {
    data: T;
    setData: (key: keyof CompanyFieldsData, value: string) => void;
    errors: Partial<Record<string, string>>;
    businessTypes: Option[];
}

/** Business details shared by the create wizard and the edit page. Rendered inside a FormSection grid. */
export function CompanyFields<T extends CompanyFieldsData>({ data, setData, errors, businessTypes }: CompanyFieldsProps<T>) {
    return (
        <>
            <Field id="name" label="Business name" hint="As the customer knows it, e.g. Khan Mini Mart." error={errors.name}>
                <Input
                    id="name"
                    required
                    autoFocus
                    value={data.name}
                    onChange={(e) => setData('name', e.target.value)}
                    aria-invalid={!!errors.name}
                />
            </Field>
            <Field id="legal_name" label="Legal name" optional hint="Printed on invoices, e.g. Khan Retail Ltd." error={errors.legal_name}>
                <Input
                    id="legal_name"
                    value={data.legal_name}
                    onChange={(e) => setData('legal_name', e.target.value)}
                    aria-invalid={!!errors.legal_name}
                />
            </Field>
            <Field id="vat_number" label="VAT number" optional hint="For example GB123456789." error={errors.vat_number}>
                <Input
                    id="vat_number"
                    value={data.vat_number}
                    onChange={(e) => setData('vat_number', e.target.value.toUpperCase())}
                    aria-invalid={!!errors.vat_number}
                    autoComplete="off"
                />
            </Field>
            <Field id="company_number" label="Company number" optional hint="Companies House, 8 characters." error={errors.company_number}>
                <Input
                    id="company_number"
                    value={data.company_number}
                    onChange={(e) => setData('company_number', e.target.value.toUpperCase())}
                    aria-invalid={!!errors.company_number}
                    autoComplete="off"
                />
            </Field>
            <Field id="contact_name" label="Main contact" optional error={errors.contact_name}>
                <Input id="contact_name" value={data.contact_name} onChange={(e) => setData('contact_name', e.target.value)} />
            </Field>
            <Field id="email" label="Business email" optional error={errors.email}>
                <Input
                    id="email"
                    type="email"
                    value={data.email}
                    onChange={(e) => setData('email', e.target.value)}
                    aria-invalid={!!errors.email}
                    autoComplete="off"
                />
            </Field>
            <Field id="phone" label="Phone" optional error={errors.phone}>
                <Input id="phone" type="tel" value={data.phone} onChange={(e) => setData('phone', e.target.value)} aria-invalid={!!errors.phone} />
            </Field>
            <Field id="business_type" label="Type of shop" optional hint="Starts the till’s first-run wizard." error={errors.business_type}>
                <Select value={data.business_type || undefined} onValueChange={(value) => setData('business_type', value)}>
                    <SelectTrigger id="business_type" aria-invalid={!!errors.business_type}>
                        <SelectValue placeholder="The till asks" />
                    </SelectTrigger>
                    <SelectContent>
                        {businessTypes.map((type) => (
                            <SelectItem key={type.value} value={type.value}>
                                {type.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </Field>
            <Field
                id="address"
                label="Registered address"
                optional
                hint="Street lines, as printed on receipts."
                error={errors.address}
                className="sm:col-span-2"
            >
                <Textarea id="address" rows={3} value={data.address} onChange={(e) => setData('address', e.target.value)} />
            </Field>
            <Field id="town" label="Town" optional error={errors.town}>
                <Input id="town" value={data.town} onChange={(e) => setData('town', e.target.value)} aria-invalid={!!errors.town} />
            </Field>
            <Field id="postcode" label="Postcode" optional error={errors.postcode}>
                <Input
                    id="postcode"
                    value={data.postcode}
                    onChange={(e) => setData('postcode', e.target.value.toUpperCase())}
                    aria-invalid={!!errors.postcode}
                    autoComplete="off"
                    className="max-w-40 uppercase"
                />
            </Field>
            <Field
                id="receipt_footer"
                label="Receipt footer"
                optional
                hint="Printed at the bottom of every receipt, up to 200 characters."
                error={errors.receipt_footer}
                className="sm:col-span-2"
            >
                <Textarea
                    id="receipt_footer"
                    rows={2}
                    maxLength={200}
                    value={data.receipt_footer}
                    onChange={(e) => setData('receipt_footer', e.target.value)}
                />
            </Field>
        </>
    );
}
