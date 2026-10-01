<?php

namespace App\Http\Requests\App\Labels;

use App\Domain\Labels\Models\LabelTemplate;
use App\Domain\Labels\Support\LabelStocks;
use App\Domain\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creates, edits or deletes a label template (gap #6). Route: `company.can:labels.print`. A one-shop user works only
 * on their own shop's templates (not the every-shop ones): 403 otherwise.
 */
class LabelTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $restricted = app(CurrentCompany::class)->restrictedBranchId();

        if ($restricted === null) {
            return true;
        }

        $template = $this->template();

        return ($template === null || $template->branch_id === $restricted)
            && ($this->isMethod('delete') || $this->input('branch_id') === $restricted);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->isMethod('delete')) {
            return [];
        }

        $options = [];
        foreach (LabelTemplate::OPTIONS as $key) {
            $options["options.{$key}"] = ['sometimes', 'boolean'];
        }

        return [
            'name' => ['required', 'string', 'max:60'],
            'stock' => ['required', 'string', Rule::in(LabelStocks::keys())],
            'branch_id' => ['nullable', 'string', 'size:26'],
            'is_default' => ['sometimes', 'boolean'],
            'options' => ['sometimes', 'array'],
            ...$options,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['stock.in' => 'Choose a label stock.', 'name.required' => 'Give the template a name.'];
    }

    /** The template in the route (null when creating); another business's is not found. */
    public function template(): ?LabelTemplate
    {
        $id = $this->route('template');

        return is_string($id) ? LabelTemplate::query()->findOrFail($id) : null;
    }
}
