import { FormField, FormGrid, FormSection as SharedFormSection } from '@/components/shared/form-section';
import { Textarea as UiTextarea } from '@/components/ui/textarea';
import { type ReactNode, type TextareaHTMLAttributes } from 'react';

interface FieldProps {
    id: string;
    label: string;
    hint?: ReactNode;
    error?: string;
    optional?: boolean;
    className?: string;
    children: ReactNode;
}

/** Label above, control, helper text, inline error. Same as the shared FormField (`hint` = `help`). */
export function Field({ hint, ...props }: FieldProps) {
    return <FormField help={hint} {...props} />;
}

export function Textarea(props: TextareaHTMLAttributes<HTMLTextAreaElement>) {
    return <UiTextarea {...props} />;
}

/** A titled group of fields inside a FormCard: title left, a two-column field grid right. */
export function FormSection({ title, description, children }: { title: string; description?: ReactNode; children: ReactNode }) {
    return (
        <SharedFormSection title={title} description={description}>
            <FormGrid>{children}</FormGrid>
        </SharedFormSection>
    );
}
