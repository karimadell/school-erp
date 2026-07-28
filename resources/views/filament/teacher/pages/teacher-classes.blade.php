<x-filament::page>

<div class="space-y-6">

    @if(count($classes) === 0)

        <div class="p-6 bg-white rounded-xl shadow">
            {{ __('teacher_classes.no_classes') }}
        </div>

    @endif

    @foreach($classes as $subject)

        <div class="p-6 bg-white rounded-xl shadow">

            <h2 class="text-xl font-bold mb-4">
                {{ $subject->name }}
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                @foreach($subject->classes as $class)

                    <div class="p-4 border rounded">

                        <div class="font-semibold">
                            {{ __('teacher_classes.class_label') }}: {{ $class->name }}
                        </div>

                        <div class="text-sm text-gray-500">
                            {{ __('teacher_classes.students_count') }}: {{ $class->students()->count() }}
                        </div>

                    </div>

                @endforeach

            </div>

        </div>

    @endforeach

</div>

</x-filament::page>