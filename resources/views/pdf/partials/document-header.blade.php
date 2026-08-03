@php
    $schoolSettings = $schoolSettings ?? \App\Models\SchoolSetting::current();
    $brandingLogoPath = $schoolSettings->documentLogoPath();
@endphp

<style>
    .school-document-header {
        width: 100%;
        border-bottom: 2px solid {{ $schoolSettings->header_color }};
        margin-bottom: 18px;
        padding-bottom: 12px;
    }
    .school-document-header td { border: 0; vertical-align: middle; }
    .school-document-logo-cell { width: 105px; text-align: center; }
    .school-document-logo {
        display: block;
        max-width: 92px;
        max-height: 82px;
        width: auto;
        height: auto;
        margin: 0 auto;
    }
    .school-document-identity { text-align: center; padding-right: 105px; }
    .school-document-name { font-size: 19px; font-weight: 700; }
    .school-document-subtitle { margin-top: 3px; font-size: 12px; color: #4b5563; }
    .school-document-title { margin-top: 8px; font-size: 16px; font-weight: 700; }
</style>

<table class="school-document-header" role="presentation">
    <tr>
        @if (is_string($brandingLogoPath) && is_file($brandingLogoPath))
            <td class="school-document-logo-cell">
                <img class="school-document-logo" src="data:image/png;base64,{{ base64_encode(file_get_contents($brandingLogoPath)) }}" alt="Логотип школы">
            </td>
        @else
            <td class="school-document-logo-cell"></td>
        @endif
        <td class="school-document-identity">
            <div class="school-document-name">{{ $schoolSettings->school_name }}</div>
            <div class="school-document-subtitle">Русская школа в Египте</div>
            <div class="school-document-subtitle">
                {{ $schoolSettings->phone_1 }}
                @if ($schoolSettings->phone_2) / {{ $schoolSettings->phone_2 }} @endif
                @if ($schoolSettings->email) · {{ $schoolSettings->email }} @endif
            </div>
            @if (filled($documentTitle ?? null))
                <div class="school-document-title">{{ $documentTitle }}</div>
            @endif
            @if ($schoolSettings->print_date_enabled)
                <div class="school-document-subtitle">{{ now()->format('d.m.Y H:i') }}</div>
            @endif
        </td>
    </tr>
</table>
