<x-filament::page>

<div class="space-y-6">

    @foreach($classes as $subject)

        <div class="p-6 bg-white rounded-xl shadow">

            <h2 class="text-xl font-bold mb-4">
                {{ $subject->name }}
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                @foreach($subject->classes as $class)

                    <div class="p-4 border rounded">

                        <div class="font-semibold">
                            Класс: {{ $class->name }}
                        </div>

                        <div class="text-sm text-gray-500">
                            Студенты: {{ $class->students()->count() }}
                        </div>

                    </div>

                @endforeach

            </div>

        </div>

    @endforeach

</div>

</x-filament::page>