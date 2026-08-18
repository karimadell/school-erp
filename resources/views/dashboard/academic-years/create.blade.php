@extends('layouts.dashboard')

@section('content')

<div class="ui2-scope mx-auto max-w-2xl">

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">{{ __('academic_years.create') }}</h1>
            <p class="text-sm text-slate-500">{{ __('academic_years.create_hint') }}</p>
        </div>

        <a href="{{ route('dashboard.academic-years.index') }}" class="ui2-btn border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">
            {{ __('academic_years.back') }}
        </a>
    </div>

    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
            <p class="font-medium">{{ __('academic_years.validation_error') }}</p>
            <ul class="mt-1 list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="ui2-card">
        <form method="POST" action="{{ route('dashboard.academic-years.store') }}">
            @csrf

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">

                <div class="sm:col-span-2">
                    <label for="name" class="ui2-label">
                        {{ __('academic_years.name') }} <span class="text-red-500">*</span>
                    </label>
                    <input type="text" id="name" name="name" value="{{ old('name') }}"
                           class="ui2-input @error('name') is-invalid @enderror"
                           placeholder="{{ __('academic_years.name_placeholder') }}" required>
                    @error('name')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="start_date" class="ui2-label">
                        {{ __('academic_years.start_date') }} <span class="text-red-500">*</span>
                    </label>
                    <input type="date" id="start_date" name="start_date" value="{{ old('start_date') }}"
                           class="ui2-input @error('start_date') is-invalid @enderror" required>
                    @error('start_date')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="end_date" class="ui2-label">
                        {{ __('academic_years.end_date') }} <span class="text-red-500">*</span>
                    </label>
                    <input type="date" id="end_date" name="end_date" value="{{ old('end_date') }}"
                           class="ui2-input @error('end_date') is-invalid @enderror" required>
                    @error('end_date')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="sm:col-span-2 flex items-start gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" id="is_active" name="is_active" value="1"
                           class="mt-1 h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                           @checked(old('is_active'))>
                    <label for="is_active" class="text-sm text-slate-700">
                        <span class="block font-medium">{{ __('academic_years.is_active') }}</span>
                        <span class="text-slate-500">{{ __('academic_years.is_active_hint') }}</span>
                    </label>
                </div>

            </div>

            <div class="mt-6 flex gap-2">
                <button type="submit" class="ui2-btn bg-indigo-600 text-white hover:bg-indigo-500">
                    {{ __('academic_years.save') }}
                </button>
                <a href="{{ route('dashboard.academic-years.index') }}" class="ui2-btn border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">
                    {{ __('academic_years.cancel') }}
                </a>
            </div>

        </form>
    </div>

</div>

@endsection
