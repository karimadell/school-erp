<?php

namespace App\Services\Transport;

use App\Models\Bus;
use App\Models\TransportRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Preview by default; APPLY is an explicit, local catalog bootstrap. */
class RealTransportCatalogBootstrapService
{
    private const CATALOG = [
        ['vehicle_number' => '1', 'route' => 'Арабия', 'pricing_zone' => 'Zone 2', 'expected_students' => 13],
        ['vehicle_number' => '2', 'route' => 'Бествэй', 'pricing_zone' => null, 'expected_students' => 12],
        ['vehicle_number' => '3', 'route' => 'Бритиш', 'pricing_zone' => null, 'expected_students' => 13],
        ['vehicle_number' => '4', 'route' => 'Каусер', 'pricing_zone' => 'Zone 1', 'expected_students' => 14],
        ['vehicle_number' => '5', 'route' => 'Эль Ахья', 'pricing_zone' => 'Zone 3', 'expected_students' => 12],
    ];

    public function preview(): array
    {
        $plan = $this->plan();

        return [
            'mode' => 'PREVIEW',
            'expected_routes' => 5,
            'expected_vehicles' => 5,
            'new_routes' => $plan->where('route_exists', false)->count(),
            'new_vehicles' => $plan->where('vehicle_exists', false)->count(),
            'routes' => $plan->values()->all(),
        ];
    }

    public function apply(): array
    {
        $plan = $this->plan();
        $this->assertGuard($plan);

        return DB::transaction(function () use ($plan): array {
            $routes = 0;
            $vehicles = 0;

            foreach ($plan as $item) {
                $route = $this->resolveRoute($item);
                if (! $item['route_exists']) {
                    $routes++;
                }

                $vehicle = $this->resolveVehicle($item, $route);
                if (! $item['vehicle_exists']) {
                    $vehicles++;
                }
            }

            return [
                'mode' => 'APPLY',
                'created_routes' => $routes,
                'created_vehicles' => $vehicles,
            ];
        });
    }

    private function plan(): Collection
    {
        return collect(self::CATALOG)->map(function (array $item): array {
            $routes = TransportRoute::query()->where('name', $item['route'])->get();
            $vehicles = Bus::query()->where('vehicle_code', $item['vehicle_number'])->get();

            if ($routes->count() > 1) {
                throw ValidationException::withMessages(['route' => "Найдено несколько маршрутов «{$item['route']}». Bootstrap остановлен."]);
            }
            if ($vehicles->count() > 1) {
                throw ValidationException::withMessages(['vehicle' => "Найдено несколько транспортов с кодом {$item['vehicle_number']}. Bootstrap остановлен."]);
            }

            if ($routes->isNotEmpty() && ($routes->first()->pricing_zone !== $item['pricing_zone'] || ! $routes->first()->is_active)) {
                throw ValidationException::withMessages(['route' => "Существующий маршрут «{$item['route']}» не соответствует утвержденной зоне/активности."]);
            }
            if ($vehicles->isNotEmpty()) {
                $vehicle = $vehicles->first();
                if ((int) $vehicle->student_capacity !== 14 || (int) $vehicle->passenger_capacity !== 15 || ! $vehicle->is_active) {
                    throw ValidationException::withMessages(['vehicle' => "Существующий транспорт {$item['vehicle_number']} не соответствует утвержденной вместимости/активности."]);
                }
                if ($routes->isEmpty()) {
                    throw ValidationException::withMessages(['vehicle' => "Невозможно безопасно определить маршрут существующего транспорта {$item['vehicle_number']}. Bootstrap остановлен."]);
                }
                if ($routes->isNotEmpty() && (int) $vehicle->transport_route_id !== (int) $routes->first()->id) {
                    throw ValidationException::withMessages(['vehicle' => "Транспорт {$item['vehicle_number']} уже связан с другим маршрутом."]);
                }
            }

            return $item + [
                'route_exists' => $routes->isNotEmpty(),
                'vehicle_exists' => $vehicles->isNotEmpty(),
                'route_id' => $routes->first()?->id,
                'vehicle_id' => $vehicles->first()?->id,
                'student_capacity' => 14,
                'passenger_capacity' => 15,
            ];
        });
    }

    private function resolveRoute(array $item): TransportRoute
    {
        $route = TransportRoute::query()->where('name', $item['route'])->lockForUpdate()->first();

        return $route ?: TransportRoute::create([
            'name' => $item['route'],
            'pricing_zone' => $item['pricing_zone'],
            'is_active' => true,
        ]);
    }

    private function resolveVehicle(array $item, TransportRoute $route): Bus
    {
        $vehicle = Bus::query()->where('vehicle_code', $item['vehicle_number'])->lockForUpdate()->first();

        return $vehicle ?: Bus::create([
            'vehicle_code' => $item['vehicle_number'],
            'name' => "Микроавтобус {$item['vehicle_number']}",
            'plate_number' => null,
            'capacity' => 15,
            'student_capacity' => 14,
            'passenger_capacity' => 15,
            'transport_route_id' => $route->id,
            'is_active' => true,
        ]);
    }

    private function assertGuard(Collection $plan): void
    {
        if ($plan->count() !== 5 || $plan->where('pricing_zone', null)->count() !== 2) {
            throw ValidationException::withMessages(['bootstrap' => 'Утвержденный каталог должен содержать ровно 5 маршрутов и 2 неразрешенные зоны.']);
        }
    }
}
