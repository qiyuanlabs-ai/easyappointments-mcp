<?php

namespace Tests\Feature;

use App\Mcp\Servers\EasyAppointmentsServer;
use App\Mcp\Tools\FindCustomer;
use App\Mcp\Tools\FindOpenSlots;
use App\Mcp\Tools\ListAppointments;
use App\Mcp\Tools\ListServices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConnectsMcp;
use Tests\TestCase;

class ReadToolsTest extends TestCase
{
    use ConnectsMcp, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcp();
    }

    public function test_list_services_shows_who_offers_what_and_the_time_zone(): void
    {
        $data = $this->structured(EasyAppointmentsServer::tool(ListServices::class));

        $this->assertSame('America/New_York', $data['timezone']);
        $this->assertCount(2, $data['services']);
        $this->assertSame(['Anna Kim', 'Marcus Reed'], array_column($data['services'][0]['providers'], 'name'));
    }

    public function test_the_catalog_is_cached_so_repeat_calls_spare_the_rate_limit(): void
    {
        EasyAppointmentsServer::tool(ListServices::class);
        $first = count($this->ea->requests);
        EasyAppointmentsServer::tool(ListServices::class);

        $this->assertSame($first, count($this->ea->requests));
    }

    public function test_find_open_slots_merges_providers_and_filters_part_of_day(): void
    {
        $data = $this->structured(EasyAppointmentsServer::tool(FindOpenSlots::class, ['service_id' => 1, 'date_from' => '2026-11-03', 'days' => 2, 'part_of_day' => 'afternoon']));

        $this->assertSame(['2026-11-03', '2026-11-04'], array_column($data['days'], 'date'));
        $this->assertSame(['13:00', '13:00', '14:00', '14:00', '15:00', '15:00'], array_column($data['days'][0]['slots'], 'time'));
        $this->assertSame(12, $data['total_open_times']);
    }

    public function test_find_open_slots_limits_lookups_and_says_so(): void
    {
        $data = $this->structured(EasyAppointmentsServer::tool(FindOpenSlots::class, ['service_id' => 1, 'days' => 14]));

        // Two providers and a budget of 21 lookups gives 10 days.
        $this->assertCount(10, $data['days']);
        $this->assertStringContainsString('Searched 10 days instead of 14', $data['note']);
        $this->assertSame(20, collect($this->ea->requests)->filter(fn ($r) => $r === 'GET /availabilities')->count());
    }

    public function test_find_open_slots_never_searches_the_past(): void
    {
        $data = $this->structured(EasyAppointmentsServer::tool(FindOpenSlots::class, ['service_id' => 2, 'provider_id' => 10, 'date_from' => '2026-10-01', 'days' => 1]));

        $this->assertSame('2026-11-02', $data['from']);
    }

    public function test_find_customer_needs_two_characters(): void
    {
        EasyAppointmentsServer::tool(FindCustomer::class, ['query' => 'o'])->assertHasErrors();

        $data = $this->structured(EasyAppointmentsServer::tool(FindCustomer::class, ['query' => 'bennett']));
        $this->assertSame('Olivia Bennett', $data['customers'][0]['name']);
    }

    public function test_list_appointments_needs_a_filter_and_returns_names(): void
    {
        $this->ea->addAppointment(['start' => '2026-11-03 10:00', 'serviceId' => 1, 'providerId' => 10, 'customerId' => 1]);

        EasyAppointmentsServer::tool(ListAppointments::class, [])
            ->assertHasErrors(['Give at least one of customer_id, provider_id or date_from.']);

        $data = $this->structured(EasyAppointmentsServer::tool(ListAppointments::class, ['customer_id' => 1]));
        $this->assertSame(1, $data['count']);
        $this->assertSame(['Initial assessment', 'Anna Kim', 'Olivia Bennett'], [$data['appointments'][0]['service'], $data['appointments'][0]['provider'], $data['appointments'][0]['customer']]);
    }

    public function test_easyappointments_errors_become_plain_messages(): void
    {
        $this->ea->failWith = 429;
        EasyAppointmentsServer::tool(ListServices::class)->assertHasErrors(['rate limiting requests']);

        $this->ea->failWith = 401;
        EasyAppointmentsServer::tool(ListServices::class)->assertHasErrors(['rejected the API token']);
    }
}
