<?php

namespace Tests\Feature\ServiceRates;

use App\Models\EventType;
use App\Models\Organization;
use App\Models\Package;
use App\Models\Service;
use App\Models\ServiceRate;
use App\Support\Pricing\ActiveServiceRateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActiveServiceRateResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_active_combination_resolves_without_fallback_or_cross_tenant_access(): void
    {
        [$organization, $eventType, $service, $package, $rate] = $this->combination();
        [$otherOrganization] = $this->combination();
        $resolver = app(ActiveServiceRateResolver::class);

        $this->assertTrue($rate->is($resolver->resolve($organization, $eventType->id, $service->id, $package->id, 180)));
        $this->assertNull($resolver->resolve($organization, $eventType->id, $service->id, $package->id, 120));
        $this->assertNull($resolver->resolve($otherOrganization, $eventType->id, $service->id, $package->id, 180));
    }

    public function test_inactive_rate_or_dependency_is_unavailable(): void
    {
        foreach (['rate', 'eventType', 'package', 'service'] as $inactive) {
            [$organization, $eventType, $service, $package, $rate] = $this->combination();
            ${$inactive}->update(['is_active' => false]);

            $this->assertNull(
                app(ActiveServiceRateResolver::class)->resolve($organization, $eventType->id, $service->id, $package->id, 180),
                "Expected inactive {$inactive} to make the rate unavailable.",
            );
        }
    }

    public function test_same_package_resolves_different_rates_for_different_services(): void
    {
        [$organization, $eventType, $service, $package, $firstRate] = $this->combination();
        $firstRate->update(['unit_rate' => '5000.00']);
        $otherService = Service::factory()->for($organization)->create();
        $package->services()->attach($otherService->id, ['organization_id' => $organization->id]);
        $otherRate = ServiceRate::factory()->forCombination($eventType, $otherService, $package)->create([
            'duration_minutes' => 180,
            'unit_rate' => '9000.00',
        ]);
        $resolver = app(ActiveServiceRateResolver::class);

        $this->assertTrue($firstRate->is($resolver->resolve(
            $organization, $eventType->id, $service->id, $package->id, 180,
        )));
        $this->assertTrue($otherRate->is($resolver->resolve(
            $organization, $eventType->id, $otherService->id, $package->id, 180,
        )));
    }

    /** @return array{Organization, EventType, Service, Package, ServiceRate} */
    private function combination(): array
    {
        $organization = Organization::factory()->create();
        $eventType = EventType::factory()->for($organization)->create();
        $service = Service::factory()->for($organization)->create();
        $package = Package::factory()->forService($service)->create();
        $rate = ServiceRate::factory()->forCombination($eventType, $service, $package)->create(['duration_minutes' => 180]);

        return [$organization, $eventType, $service, $package, $rate];
    }
}
