@props([
    'title' => null,
    'academicYear' => null,
    'logoPath' => null,
    'footer' => false,
])

@include('pdf.partials.document-header', [
    'documentTitle' => $title,
    'logoPath' => $logoPath,
])

@if ($footer)
    @include('pdf.partials.document-footer', ['academicYear' => $academicYear])
@endif
