@extends('layouts.dashboard')

@section('content')
<div class="mx-auto max-w-7xl">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-slate-900">{{ __('classes.title') }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ __('classes.list_description') }}</p>
        </div>
        @can('manage classes')
            <a href="{{ route('dashboard.classes.create') }}" class="inline-flex min-h-11 items-center justify-center gap-2 self-start rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 sm:self-auto">
                <span class="text-lg leading-none" aria-hidden="true">+</span>{{ __('classes.create') }}
            </a>
        @endcan
    </div>

    @if($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
            <ul class="mb-0 list-disc ps-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm" aria-labelledby="classes-list-heading">
        <div class="border-b border-slate-200 px-4 py-4 sm:px-6">
            <h2 id="classes-list-heading" class="text-base font-semibold text-slate-900">{{ __('classes.list') }}</h2>
            <p class="mt-1 text-sm text-slate-500">{{ trans_choice('classes.total', $classes->count(), ['count' => $classes->count()]) }}</p>
        </div>

        @if($classes->isEmpty())
            <div class="flex flex-col items-center px-6 py-14 text-center">
                <div class="mb-4 grid h-12 w-12 place-items-center rounded-full bg-slate-100 text-2xl text-slate-500" aria-hidden="true">🏫</div>
                <h3 class="font-semibold text-slate-900">{{ __('classes.empty_heading') }}</h3>
                <p class="mt-1 max-w-sm text-sm text-slate-500">{{ __('classes.empty_description') }}</p>
                @can('manage classes')
                    <a href="{{ route('dashboard.classes.create') }}" class="mt-5 text-sm font-semibold text-blue-600 hover:text-blue-700">+ {{ __('classes.create') }}</a>
                @endcan
            </div>
        @else
            <div class="hidden grid-cols-[minmax(13rem,2fr)_minmax(8rem,1fr)_7rem_7rem_minmax(15rem,auto)] gap-4 border-b border-slate-200 bg-slate-50 px-6 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500 lg:grid">
                <div>{{ __('classes.name') }}</div><div>{{ __('classes.grade') }}</div><div>{{ __('classes.capacity') }}</div><div>{{ __('classes.status') }}</div><div class="text-end">{{ __('classes.actions') }}</div>
            </div>

            <div class="divide-y divide-slate-200">
                @foreach($classes as $class)
                    <article class="grid gap-4 px-4 py-5 transition hover:bg-slate-50/70 sm:px-6 lg:grid-cols-[minmax(13rem,2fr)_minmax(8rem,1fr)_7rem_7rem_minmax(15rem,auto)] lg:items-center">
                        <div class="min-w-0">
                            <h3 class="truncate text-base font-semibold text-slate-900">{{ $class->name_ru ?? $class->name ?? '-' }}</h3>
                            <div class="mt-1 flex items-center gap-2 text-sm text-slate-500"><span class="lg:hidden">{{ __('classes.code') }}:</span><code class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs font-medium text-slate-600">{{ $class->code ?? '-' }}</code></div>
                        </div>
                        <div class="flex items-baseline justify-between gap-3 lg:block"><span class="text-xs font-medium uppercase tracking-wide text-slate-400 lg:hidden">{{ __('classes.grade') }}</span><span class="text-sm font-medium text-slate-700">{{ $class->grade->name ?? '-' }}</span></div>
                        <div class="flex items-baseline justify-between gap-3 lg:block"><span class="text-xs font-medium uppercase tracking-wide text-slate-400 lg:hidden">{{ __('classes.capacity') }}</span><span class="text-sm tabular-nums text-slate-700">{{ $class->capacity ?? '-' }}</span></div>
                        <div class="flex items-center justify-between gap-3 lg:block">
                            <span class="text-xs font-medium uppercase tracking-wide text-slate-400 lg:hidden">{{ __('classes.status') }}</span>
                            @if($class->is_active)
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-200"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>{{ __('classes.active') }}</span>
                            @else
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600 ring-1 ring-inset ring-slate-200"><span class="h-1.5 w-1.5 rounded-full bg-slate-400" aria-hidden="true"></span>{{ __('classes.inactive') }}</span>
                            @endif
                        </div>
                        <div class="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-4 lg:justify-end lg:border-0 lg:pt-0">
                            @if(auth()->user()?->hasAnyPermission(['view timetable', 'manage timetable']))
                                <a href="{{ route('dashboard.classes.timetable', $class->id) }}" class="inline-flex min-h-9 items-center gap-1.5 rounded-lg bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-700 transition hover:bg-blue-100 focus:outline-none focus:ring-2 focus:ring-blue-500" title="{{ __('timetable.view_schedule') }}"><span aria-hidden="true">▦</span>{{ __('timetable.title') }}</a>
                            @endif
                            @can('manage classes')
                                <a href="{{ route('dashboard.classes.edit', $class->id) }}" class="inline-flex min-h-9 items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-400"><span aria-hidden="true">✎</span>{{ __('classes.edit_short') }}<span class="sr-only">: {{ $class->name_ru }}</span></a>
                                <form action="{{ route('dashboard.classes.destroy', $class->id) }}" method="POST" onsubmit="return confirm('{{ __('classes.confirm_delete') }}')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="inline-flex min-h-9 items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-red-600 transition hover:bg-red-50 hover:text-red-700 focus:outline-none focus:ring-2 focus:ring-red-500"><span aria-hidden="true">×</span>{{ __('classes.delete_short') }}<span class="sr-only">: {{ $class->name_ru }}</span></button>
                                </form>
                            @endcan
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
</div>
@endsection
