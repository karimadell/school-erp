<?php

namespace App\Services\Transport;

use App\Models\AuditLog;
use App\Models\Bus;
use App\Models\TransportRoute;
use App\Models\User;
use App\Support\TransportPermissions;
use Illuminate\Support\Facades\DB;

class TransportCatalogService
{
    public function createVehicle(array $data, User $actor): Bus
    {
        abort_unless($actor->can(TransportPermissions::MANAGE_VEHICLES), 403);

        return DB::transaction(function () use ($data, $actor) {
            $vehicle = Bus::create($this->vehicleData($data));
            $this->audit($actor, 'transport_vehicle_created', $vehicle, null, $vehicle->toArray());

            return $vehicle;
        });
    }

    public function updateVehicle(Bus $vehicle, array $data, User $actor): Bus
    {
        abort_unless($actor->can(TransportPermissions::MANAGE_VEHICLES), 403);

        return DB::transaction(function () use ($vehicle, $data, $actor) {
            $vehicle = Bus::query()->lockForUpdate()->findOrFail($vehicle->id);
            $old = $vehicle->toArray();
            $vehicle->update($this->vehicleData($data));
            $this->audit($actor, 'transport_vehicle_updated', $vehicle, $old, $vehicle->fresh()->toArray());

            return $vehicle->fresh();
        });
    }

    public function setVehicleActive(Bus $vehicle, bool $active, User $actor): Bus
    {
        return $this->updateVehicle($vehicle, ['is_active' => $active], $actor);
    }

    public function createRoute(array $data, User $actor): TransportRoute
    {
        abort_unless($actor->can(TransportPermissions::MANAGE_ROUTES), 403);

        return DB::transaction(function () use ($data, $actor) {
            $route = TransportRoute::create($this->routeData($data));
            $this->audit($actor, 'transport_route_created', $route, null, $route->toArray());

            return $route;
        });
    }

    public function updateRoute(TransportRoute $route, array $data, User $actor): TransportRoute
    {
        abort_unless($actor->can(TransportPermissions::MANAGE_ROUTES), 403);

        return DB::transaction(function () use ($route, $data, $actor) {
            $route = TransportRoute::query()->lockForUpdate()->findOrFail($route->id);
            $old = $route->toArray();
            $route->update($this->routeData($data));
            $this->audit($actor, 'transport_route_updated', $route, $old, $route->fresh()->toArray());

            return $route->fresh();
        });
    }

    public function setRouteActive(TransportRoute $route, bool $active, User $actor): TransportRoute
    {
        return $this->updateRoute($route, ['is_active' => $active], $actor);
    }

    private function vehicleData(array $data): array
    {
        return collect($data)->only([
            'vehicle_code', 'name', 'plate_number', 'student_capacity',
            'passenger_capacity', 'is_active',
        ])->all();
    }

    private function routeData(array $data): array
    {
        return collect($data)->only(['name', 'pricing_zone', 'description', 'is_active'])->all();
    }

    private function audit(User $actor, string $action, object $model, ?array $old, array $new): void
    {
        AuditLog::create([
            'user_id' => $actor->id,
            'action' => $action,
            'model' => $model::class,
            'model_id' => $model->id,
            'old_values' => $old,
            'new_values' => $new,
        ]);
    }
}
