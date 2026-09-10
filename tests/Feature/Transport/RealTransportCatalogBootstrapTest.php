<?php

namespace Tests\Feature\Transport;

use App\Models\Bus;
use App\Models\TransportRoute;
use App\Services\Transport\RealTransportCatalogBootstrapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Normalizer;
use Tests\TestCase;

class RealTransportCatalogBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_is_five_routes_and_vehicles_without_writes(): void
    {
        $before = $this->counts();
        $result = app(RealTransportCatalogBootstrapService::class)->preview();

        $this->assertSame(5, $result['new_routes']);
        $this->assertSame(5, $result['new_vehicles']);
        $this->assertSame([13, 12, 13, 14, 12], collect($result['routes'])->pluck('expected_students')->all());
        $this->assertSame($before, $this->counts());
    }

    public function test_apply_creates_approved_catalog_and_is_idempotent(): void
    {
        $service = app(RealTransportCatalogBootstrapService::class);
        $first = $service->apply();
        $second = $service->apply();

        $this->assertSame(['mode' => 'APPLY', 'created_routes' => 5, 'created_vehicles' => 5], $first);
        $this->assertSame(['mode' => 'APPLY', 'created_routes' => 0, 'created_vehicles' => 0], $second);
        $this->assertSame(5, TransportRoute::count());
        $this->assertSame(5, Bus::count());
        $this->assertSame(0, DB::table('student_transport_assignments')->count());
        $this->assertSame(0, DB::table('vehicle_staff_assignments')->count());
        $this->assertSame(0, DB::table('invoices')->count());
        $this->assertSame(0, DB::table('invoice_items')->count());
        $this->assertSame(0, DB::table('invoice_payments')->count());

        $expected = [
            'Арабия' => ['code' => '1', 'zone' => 'Zone 2'],
            'Бествэй' => ['code' => '2', 'zone' => null],
            'Бритиш' => ['code' => '3', 'zone' => null],
            'Каусер' => ['code' => '4', 'zone' => 'Zone 1'],
            'Эль Ахья' => ['code' => '5', 'zone' => 'Zone 3'],
        ];
        foreach ($expected as $name => $item) {
            $route = TransportRoute::where('name', $name)->firstOrFail();
            $bus = Bus::where('vehicle_code', $item['code'])->firstOrFail();
            $this->assertSame($item['zone'], $route->pricing_zone);
            $this->assertSame($route->id, $bus->transport_route_id);
            $this->assertSame(14, $bus->student_capacity);
            $this->assertSame(15, $bus->passenger_capacity);
            $this->assertTrue($bus->is_active);
            $this->assertNull($bus->plate_number);
        }
    }

    private function counts(): array
    {
        return [
            'routes' => DB::table('transport_routes')->count(),
            'buses' => DB::table('buses')->count(),
            'students' => DB::table('students')->count(),
            'enrollments' => DB::table('enrollments')->count(),
            'assignments' => DB::table('student_transport_assignments')->count(),
            'staff_assignments' => DB::table('vehicle_staff_assignments')->count(),
            'invoices' => DB::table('invoices')->count(),
            'invoice_items' => DB::table('invoice_items')->count(),
            'invoice_payments' => DB::table('invoice_payments')->count(),
        ];
    }

    public function test_nfd_existing_route_is_reused_without_duplicate_catalog_entry(): void
    {
        app(RealTransportCatalogBootstrapService::class)->apply();
        TransportRoute::where('name', 'Бествэй')->sole()->forceFill([
            'name' => Normalizer::normalize('Бествэй', Normalizer::FORM_D),
        ])->saveQuietly();

        $preview = app(RealTransportCatalogBootstrapService::class)->preview();
        $apply = app(RealTransportCatalogBootstrapService::class)->apply();

        $this->assertSame(0, $preview['new_routes']);
        $this->assertSame(0, $apply['created_routes']);
        $this->assertSame(5, TransportRoute::count());
    }
}
