@props(['alt' => 'Логотип школы'])
@php
    $asset = \App\Models\SchoolSetting::current()->documentLogoAsset();
@endphp
@if ($asset)
    <img {{ $attributes }} src="{{ $asset['data_uri'] }}" alt="{{ $alt }}">
@endif
