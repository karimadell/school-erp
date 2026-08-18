@extends('layouts.dashboard')

@section('content')

<div class="ui2-scope mx-auto max-w-5xl">

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">{{ __('academic_years.title') }}</h1>
            <p class="text-sm text-slate-500">{{ __('academic_years.list_hint') }}</p>
        </div>

        <a href="{{ route('dashboard.academic-years.create') }}" class="ui2-btn bg-indigo-600 text-white hover:bg-indigo-500">
            <x-ui-icon name="plus" class="h-4 w-4" />
            {{ __('academic_years.create') }}
        </a>
    </div>

    @if(session('success'))<div class="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700"><ul class="list-inside list-disc">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <div class="ui2-card p-0">
        <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
            <span class="text-sm font-semibold text-slate-900">{{ __('academic_years.list') }}</span>
            <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600">
                {{ $academicYears->total() }} {{ __('academic_years.total') }}
            </span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-start text-sm">
                <thead>
                    <tr class="border-b border-slate-100 text-xs uppercase tracking-wide text-slate-500">
                        <th class="px-6 py-3 text-start font-medium">{{ __('academic_years.name') }}</th>
                        <th class="px-6 py-3 text-start font-medium">{{ __('academic_years.start_date') }}</th>
                        <th class="px-6 py-3 text-start font-medium">{{ __('academic_years.end_date') }}</th>
                        <th class="px-6 py-3 text-start font-medium">{{ __('academic_years.status') }}</th>
                        <th class="px-6 py-3 text-end font-medium">{{ __('academic_years.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($academicYears as $academicYear)
                        <tr class="border-b border-slate-50 last:border-0">
                            <td class="px-6 py-3 font-medium text-slate-900">{{ $academicYear->name }}</td>
                            <td class="px-6 py-3 text-slate-600">{{ $academicYear->start_date?->format('d.m.Y') }}</td>
                            <td class="px-6 py-3 text-slate-600">{{ $academicYear->end_date?->format('d.m.Y') }}</td>
                            <td class="px-6 py-3">
                                @if($academicYear->is_active)
                                    <span class="rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">
                                        {{ __('academic_years.active') }}
                                    </span>
                                @else
                                    @if($academicYear->unlocks->isNotEmpty())
                                        <span class="rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700">{{ __('academic_years.unlocked') }}</span>
                                    @else
                                        <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600">{{ __('academic_years.locked_status') }}</span>
                                    @endif
                                @endif
                            </td>
                            <td class="px-6 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('dashboard.academic-years.quarters.index', $academicYear) }}"
                                       class="ui2-btn border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">
                                        {{ __('quarters.title') }}
                                    </a>
                                    <a href="{{ route('dashboard.academic-years.edit', $academicYear) }}"
                                       class="ui2-btn-icon"
                                       title="{{ __('academic_years.edit_btn') }}">
                                        <x-ui-icon name="pencil" class="h-4 w-4" />
                                    </a>

                                    @can('delete', $academicYear)
                                        <form action="{{ route('dashboard.academic-years.destroy', $academicYear) }}"
                                              method="POST"
                                              onsubmit="return confirm('{{ __('academic_years.confirm_delete') }}')">
                                            @csrf
                                            @method('DELETE')

                                            <button type="submit" class="ui2-btn-icon text-red-600 hover:bg-red-50" title="{{ __('academic_years.delete') }}">
                                                <x-ui-icon name="trash" class="h-4 w-4" />
                                            </button>
                                        </form>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-10 text-center text-sm text-slate-500">
                                {{ __('academic_years.no_data') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($academicYears->hasPages())
            <div class="border-t border-slate-100 px-6 py-4">
                {{ $academicYears->links() }}
            </div>
        @endif
    </div>

</div>

@endsection
