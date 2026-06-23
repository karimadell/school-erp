@extends('layouts.dashboard')

@section('content')

<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0">✏️ {{ __('students.edit') }}</h3>

        <a href="{{ route('dashboard.students.show', $student->id) }}" class="btn btn-secondary">
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
          action="{{ route('dashboard.students.update', $student->id) }}"
          enctype="multipart/form-data">

        @csrf
        @method('PUT')

        {{-- بيانات الطالب --}}
        <div class="card mb-4 shadow-sm border-0">
            <div class="card-header bg-primary text-white fw-bold">
                {{ __('students.student_info') }}
            </div>

            <div class="card-body row g-3">

                <div class="col-md-4">
                    <label>{{ __('students.last_name_ru') }}</label>
                    <input type="text" name="last_name_ru"
                           value="{{ old('last_name_ru', $student->last_name_ru) }}"
                           class="form-control" required>
                </div>

                <div class="col-md-4">
                    <label>{{ __('students.first_name_ru') }}</label>
                    <input type="text" name="first_name_ru"
                           value="{{ old('first_name_ru', $student->first_name_ru) }}"
                           class="form-control" required>
                </div>

                <div class="col-md-4">
                    <label>{{ __('students.patronymic_ru') }}</label>
                    <input type="text" name="patronymic_ru"
                           value="{{ old('patronymic_ru', $student->patronymic_ru) }}"
                           class="form-control">
                </div>

                <div class="col-md-6">
                    <label>{{ __('students.name_ar') }}</label>
                    <input type="text" name="name_ar"
                           value="{{ old('name_ar', $student->name_ar) }}"
                           class="form-control">
                </div>

                <div class="col-md-6">
                    <label>{{ __('students.class') }}</label>
                    <select name="class_id" class="form-select">
                        <option value="">{{ __('students.select_class') }}</option>
                        @foreach($classes as $class)
                            <option value="{{ $class->id }}"
                                @selected(old('class_id', $student->class_id) == $class->id)>
                                {{ $class->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-4">
                    <label>{{ __('students.birth_date') }}</label>
                    <input type="date" name="birth_date"
                           value="{{ old('birth_date', optional($student->birth_date)->format('Y-m-d')) }}"
                           class="form-control">
                </div>

                <div class="col-md-4">
                    <label>{{ __('students.gender') }}</label>
                    <select name="gender" class="form-select">
                        <option value="">{{ __('students.select_gender') }}</option>
                        <option value="male" @selected($student->gender == 'male')>{{ __('students.male') }}</option>
                        <option value="female" @selected($student->gender == 'female')>{{ __('students.female') }}</option>
                    </select>
                </div>

                <div class="col-md-4">
                    <label>{{ __('students.phone') }}</label>
                    <input type="text" name="phone"
                           value="{{ old('phone', $student->phone) }}"
                           class="form-control">
                </div>

                <div class="col-md-6">
                    <label>{{ __('students.email') }}</label>
                    <input type="email" name="email"
                           value="{{ old('email', $student->email) }}"
                           class="form-control">
                </div>

                <div class="col-md-6">
                    <label>{{ __('students.nationality') }}</label>
                    <input type="text" name="nationality"
                           value="{{ old('nationality', $student->nationality) }}"
                           class="form-control">
                </div>

            </div>
        </div>

        {{-- العنوان --}}
        <div class="card mb-4 shadow-sm border-0">
            <div class="card-header bg-dark text-white fw-bold">
                {{ __('students.address') }}
            </div>

            <div class="card-body">
                <textarea name="address" class="form-control" rows="3">{{ old('address', $student->address) }}</textarea>
            </div>
        </div>

        {{-- الصورة --}}
        <div class="card mb-4 shadow-sm border-0">
            <div class="card-header bg-info text-white fw-bold">
                {{ __('students.photo') }}
            </div>

            <div class="card-body row">

                <div class="col-md-6 text-center">

                    @if($student->photo)
                        <img src="{{ asset('storage/' . $student->photo) }}"
                             class="rounded mb-3"
                             style="width:150px;height:150px;object-fit:cover;">
                    @endif

                    <input type="file"
                           name="photo"
                           id="photoInput"
                           class="form-control"
                           accept="image/*">

                    <img id="preview"
                         class="mt-3 d-none rounded"
                         style="width:150px;height:150px;object-fit:cover;">
                </div>

                <div class="col-md-6">
                    <label>{{ __('students.documents') }}</label>
                    <input type="file" name="documents[]" class="form-control" multiple>
                </div>

            </div>
        </div>

        <button class="btn btn-success">
            💾 {{ __('students.save') }}
        </button>

    </form>

</div>

@endsection

@push('scripts')
<script>
document.getElementById('photoInput').addEventListener('change', function () {
    const file = this.files[0];
    const preview = document.getElementById('preview');

    if (file) {
        preview.src = URL.createObjectURL(file);
        preview.classList.remove('d-none');
    }
});
</script>
@endpush