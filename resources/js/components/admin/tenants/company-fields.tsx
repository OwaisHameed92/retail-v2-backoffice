import { Field, Textarea } from '@/components/admin/tenants/field';
import { Input } from '@/components/ui/input';

export type CompanyFieldsData = {
    name: string;
    legal_name: string;
    vat_number: string;
    company_number: string;
    email: string;
    phone: string;
    contact_name: string;
    address: string;
};

interface CompanyFieldsProps<T extends CompanyFieldsData> {
    data: T;
    setData: (key: keyof CompanyFieldsData, value: string) => void;
    errors: Partial<Record<string, string>>;
}

/** Business details shared by the create wizard and the edit page. Rendered inside a FormSection grid. */
export function CompanyFields<T extends CompanyFieldsData>({ data, setData, errors }: CompanyFieldsProps<T>) {
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
            <Field id="address" label="Registered address" optional error={errors.address} className="sm:col-span-2">
                <Textarea id="address" rows={3} value={data.address} onChange={(e) => setData('address', e.target.value)} />
            </Field>
        </>
    );
}
