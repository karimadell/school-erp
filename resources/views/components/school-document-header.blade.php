@props([
    'title' => null,
    'academicYear' => null,
    'footer' => false,
])

@include('pdf.partials.document-header', [
    'documentTitle' => $title,
])

@if ($footer)
    @include('pdf.partials.document-footer', ['academicYear' => $academicYear])
@endif
