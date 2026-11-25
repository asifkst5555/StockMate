<?php

namespace App\Filters;

use Illuminate\Database\Eloquent\Builder;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * Class CustomSearchFilter
 */
class CustomSearchFilter implements Filter
{
    /** @var array<int, string> */
    public $searchableFields;

    /** @var array<int, string> */
    protected $allowedFields;

    /** @var bool */
    protected $hasDisallowedSearchFieldsOnly = false;

    public function __construct($searchableFields)
    {
        $this->allowedFields = $this->normalizeFields(is_array($searchableFields) ? $searchableFields : []);
        $this->searchableFields = $this->allowedFields;

        $filter = request()->get('filter');
        $filterSearchFields = is_array($filter) ? ($filter['search_fields'] ?? null) : null;

        if (is_string($filterSearchFields) && trim($filterSearchFields) !== '') {
            $requestedFields = $this->normalizeFields(explode(',', $filterSearchFields));
            $safeFields = array_values(array_intersect($requestedFields, $this->allowedFields));

            if (! empty($safeFields)) {
                $this->searchableFields = $safeFields;
            } else {
                $this->searchableFields = [];
                $this->hasDisallowedSearchFieldsOnly = true;
            }
        } elseif (is_array($filterSearchFields) && ! empty($filterSearchFields)) {
            $requestedFields = $this->normalizeFields($filterSearchFields);
            $safeFields = array_values(array_intersect($requestedFields, $this->allowedFields));

            if (! empty($safeFields)) {
                $this->searchableFields = $safeFields;
            } else {
                $this->searchableFields = [];
                $this->hasDisallowedSearchFieldsOnly = true;
            }
        }
    }

    public function __invoke(Builder $query, $value, string $property): Builder
    {
        if ($this->hasDisallowedSearchFieldsOnly) {
            return $query->whereRaw('1 = 0');
        }

        if (empty($this->searchableFields)) {
            return $query;
        }

        $searchValues = is_array($value) ? $value : [$value];
        $searchValues = array_values(array_filter(
            array_map(static fn ($item) => is_scalar($item) ? (string) $item : '', $searchValues),
            static fn (string $item) => $item !== ''
        ));

        if (empty($searchValues)) {
            return $query;
        }

        return $query->where(function (Builder $nestedQuery) use ($searchValues) {
            foreach ($this->searchableFields as $searchableField) {
                foreach ($searchValues as $string) {
                    $nestedQuery->orWhere($searchableField, 'LIKE', '%'.$string.'%');
                }
            }
        });
    }

    /**
     * @param  array<int, mixed>  $fields
     * @return array<int, string>
     */
    protected function normalizeFields(array $fields): array
    {
        $normalized = [];
        foreach ($fields as $field) {
            if (! is_string($field)) {
                continue;
            }
            $field = trim($field);
            if ($field !== '' && preg_match('/^[a-zA-Z0-9_]+(\.[a-zA-Z0-9_]+)?$/', $field) === 1) {
                $normalized[] = $field;
            }
        }

        return array_values(array_unique($normalized));
    }
}
