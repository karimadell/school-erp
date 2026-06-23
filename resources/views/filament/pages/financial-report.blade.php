<x-filament::page>

<div class="flex justify-end mb-6">
    <x-filament::button wire:click="downloadPdf">
        Скачать PDF
    </x-filament::button>
</div>

<div class="grid grid-cols-1 md:grid-cols-4 gap-6">

    <div class="bg-white p-6 rounded-xl shadow">
        <h3 class="text-lg font-bold">
            Общий доход
        </h3>

        <p class="text-3xl font-bold mt-3 text-green-600">
            {{ number_format($totalIncome, 2) }} EGP
        </p>
    </div>


    <div class="bg-white p-6 rounded-xl shadow">
        <h3 class="text-lg font-bold">
            Оплаченные счета
        </h3>

        <p class="text-3xl font-bold mt-3">
            {{ $paidInvoices }}
        </p>
    </div>


    <div class="bg-white p-6 rounded-xl shadow">
        <h3 class="text-lg font-bold">
            Неоплаченные счета
        </h3>

        <p class="text-3xl font-bold mt-3 text-red-600">
            {{ $unpaidInvoices }}
        </p>
    </div>


    <div class="bg-white p-6 rounded-xl shadow">
        <h3 class="text-lg font-bold">
            Баланс кассы
        </h3>

        <p class="text-3xl font-bold mt-3 text-blue-600">
            {{ number_format($cashBalance, 2) }} EGP
        </p>
    </div>

</div>

</x-filament::page>