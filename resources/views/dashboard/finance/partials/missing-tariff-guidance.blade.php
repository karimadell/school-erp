@if(session('missing_tariff_message'))
    <div class="alert alert-warning" role="alert">
        {{ session('missing_tariff_message') }}
        @if(session('missing_tariff_link'))
            <a href="{{ session('missing_tariff_link') }}" class="alert-link ms-1" target="_blank" rel="noopener">Добавить цену</a>
        @endif
    </div>
@endif
