import { OptionSelect } from '@/components/app/products/fields';
import { FormCard, FormField, FormGrid, FormSection } from '@/components/shared/form-section';
import { Input } from '@/components/ui/input';
import { type InvoiceDraft, type InvoiceReviewProps } from './types';
import { taxName, vatNumberLabel } from '@/lib/country';

interface HeaderFieldsProps {
    data: InvoiceDraft;
    editable: boolean;
    errors: Record<string, string>;
    suppliers: InvoiceReviewProps['suppliers'];
    orders: InvoiceReviewProps['orders'];
    deliveries: InvoiceReviewProps['deliveries'];
    onChange: (patch: Partial<InvoiceDraft>) => void;
}

/** Supplier, number, dates, the shop's order and delivery it belongs to, and the totals printed on the document. */
export function HeaderFields({ data, editable, errors, suppliers, orders, deliveries, onChange }: HeaderFieldsProps) {
    const text = (key: keyof InvoiceDraft, label: string, options: { optional?: boolean; help?: string; type?: string; mono?: boolean } = {}) => (
        <FormField id={key} label={label} optional={options.optional} help={options.help} error={errors[key]}>
            <Input
                id={key}
                type={options.type ?? 'text'}
                value={(data[key] as string | null) ?? ''}
                disabled={!editable}
                inputMode={key.endsWith('Total') ? 'decimal' : undefined}
                className={options.mono ? 'font-mono' : key.endsWith('Total') ? 'tabular-nums' : undefined}
                aria-invalid={Boolean(errors[key]) || undefined}
                onChange={(e) => onChange({ [key]: e.target.value === '' ? null : e.target.value })}
            />
        </FormField>
    );

    return (
        <FormCard>
            <FormSection title="Supplier and document" description="Pick the supplier if it was not matched. The supplier's name as printed is kept for reference.">
                <FormGrid>
                    <FormField
                        id="supplierId"
                        label="Supplier"
                        error={errors.supplierId}
                        help={data.supplierName ? `Printed as “${data.supplierName}”.` : undefined}
                    >
                        <OptionSelect
                            id="supplierId"
                            value={data.supplierId ?? ''}
                            disabled={!editable}
                            invalid={Boolean(errors.supplierId)}
                            none="Match automatically"
                            onChange={(value) => onChange({ supplierId: value || null, supplierPinned: value !== '', documentPinned: false })}
                            options={suppliers.map((s) => ({ value: s.id, label: s.name }))}
                        />
                    </FormField>
                    <FormField id="documentType" label="Document">
                        <OptionSelect
                            id="documentType"
                            value={data.documentType}
                            disabled={!editable}
                            onChange={(value) => onChange({ documentType: value === 'deliveryNote' ? 'deliveryNote' : 'invoice' })}
                            options={[
                                { value: 'invoice', label: 'Invoice' },
                                { value: 'deliveryNote', label: 'Delivery note' },
                            ]}
                        />
                    </FormField>
                    {text('invoiceNumber', 'Invoice or delivery note number', { mono: true })}
                    {text('invoiceDate', 'Date', { type: 'date' })}
                    {text('orderReference', 'Order reference', { optional: true, mono: true, help: 'Your order number, when the supplier prints it.' })}
                    {text('supplierVatNumber', `Supplier ${vatNumberLabel()}`, { optional: true, mono: true })}
                </FormGrid>
            </FormSection>

            <FormSection title="The shop's order and delivery" description="Found from the order reference and the products. The lines are compared with them.">
                <FormGrid>
                    <FormField id="purchaseOrderId" label="Order" optional error={errors.purchaseOrderId}>
                        <OptionSelect
                            id="purchaseOrderId"
                            value={data.purchaseOrderId ?? ''}
                            disabled={!editable}
                            none="Find automatically"
                            onChange={(value) => onChange({ purchaseOrderId: value || null, documentPinned: value !== '' || data.goodsReceiptId !== null })}
                            options={orders.map((o) => ({ value: o.id, label: o.label }))}
                        />
                    </FormField>
                    <FormField id="goodsReceiptId" label="Delivery booked in" optional error={errors.goodsReceiptId}>
                        <OptionSelect
                            id="goodsReceiptId"
                            value={data.goodsReceiptId ?? ''}
                            disabled={!editable}
                            none="Find automatically"
                            onChange={(value) => onChange({ goodsReceiptId: value || null, documentPinned: value !== '' || data.purchaseOrderId !== null })}
                            options={deliveries.map((d) => ({ value: d.id, label: d.label }))}
                        />
                    </FormField>
                </FormGrid>
            </FormSection>

            <FormSection title="Totals as printed" description="Leave a total empty if the document does not show it.">
                <FormGrid columns={3}>
                    {text('netTotal', 'Net total', { optional: true })}
                    {text('vatTotal', taxName(), { optional: true })}
                    {text('grossTotal', 'Total to pay', { optional: true })}
                </FormGrid>
            </FormSection>
        </FormCard>
    );
}
