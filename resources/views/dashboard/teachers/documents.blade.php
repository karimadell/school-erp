@extends('layouts.dashboard')

@section('content')

<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-0 fw-bold">📎 {{ __('teachers.documents') }}</h3>
            <small class="text-muted">
                {{ $teacher->full_name }} — {{ __('teachers.documents_hint') }}
            </small>
        </div>

        <a href="{{ route('dashboard.teachers.index') }}" class="btn btn-outline-secondary">
            ← {{ __('teachers.back') }}
        </a>
    </div>

    @if(session('success'))
        <div class="alert alert-success shadow-sm border-0">
            ✅ {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger shadow-sm border-0">
            <strong>{{ __('teachers.validation_error') }}</strong>
            <ul class="mb-0 mt-2">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Teacher Summary --}}
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body p-4">
            <div class="row g-3 align-items-center">
                <div class="col-md-4">
                    <div class="text-muted small">{{ __('teachers.full_name') }}</div>
                    <div class="fw-bold fs-5">{{ $teacher->full_name }}</div>
                </div>

                <div class="col-md-3">
                    <div class="text-muted small">{{ __('teachers.phone') }}</div>
                    <div>{{ $teacher->phone ?? '—' }}</div>
                </div>

                <div class="col-md-3">
                    <div class="text-muted small">{{ __('teachers.specialization') }}</div>
                    <div>{{ $teacher->specialization ?? '—' }}</div>
                </div>

                <div class="col-md-2 text-md-end">
                    @if($teacher->is_active)
                        <span class="badge bg-success px-3 py-2">{{ __('teachers.active') }}</span>
                    @else
                        <span class="badge bg-secondary px-3 py-2">{{ __('teachers.inactive') }}</span>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Upload --}}
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-primary text-white fw-bold">
            {{ __('teachers.upload_document') }}
        </div>

        <div class="card-body p-4">
            <form method="POST"
                  action="{{ route('dashboard.teachers.documents.store', $teacher->id) }}"
                  enctype="multipart/form-data">
                @csrf

                <div class="row g-3 align-items-end">

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">
                            {{ __('teachers.document_title') }}
                            <span class="text-danger">*</span>
                        </label>

                        <input type="text"
                               name="title"
                               value="{{ old('title') }}"
                               class="form-control @error('title') is-invalid @enderror"
                               placeholder="{{ __('teachers.document_title_placeholder') }}"
                               required>

                        @error('title')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-3">
                        <label class="form-label fw-semibold">
                            {{ __('teachers.document_date') }}
                        </label>

                        <input type="date"
                               name="document_date"
                               value="{{ old('document_date') }}"
                               class="form-control @error('document_date') is-invalid @enderror">

                        @error('document_date')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-3">
                        <label class="form-label fw-semibold">
                            {{ __('teachers.file') }}
                            <span class="text-danger">*</span>
                        </label>

                        <input type="file"
                               name="file"
                               class="form-control @error('file') is-invalid @enderror"
                               accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                               required>

                        @error('file')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror

                        <div class="text-muted small mt-1">
                            PDF, JPG, PNG, DOC, DOCX — max 10MB
                        </div>
                    </div>

                    <div class="col-md-2">
                        <button class="btn btn-success w-100"
                                onclick="this.innerHTML='⏳ {{ __('teachers.uploading') }}'">
                            ⬆ {{ __('teachers.upload') }}
                        </button>
                    </div>

                </div>
            </form>
        </div>
    </div>

    {{-- Documents List --}}
    <div class="card shadow-sm border-0">
        <div class="card-header bg-light fw-bold d-flex justify-content-between align-items-center">
            <span>{{ __('teachers.documents_list') }}</span>
            <span class="badge bg-secondary">
                {{ $teacher->documents->count() }} {{ __('teachers.total') }}
            </span>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">

                <table class="table table-hover table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th width="70">#</th>
                            <th>{{ __('teachers.document_title') }}</th>
                            <th width="160">{{ __('teachers.document_date') }}</th>
                            <th width="140">{{ __('teachers.file_type') }}</th>
                            <th>{{ __('teachers.file_name') }}</th>
                            <th width="260">{{ __('teachers.actions') }}</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse($teacher->documents as $document)
                            <tr>
                                <td>{{ $document->id }}</td>

                                <td>
                                    <strong>
                                        {{ $document->file_icon ?? '📎' }}
                                        {{ $document->title }}
                                    </strong>
                                </td>

                                <td>
                                    {{ $document->formatted_date ?? '—' }}
                                </td>

                                <td>
                                    <span class="badge bg-dark">
                                        {{ strtoupper($document->file_type ?? '-') }}
                                    </span>
                                </td>

                                <td class="text-muted small">
                                    {{ $document->file_name ?? basename($document->file_path) }}
                                </td>

                                <td>
                                    <a href="{{ $document->file_url ?? asset('storage/' . $document->file_path) }}"
                                       target="_blank"
                                       class="btn btn-sm btn-primary">
                                        👁 {{ __('teachers.view') }}
                                    </a>

                                    <a href="{{ $document->file_url ?? asset('storage/' . $document->file_path) }}"
                                       download
                                       class="btn btn-sm btn-outline-dark">
                                        ⬇ {{ __('teachers.download') }}
                                    </a>

                                    <form action="{{ route('dashboard.teachers.documents.delete', $document->id) }}"
                                          method="POST"
                                          class="d-inline"
                                          onsubmit="return confirm('{{ __('teachers.confirm_delete_document') }}')">
                                        @csrf
                                        @method('DELETE')

                                        <button class="btn btn-sm btn-danger">
                                            🗑 {{ __('teachers.delete') }}
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-5">
                                    📭 {{ __('teachers.no_documents') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>

            </div>
        </div>
    </div>

</div>

@endsection