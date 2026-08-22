<?php

namespace App\Http\Requests;

use App\Services\PatchArchive;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PatchSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $categories = $this->query('categories', []);
        if (is_string($categories)) $categories = [$categories];
        if (! is_array($categories)) $categories = [];

        $query = $this->query('q');
        if (is_string($query)) {
            $query = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $query));
        }

        $this->merge([
            'q' => $query,
            'categories' => array_values(array_unique(array_filter($categories, 'is_string'))),
        ]);
    }

    public function rules(PatchArchive $archive): array
    {
        $facets = $archive->facets();
        $coverage = $archive->coverage();

        return [
            'q' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$coverage['first_patch'], 'before_or_equal:'.$coverage['last_patch']],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$coverage['first_patch'], 'after_or_equal:from', 'before_or_equal:'.$coverage['last_patch']],
            'year' => ['nullable', 'integer', Rule::in(array_keys($facets['years']))],
            'kind' => ['nullable', 'string', Rule::in(array_keys($facets['kinds']))],
            'expansion' => ['nullable', 'string', Rule::in(array_keys($facets['expansions']))],
            'source' => ['nullable', 'string', Rule::in(array_keys($facets['sources']))],
            'categories' => ['array', 'max:6'],
            'categories.*' => ['string', Rule::in(array_keys($facets['categories']))],
            'scope' => ['nullable', Rule::in(['all', 'title', 'body'])],
            'sort' => ['nullable', Rule::in(['relevance', 'newest', 'oldest'])],
            'view' => ['nullable', Rule::in(['cards', 'compact', 'expansions', 'timeline'])],
            'per_page' => ['nullable', 'integer', Rule::in([12, 24, 48])],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }

    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'q' => $validated['q'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'year' => isset($validated['year']) ? (int) $validated['year'] : null,
            'kind' => $validated['kind'] ?? null,
            'expansion' => $validated['expansion'] ?? null,
            'source' => $validated['source'] ?? null,
            'categories' => $validated['categories'] ?? [],
            'scope' => $validated['scope'] ?? 'all',
            'sort' => $validated['sort'] ?? (($validated['q'] ?? null) ? 'relevance' : 'newest'),
            'view' => $validated['view'] ?? 'cards',
            'per_page' => (int) ($validated['per_page'] ?? 24),
        ];
    }
}
