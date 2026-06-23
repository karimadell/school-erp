@extends('layouts.dashboard')

@section('content')

<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0">🎓 {{ __('students.create') }}</h3>

        <a href="{{ route('dashboard.students.index') }}" class="btn btn-secondary">
            {{ __('students.back') }}
        </a>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST"
          action="{{ route('dashboard.students.store') }}"
          enctype="multipart/form-data">
        @csrf

        <div class="row g-4">

            {{-- Photo Card --}}
            <div class="col-md-3">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-info text-white fw-bold">
                        {{ __('students.photo') }}
                    </div>

                    <div class="card-body text-center">
                        <div id="photoPlaceholder"
                             class="rounded-circle bg-primary text-white mx-auto mb-3 d-flex align-items-center justify-content-center"
                             style="width:160px;height:160px;font-size:60px;">
                            👤
                        </div>

                        <img id="studentPhotoPreview"
                             class="rounded-circle d-none mx-auto mb-3"
                             style="width:160px;height:160px;object-fit:cover;border:4px solid #f1f1f1;">

                        <input type="file"
                               name="photo"
                               id="studentPhotoInput"
                               class="form-control"
                               accept="image/*">

                        <small class="text-muted d-block mt-2">
                            JPG / PNG
                        </small>
                    </div>
                </div>
            </div>

            {{-- Student Data --}}
            <div class="col-md-9">
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-primary text-white fw-bold">
                        {{ __('students.student_info') }}
                    </div>

                    <div class="card-body row g-3">

                        <div class="col-md-4">
                            <label class="form-label">{{ __('students.last_name_ru') }}</label>
                            <input type="text" name="last_name_ru" class="form-control"
                                   value="{{ old('last_name_ru') }}" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">{{ __('students.first_name_ru') }}</label>
                            <input type="text" name="first_name_ru" class="form-control"
                                   value="{{ old('first_name_ru') }}" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">{{ __('students.patronymic_ru') }}</label>
                            <input type="text" name="patronymic_ru" class="form-control"
                                   value="{{ old('patronymic_ru') }}">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">{{ __('students.name_ar') }}</label>
                            <input type="text" name="name_ar" class="form-control"
                                   value="{{ old('name_ar') }}">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">{{ __('students.class') }}</label>
                            <select name="class_id" class="form-select" required>
                                <option value="">{{ __('students.select_class') }}</option>
                                @foreach($classes as $class)
                                    <option value="{{ $class->id }}" @selected(old('class_id') == $class->id)>
                                        {{ $class->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">{{ __('students.birth_date') }}</label>
                            <input type="date" name="birth_date" class="form-control"
                                   value="{{ old('birth_date') }}">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">{{ __('students.gender') }}</label>
                            <select name="gender" class="form-select">
                                <option value="">{{ __('students.select_gender') }}</option>
                                <option value="male" @selected(old('gender') == 'male')>{{ __('students.male') }}</option>
                                <option value="female" @selected(old('gender') == 'female')>{{ __('students.female') }}</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">{{ __('students.phone') }}</label>
                            <input type="text" name="phone" class="form-control"
                                   value="{{ old('phone') }}">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">{{ __('students.email') }}</label>
                            <input type="email" name="email" class="form-control"
                                   value="{{ old('email') }}">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">{{ __('students.nationality') }}</label>
                            <input type="text" name="nationality" class="form-control"
                                   value="{{ old('nationality') }}">
                        </div>

                    </div>
                </div>

                {{-- Address --}}
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-dark text-white fw-bold">
                        {{ __('students.address') }}
                    </div>

                    <div class="card-body">
                        <textarea name="address" class="form-control" rows="3">{{ old('address') }}</textarea>
                    </div>
                </div>

                {{-- Documents --}}
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-info text-white fw-bold">
                        {{ __('students.documents') }}
                    </div>

                    <div class="card-body">
                        <input type="file" name="documents[]" class="form-control" multiple>
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-success">
                        💾 {{ __('students.save') }}
                    </button>

                    <a href="{{ route('dashboard.students.index') }}" class="btn btn-secondary">
                        {{ __('students.cancel') }}
                    </a>
                </div>

            </div>
        </div>

    </form>

</div>

@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('studentPhotoInput');
    const preview = document.getElementById('studentPhotoPreview');
    const placeholder = document.getElementById('photoPlaceholder');

    input.addEventListener('change', function () {
        const file = this.files[0];

        if (!file) {
            preview.src = '';
            preview.classList.add('d-none');
            placeholder.classList.remove('d-none');
            return;
        }

        preview.src = URL.createObjectURL(file);
        preview.classList.remove('d-none');
        placeholder.classList.add('d-none');
    });
});
</script>
@endpush