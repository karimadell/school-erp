{{--
    Reusable searchable multi-select rendered as a filterable checkbox list.
    Framework-free (dashboard pages do not load Alpine): behaviour is wired by
    the small vanilla script pushed from the create/edit forms.

    Expected variables:
      - $name            submitted field name, e.g. "class_ids[]"
      - $inputId         id prefix for the rendered checkboxes
      - $options         array of ['id' => int, 'label' => string]
      - $selected        Collection|array of selected ids
      - $searchPlaceholder, $emptyText, $groupLabel   translated strings
      - $countLabel      translated template containing ":count"
      - $invalid         optional bool to flag validation error state
--}}
@php
    $selected = collect($selected ?? [])->map(fn ($id) => (int) $id);
    $invalid = $invalid ?? false;
    $initialCount = str_replace(':count', (string) $selected->count(), $countLabel);
@endphp
<div class="mb-checklist" data-mb-checklist>
    <input type="search" class="form-control form-control-sm mb-2" data-mb-search
           placeholder="{{ $searchPlaceholder }}" aria-label="{{ $searchPlaceholder }}" autocomplete="off">

    <div class="small text-muted mb-1" data-mb-count data-mb-template="{{ $countLabel }}" aria-live="polite">{{ $initialCount }}</div>

    <div class="border rounded p-2 {{ $invalid ? 'border-danger' : '' }}"
         style="max-height: 12rem; overflow-y: auto;"
         role="group" aria-label="{{ $groupLabel }}">
        @forelse($options as $opt)
            <div class="form-check" data-mb-item data-label="{{ \Illuminate\Support\Str::lower($opt['label']) }}">
                <input class="form-check-input" type="checkbox" name="{{ $name }}"
                       value="{{ $opt['id'] }}" id="{{ $inputId }}_{{ $opt['id'] }}"
                       @checked($selected->contains($opt['id']))>
                <label class="form-check-label w-100" for="{{ $inputId }}_{{ $opt['id'] }}">{{ $opt['label'] }}</label>
            </div>
        @empty
            <p class="text-muted small mb-0">{{ $emptyText }}</p>
        @endforelse
        <p class="text-muted small mb-0 d-none" data-mb-noresults>{{ __('mass_billing.search.no_results') }}</p>
    </div>
</div>
