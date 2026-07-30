@extends('layouts.dashboard')

@section('content')

<div class="ui2-scope mx-auto max-w-2xl">

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">{{ __('curriculum.edit') }}</h1>
            <p class="text-sm text-slate-500">{{ __('curriculum.edit_hint') }}</p>
        </div>

        <a href="{{ route('dashboard.curricula.index') }}" class="ui2-btn border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">
            {{ __('curriculum.back') }}
        </a>
    </div>

    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
            <p class="font-medium">{{ __('curriculum.validation_error') }}</p>
            <ul class="mt-1 list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="ui2-card">
        <form method="POST" action="{{ route('dashboard.curricula.update', $curriculum) }}">
            @csrf
            @method('PUT')

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">

                <div>
                    <label for="academic_year_id" class="ui2-label">
                        {{ __('curriculum.academic_year') }} <span class="text-red-500">*</span>
                    </label>
                    <select id="academic_year_id" name="academic_year_id"
                            class="ui2-input @error('academic_year_id') is-invalid @enderror" required>
                        <option value="">—</option>
                        @foreach($academicYears as $year)
                            <option value="{{ $year->id }}" @selected(old('academic_year_id', $curriculum->academic_year_id) == $year->id)>{{ $year->name }}</option>
                        @endforeach
                    </select>
                    @error('academic_year_id')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="grade_id" class="ui2-label">
                        {{ __('curriculum.grade') }} <span class="text-red-500">*</span>
                    </label>
                    <select id="grade_id" name="grade_id"
                            class="ui2-input @error('grade_id') is-invalid @enderror" required>
                        <option value="">—</option>
                        @foreach($grades as $grade)
                            <option value="{{ $grade->id }}" @selected(old('grade_id', $curriculum->grade_id) == $grade->id)>{{ $grade->name }}</option>
                        @endforeach
                    </select>
                    @error('grade_id')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="subject_id" class="ui2-label">
                        {{ __('curriculum.subject') }} <span class="text-red-500">*</span>
                    </label>
                    <select id="subject_id" name="subject_id"
                            class="ui2-input @error('subject_id') is-invalid @enderror" required>
                        <option value="">—</option>
                        @foreach($subjects as $subject)
                            <option value="{{ $subject->id }}" @selected(old('subject_id', $curriculum->subject_id) == $subject->id)>{{ $subject->name_ru }}</option>
                        @endforeach
                    </select>
                    @error('subject_id')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="weekly_hours" class="ui2-label">
                        {{ __('curriculum.weekly_hours') }} <span class="text-red-500">*</span>
                    </label>
                    <input type="number" id="weekly_hours" name="weekly_hours" min="1"
                           value="{{ old('weekly_hours', $curriculum->weekly_hours) }}"
                           class="ui2-input @error('weekly_hours') is-invalid @enderror" required>
                    @error('weekly_hours')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="sm:col-span-2">
                    <label for="type" class="ui2-label">
                        {{ __('curriculum.type') }} <span class="text-red-500">*</span>
                    </label>
                    <select id="type" name="type"
                            class="ui2-input @error('type') is-invalid @enderror" required>
                        <option value="">—</option>
                        <option value="mandatory" @selected(old('type', $curriculum->type) === 'mandatory')>{{ __('curriculum.type_mandatory') }}</option>
                        <option value="elective" @selected(old('type', $curriculum->type) === 'elective')>{{ __('curriculum.type_elective') }}</option>
                        <option value="optional_enrichment" @selected(old('type', $curriculum->type) === 'optional_enrichment')>{{ __('curriculum.type_optional_enrichment') }}</option>
                    </select>
                    @error('type')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

            </div>

            <div class="mt-6 flex gap-2">
                <button type="submit" class="ui2-btn bg-indigo-600 text-white hover:bg-indigo-500">
                    {{ __('curriculum.save') }}
                </button>
                <a href="{{ route('dashboard.curricula.index') }}" class="ui2-btn border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">
                    {{ __('curriculum.cancel') }}
                </a>
            </div>

        </form>
    </div>

</div>

@endsection
