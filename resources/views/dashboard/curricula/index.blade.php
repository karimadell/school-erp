@extends('layouts.dashboard')

@section('content')

<div class="ui2-scope mx-auto max-w-6xl">

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">{{ __('curriculum.title') }}</h1>
            <p class="text-sm text-slate-500">{{ __('curriculum.list_hint') }}</p>
        </div>

        <a href="{{ route('dashboard.curricula.create') }}" class="ui2-btn bg-indigo-600 text-white hover:bg-indigo-500">
            <x-ui-icon name="plus" class="h-4 w-4" />
            {{ __('curriculum.create') }}
        </a>
    </div>

    <form method="GET" action="{{ route('dashboard.curricula.index') }}" class="ui2-card mb-4">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3 sm:items-end">

            <div>
                <label for="academic_year_id" class="ui2-label">{{ __('curriculum.academic_year') }}</label>
                <select id="academic_year_id" name="academic_year_id" class="ui2-input">
                    <option value="">{{ __('curriculum.all_academic_years') }}</option>
                    @foreach($academicYears as $year)
                        <option value="{{ $year->id }}" @selected(request('academic_year_id') == $year->id)>{{ $year->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="grade_id" class="ui2-label">{{ __('curriculum.grade') }}</label>
                <select id="grade_id" name="grade_id" class="ui2-input">
                    <option value="">{{ __('curriculum.all_grades') }}</option>
                    @foreach($grades as $grade)
                        <option value="{{ $grade->id }}" @selected(request('grade_id') == $grade->id)>{{ $grade->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="type" class="ui2-label">{{ __('curriculum.type') }}</label>
                <select id="type" name="type" class="ui2-input">
                    <option value="">{{ __('curriculum.all_types') }}</option>
                    <option value="mandatory" @selected(request('type') === 'mandatory')>{{ __('curriculum.type_mandatory') }}</option>
                    <option value="elective" @selected(request('type') === 'elective')>{{ __('curriculum.type_elective') }}</option>
                    <option value="optional_enrichment" @selected(request('type') === 'optional_enrichment')>{{ __('curriculum.type_optional_enrichment') }}</option>
                </select>
            </div>

        </div>

        <div class="mt-4 flex gap-2">
            <button type="submit" class="ui2-btn bg-indigo-600 text-white hover:bg-indigo-500">
                {{ __('curriculum.filter') }}
            </button>
            <a href="{{ route('dashboard.curricula.index') }}" class="ui2-btn border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">
                {{ __('curriculum.reset_filters') }}
            </a>
        </div>
    </form>

    <div class="ui2-card p-0">
        <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
            <span class="text-sm font-semibold text-slate-900">{{ __('curriculum.list') }}</span>
            <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600">
                {{ $curricula->total() }} {{ __('curriculum.total') }}
            </span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-start text-sm">
                <thead>
                    <tr class="border-b border-slate-100 text-xs uppercase tracking-wide text-slate-500">
                        <th class="px-6 py-3 text-start font-medium">{{ __('curriculum.academic_year') }}</th>
                        <th class="px-6 py-3 text-start font-medium">{{ __('curriculum.grade') }}</th>
                        <th class="px-6 py-3 text-start font-medium">{{ __('curriculum.subject') }}</th>
                        <th class="px-6 py-3 text-start font-medium">{{ __('curriculum.weekly_hours') }}</th>
                        <th class="px-6 py-3 text-start font-medium">{{ __('curriculum.type') }}</th>
                        <th class="px-6 py-3 text-start font-medium">{{ __('curriculum.assessment_type') }}</th>
                        <th class="px-6 py-3 text-start font-medium">{{ __('curriculum.is_active') }}</th>
                        <th class="px-6 py-3 text-end font-medium">{{ __('curriculum.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($curricula as $entry)
                        <tr class="border-b border-slate-50 last:border-0">
                            <td class="px-6 py-3 text-slate-700">{{ $entry->academicYear?->name }}</td>
                            <td class="px-6 py-3 font-medium text-slate-900">{{ $entry->grade?->name }}</td>
                            <td class="px-6 py-3 text-slate-700">{{ $entry->subject?->name_ru }}</td>
                            <td class="px-6 py-3 text-slate-700">{{ $entry->weekly_hours }}</td>
                            <td class="px-6 py-3">
                                <span class="rounded-full bg-indigo-50 px-2.5 py-0.5 text-xs font-medium text-indigo-700">
                                    {{ __('curriculum.type_' . $entry->type) }}
                                </span>
                            </td>
                            <td class="px-6 py-3 text-slate-700">
                                {{ match ($entry->assessment_type) {
                                    'grade', null => __('curriculum.assessment_grade'),
                                    'pass_fail' => __('curriculum.assessment_pass_fail'),
                                    'ungraded' => __('curriculum.assessment_ungraded'),
                                    default => '—',
                                } }}
                            </td>
                            <td class="px-6 py-3">
                                <span class="rounded-full px-2.5 py-0.5 text-xs font-medium {{ $entry->is_active ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-600' }}">
                                    {{ $entry->is_active ? __('curriculum.active') : __('curriculum.inactive') }}
                                </span>
                            </td>
                            <td class="px-6 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('dashboard.curricula.edit', $entry) }}"
                                       class="ui2-btn-icon"
                                       title="{{ __('curriculum.edit_btn') }}">
                                        <x-ui-icon name="pencil" class="h-4 w-4" />
                                    </a>

                                    @can('delete', $entry)
                                        <form action="{{ route('dashboard.curricula.destroy', $entry) }}"
                                              method="POST"
                                              onsubmit="return confirm('{{ __('curriculum.confirm_delete') }}')">
                                            @csrf
                                            @method('DELETE')

                                            <button type="submit" class="ui2-btn-icon text-red-600 hover:bg-red-50" title="{{ __('curriculum.delete') }}">
                                                <x-ui-icon name="trash" class="h-4 w-4" />
                                            </button>
                                        </form>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-6 py-10 text-center text-sm text-slate-500">
                                {{ __('curriculum.no_data') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($curricula->hasPages())
            <div class="border-t border-slate-100 px-6 py-4">
                {{ $curricula->links() }}
            </div>
        @endif
    </div>

</div>

@endsection
