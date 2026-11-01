<?php

namespace Tests\Feature;

use App\Mcp\Servers\EasyAppointmentsServer;
use App\Mcp\Tools\BookAppointment;
use App\Mcp\Tools\FindOpenSlots;
use App\Mcp\Tools\ListServices;
use App\Mcp\Tools\RescheduleAppointment;
use App\Mcp\Tools\WriteTool;
use App\Models\IdempotencyKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\ConnectsMcp;
use Tests\TestCase;

/** Double bookings, skipped previews and time zones: the checks that keep a write safe. */
class ConfirmSafetyTest extends TestCase
{
    use ConnectsMcp, RefreshDatabase;

    private array $booking = ['service_id' => 1, 'provider_id' => 10, 'start' => '2026-11-03 10:00', 'customer_id' => 1];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcp();
    }

    private function preview(array $booking): string
    {
        return $this->structured(EasyAppointmentsServer::tool(BookAppointment::class, $booking))['confirmation_token'];
    }

    private function confirm(array $booking, string $token, string $key)
    {
        return EasyAppointmentsServer::tool(BookAppointment::class, $booking + ['confirm' => true, 'confirmation_token' => $token, 'idempotency_key' => $key]);
    }

    public function test_two_people_confirming_the_same_slot_book_it_once(): void
    {
        // Both previews see the slot open, as two parallel confirmations would.
        $first = $this->preview($this->booking);
        $second = $this->preview(['customer_id' => 2] + $this->booking);

        $this->confirm($this->booking, $first, 'key-first-1')->assertHasNoErrors();
        // Easy!Appointments itself would accept this second booking; the fresh check under the lock refuses it.
        $this->confirm(['customer_id' => 2] + $this->booking, $second, 'key-second-1')->assertHasErrors(['10:00 is not open']);

        $this->assertCount(1, $this->ea->appointments);
        $this->assertSame(['POST /appointments'], $this->ea->writes());
    }

    public function test_a_change_waits_for_the_lock_on_its_provider_and_day(): void
    {
        config(['easyappointments.slot_lock_wait_seconds' => 0]);
        $token = $this->preview($this->booking);
        $held = Cache::lock('slot:'.$this->user->ea_connection_id.':10:2026-11-03', 60);
        $this->assertTrue($held->get());

        $this->confirm($this->booking, $token, 'key-locked-1')->assertHasErrors(['Another change to the same provider and day is in progress']);
        $this->assertSame([], $this->ea->writes());
        $this->assertSame(0, IdempotencyKey::count(), 'Nothing was booked, so the key is free again.');

        $held->release();
        $this->confirm($this->booking, $token, 'key-locked-1')->assertHasNoErrors();
        $this->assertCount(1, $this->ea->appointments);
    }

    public function test_a_key_is_claimed_before_the_change_so_a_parallel_retry_cannot_act_twice(): void
    {
        $token = $this->preview($this->booking);
        $this->ea->loseWriteAnswers = true;

        // The booking is saved, but the answer is lost: the key stays claimed and unfinished.
        $this->confirm($this->booking, $token, 'key-lost-001')->assertHasErrors(['not clear whether the change was made']);
        $this->assertCount(1, $this->ea->appointments);
        $this->assertNull(IdempotencyKey::sole()->completed_at);

        // A retry with the same key, while the first call might still be running, does not book again.
        $this->ea->loseWriteAnswers = false;
        $this->confirm($this->booking, $token, 'key-lost-001')->assertHasErrors(['still being processed']);

        $this->travel(3)->minutes();
        $this->confirm($this->booking, $token, 'key-lost-001')->assertHasErrors(['did not finish', 'Check with list_appointments']);
        $this->assertCount(1, $this->ea->appointments);
    }

    public function test_confirm_without_a_preview_of_these_exact_arguments_is_refused(): void
    {
        $token = $this->preview($this->booking);

        $this->confirm(['start' => '2026-11-03 13:00'] + $this->booking, $token, 'key-other-01')
            ->assertHasErrors(['does not belong to a preview of exactly these arguments']);
        $this->confirm($this->booking, '9999999999.forged', 'key-forge-01')
            ->assertHasErrors(['does not belong to a preview of exactly these arguments']);
        // A token for one tool cannot confirm another.
        EasyAppointmentsServer::tool(RescheduleAppointment::class, ['appointment_id' => 1, 'new_start' => '2026-11-03 13:00', 'confirm' => true, 'confirmation_token' => $token, 'idempotency_key' => 'key-tool-001'])
            ->assertHasErrors(['does not belong to a preview']);

        $this->assertSame([], $this->ea->writes());
    }

    public function test_an_expired_preview_must_be_shown_again(): void
    {
        $token = $this->preview($this->booking);
        $this->travel(11)->minutes();

        $this->confirm($this->booking, $token, 'key-late-001')->assertHasErrors(['The preview has expired']);
        $this->assertSame([], $this->ea->writes());
    }

    public function test_times_are_in_the_provider_time_zone_not_the_business_default(): void
    {
        // Easy!Appointments answers availability and stores appointments in each provider's own zone.
        $this->ea->providers[1]['timezone'] = 'Europe/London';

        $slots = $this->structured(EasyAppointmentsServer::tool(FindOpenSlots::class, ['service_id' => 1, 'days' => 1, 'date_from' => '2026-11-03']));
        $this->assertStringContainsString('Differs by provider', $slots['timezone']);
        $zones = collect($slots['days'][0]['slots'])->pluck('timezone', 'provider')->all();
        $this->assertSame(['Anna Kim' => 'America/New_York', 'Marcus Reed' => 'Europe/London'], $zones);

        $preview = $this->structured(EasyAppointmentsServer::tool(BookAppointment::class, ['provider_id' => 11] + $this->booking));
        $this->assertSame('Europe/London', $preview['timezone']);

        $services = $this->structured(EasyAppointmentsServer::tool(ListServices::class));
        $this->assertSame('Europe/London', collect($services['services'][0]['providers'])->firstWhere('id', 11)['timezone']);
    }

    public function test_requests_to_easyappointments_share_one_budget_across_everyone(): void
    {
        config(['easyappointments.request_budget' => 2]);

        EasyAppointmentsServer::tool(ListServices::class)->assertHasErrors(['Too many requests to Easy!Appointments from everyone using this server']);
    }

    public function test_a_refused_booking_removes_the_customer_it_created(): void
    {
        $booking = ['new_customer' => ['first_name' => 'Taylor', 'last_name' => 'Test', 'email' => 'taylor@example.test']] + $this->booking;
        unset($booking['customer_id']);
        $token = $this->preview($booking);
        $this->ea->refuseAppointmentsWith = 422;

        $this->confirm($booking, $token, 'key-refused-1')->assertHasErrors(['refused POST /appointments']);

        $this->assertSame(['POST /customers', 'POST /appointments', 'DELETE /customers/3'], $this->ea->writes());
        $this->assertSame([1, 2], array_keys($this->ea->customers));
        $this->assertSame(0, IdempotencyKey::count(), 'Nothing changed, so the key is free again.');
    }

    public function test_after_a_server_error_the_new_customer_and_the_key_stay(): void
    {
        $booking = ['new_customer' => ['first_name' => 'Taylor', 'last_name' => 'Test', 'email' => 'taylor@example.test']] + $this->booking;
        unset($booking['customer_id']);
        $token = $this->preview($booking);
        // A proxy in front of Easy!Appointments answers 502 although the booking was saved.
        $this->ea->saveAppointmentsButAnswer = 502;

        $this->confirm($booking, $token, 'key-proxy-01')->assertHasErrors(['not clear whether the change was made', 'list_appointments']);

        $this->assertSame(['POST /customers', 'POST /appointments'], $this->ea->writes(), 'The customer is not deleted.');
        $this->assertCount(1, $this->ea->appointments);
        $this->assertArrayHasKey(3, $this->ea->customers);
        $this->assertFalse(IdempotencyKey::sole()->isCompleted(), 'The key stays claimed, so a retry with it cannot book again.');

        $this->confirm($booking, $token, 'key-proxy-01')->assertHasErrors(['still being processed']);
        $this->assertCount(1, $this->ea->appointments);
    }

    public function test_a_booking_for_a_new_customer_does_not_start_without_budget_for_all_its_requests(): void
    {
        $booking = ['new_customer' => ['first_name' => 'Taylor', 'last_name' => 'Test', 'email' => 'taylor@example.test']] + $this->booking;
        unset($booking['customer_id']);
        $token = $this->preview($booking);

        // Right after the fresh slot check, only two requests are left in the shared budget.
        $budget = config('easyappointments.request_budget');
        $key = 'ea-budget:'.$this->user->ea_connection_id;
        $this->ea->onRequest = function (string $request) use ($budget, $key) {
            if (str_starts_with($request, 'GET /availabilities')) {
                RateLimiter::clear($key);
                RateLimiter::increment($key, 120, $budget - 2);
            }
        };

        $this->confirm($booking, $token, 'key-budget-1')->assertHasErrors(['Too many requests to Easy!Appointments']);

        $this->assertSame([], $this->ea->writes(), 'No customer is created when the booking could not follow.');
        $this->assertSame(0, IdempotencyKey::count());
    }

    public function test_the_slot_lock_outlasts_the_slowest_change(): void
    {
        config(['easyappointments.timeout' => 10]);
        $this->assertSame(90, WriteTool::lockSeconds());

        config(['easyappointments.timeout' => 30]);
        $this->assertSame(210, WriteTool::lockSeconds());
    }
}
