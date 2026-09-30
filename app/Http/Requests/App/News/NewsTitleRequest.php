<?php

namespace App\Http\Requests\App\News;

use App\Domain\News\Support\NewsAccess;
use App\Domain\TillData\Enums\NewsTitleFrequency;
use App\Domain\TillData\Models\NewsTitle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Saves a news title (POST/PUT) or archives / restores one (module 5.8). Route: `company.can:news.manage`. A title
 * for every shop, and choosing "every shop" or another shop, need a user of every shop; a one-shop user keeps only
 * their own shop's titles (403 otherwise; another shop's title is "not found"). Ids are checked by SaveNewsTitle.
 */
class NewsTitleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $title = $this->newsTitle();

        if ($title !== null) {
            abort_unless(NewsAccess::mayView($title), 404);

            if (! NewsAccess::mayEdit($title)) {
                return false;
            }
        }

        if ($this->routeIs('app.news.titles.store', 'app.news.titles.update')) {
            $shop = $this->input('branch_id');

            return NewsAccess::mayUseShop(is_string($shop) && $shop !== '' ? $shop : null);
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if (! $this->routeIs('app.news.titles.store', 'app.news.titles.update')) {
            return [];
        }

        return [
            'name' => ['required', 'string', 'max:120'],
            'publisher' => ['nullable', 'string', 'max:120'],
            'frequency' => ['required', Rule::enum(NewsTitleFrequency::class)],
            'supplier_id' => ['required', 'string', 'max:64'],
            'cover_price' => ['required', 'regex:/^\d{1,6}(\.\d{1,2})?$/'],
            'linked_product_id' => ['nullable', 'string', 'max:64'],
            'linked_barcode' => ['nullable', 'string', 'max:64'],
            'branch_id' => ['nullable', 'string', 'size:26'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cover_price.regex' => 'Enter the cover price in pounds, e.g. 1.80.',
            'supplier_id.required' => 'Choose the wholesaler that supplies this title.',
        ];
    }

    /**
     * @return array{name: string, publisher: string|null, frequency: string, supplier_id: string, cover_price: string, linked_product_id: string|null, linked_barcode: string|null, branch_id: string|null, is_active: bool}
     */
    public function titleInput(): array
    {
        return [
            'name' => (string) $this->input('name'),
            'publisher' => $this->filled('publisher') ? (string) $this->input('publisher') : null,
            'frequency' => (string) $this->input('frequency'),
            'supplier_id' => (string) $this->input('supplier_id'),
            'cover_price' => (string) $this->input('cover_price'),
            'linked_product_id' => $this->filled('linked_product_id') ? (string) $this->input('linked_product_id') : null,
            'linked_barcode' => $this->filled('linked_barcode') ? (string) $this->input('linked_barcode') : null,
            'branch_id' => $this->filled('branch_id') ? (string) $this->input('branch_id') : null,
            'is_active' => $this->boolean('is_active', true),
        ];
    }

    public function newsTitle(): ?NewsTitle
    {
        $id = $this->route('title');

        return is_string($id) ? NewsTitle::query()->find($id) : null;
    }
}
