<?php

namespace Tests\Feature;

use App\Mcp\Servers\EasyAppointmentsServer;
use App\Mcp\Tools\BookAppointment;
use App\Models\IdempotencyKey;
use App\Models\McpCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConnectsMcp;
use Tests\TestCase;

class BookAppointmentTest extends TestCase
{
    use ConnectsMcp, RefreshDatabase;

    private array $booking = [
        'service_id' => 1,
        'provider_id' => 10,
        'start' => '2026-11-03 10:00',
        'customer_id' => 1,
        'notes' => 'Left knee',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcp();
    }

    public function test_preview_changes_nothing(): void
    {
        $preview = $this->structured(EasyAppointmentsServer::tool(BookAppointment::class, $this->booking));

        $this->assertSame('preview', $preview['status']);
        $this->assertSame('Anna Kim', $preview['provider']);
        $this->assertSame('Olivia Bennett', $preview['customer']['name']);
        $this->assertSame(['2026-11-03 10:00', '2026-11-03 11:00', 'Tuesday'], [$preview['start'], $preview['end'], $preview['weekday']]);
        $this->assertStringContainsString('confirm: true', $preview['next_step']);

        $this->assertSame([], $this->ea->writes());
    }

    public function test_confirm_needs_a_confirmation_token_and_an_idempotency_key(): void
    {
        EasyAppointmentsServer::tool(BookAppointment::class, $this->booking + ['confirm' => true])
            ->assertHasErrors(['confirmation_token is required when confirm is true', 'idempotency_key is required when confirm is true']);

        $this->assertSame([], $this->ea->writes());
    }

    public function test_confirm_books_and_a_retry_with_the_same_key_does_not_book_twice(): void
    {
        $preview = $this->structured(EasyAppointmentsServer::tool(BookAppointment::class, $this->booking));
        $confirm = $this->booking + ['confirm' => true, 'confirmation_token' => $preview['confirmation_token'], 'idempotency_key' => 'key-0000001'];

        $booked = $this->structured(EasyAppointmentsServer::tool(BookAppointment::class, $confirm));
        $this->assertSame('booked', $booked['status']);
        $this->assertSame(100, $booked['appointment']['id']);
        $this->assertSame('Olivia Bennett', $booked['appointment']['customer']);

        $replayed = $this->structured(EasyAppointmentsServer::tool(BookAppointment::class, $confirm));
        $this->assertTrue($replayed['replayed']);
        $this->assertSame(100, $replayed['appointment']['id']);

        $this->assertSame(['POST /appointments'], $this->ea->writes());
        $this->assertCount(1, $this->ea->appointments);
    }

    public function test_reusing_a_key_for_different_arguments_is_refused(): void
    {
        $this->confirmWrite(BookAppointment::class, $this->booking, 'key-0000002')->assertHasNoErrors();

        $this->confirmWrite(BookAppointment::class, ['start' => '2026-11-03 13:00'] + $this->booking, 'key-0000002')
            ->assertHasErrors(['already used for a different request']);

        $this->assertCount(1, $this->ea->appointments);
    }

    public function test_a_taken_time_is_refused_with_the_nearest_open_times(): void
    {
        $this->ea->addAppointment(['start' => '2026-11-03 10:00', 'serviceId' => 1, 'providerId' => 10, 'customerId' => 2]);

        EasyAppointmentsServer::tool(BookAppointment::class, $this->booking)
            ->assertHasErrors(['10:00 is not open for Initial assessment with Anna Kim on 2026-11-03. Nearest open times that day: 09:00, 11:00, 13:00.']);
    }

    public function test_the_slot_is_checked_again_on_confirm_even_if_the_preview_was_cached(): void
    {
        $preview = $this->structured(EasyAppointmentsServer::tool(BookAppointment::class, $this->booking));

        // Someone else books the slot between preview and confirmation.
        $this->ea->addAppointment(['start' => '2026-11-03 10:00', 'serviceId' => 1, 'providerId' => 10, 'customerId' => 2]);

        EasyAppointmentsServer::tool(BookAppointment::class, $this->booking + ['confirm' => true, 'confirmation_token' => $preview['confirmation_token'], 'idempotency_key' => 'key-0000003'])
            ->assertHasErrors(['is not open']);
        $this->assertSame(0, IdempotencyKey::count(), 'A refused change frees its key.');
    }

    public function test_times_in_the_past_and_badly_formatted_times_are_refused(): void
    {
        EasyAppointmentsServer::tool(BookAppointment::class, ['start' => '2026-11-01 10:00'] + $this->booking)
            ->assertHasErrors(['start (2026-11-01 10:00 America/New_York) is in the past.']);

        EasyAppointmentsServer::tool(BookAppointment::class, ['start' => '2026-11-03 25:00'] + $this->booking)
            ->assertHasErrors(['start must look like 2026-11-04 14:30']);
    }

    public function test_a_provider_who_does_not_offer_the_service_is_refused(): void
    {
        EasyAppointmentsServer::tool(BookAppointment::class, ['service_id' => 2, 'provider_id' => 11] + $this->booking)
            ->assertHasErrors(['Marcus Reed does not offer Sports massage']);
    }

    public function test_a_new_customer_is_created_and_an_existing_email_is_reused(): void
    {
        $booking = ['new_customer' => ['first_name' => 'Taylor', 'last_name' => 'Test', 'email' => 'taylor@example.test']] + $this->booking;
        unset($booking['customer_id']);

        $booked = $this->structured($this->confirmWrite(BookAppointment::class, $booking, 'key-0000004'));
        $this->assertSame('Taylor Test', $booked['appointment']['customer']);

        $this->assertSame(['POST /customers', 'POST /appointments'], $this->ea->writes());

        $booking['new_customer']['email'] = 'Olivia.Bennett@example.test';
        $preview = $this->structured(EasyAppointmentsServer::tool(BookAppointment::class, ['start' => '2026-11-03 13:00'] + $booking));
        $this->assertSame(1, $preview['customer']['id']);
        $this->assertStringContainsString('already exists', $preview['customer_note']);
    }

    public function test_an_email_with_characters_easyappointments_cannot_take_is_refused_up_front(): void
    {
        $booking = ['new_customer' => ['first_name' => 'José', 'last_name' => 'Test', 'email' => 'josé@example.com']] + $this->booking;
        unset($booking['customer_id']);

        EasyAppointmentsServer::tool(BookAppointment::class, $booking)->assertHasErrors(['valid email']);
        $this->assertSame([], $this->ea->writes());
    }

    public function test_required_customer_fields_are_asked_for_at_preview(): void
    {
        $this->ea->settings['require_phone_number'] = '1';
        $booking = ['new_customer' => ['first_name' => 'Taylor', 'last_name' => 'Test', 'email' => 'taylor@example.test']] + $this->booking;
        unset($booking['customer_id']);

        EasyAppointmentsServer::tool(BookAppointment::class, $booking)
            ->assertHasErrors(['This business requires new_customer.phone for new customers.']);
    }

    public function test_customer_and_provider_ids_must_not_both_be_missing_or_both_given(): void
    {
        $booking = $this->booking;
        unset($booking['customer_id']);

        EasyAppointmentsServer::tool(BookAppointment::class, $booking)
            ->assertHasErrors(['Give customer_id for an existing customer, or new_customer for someone new.']);
    }

    public function test_every_call_is_logged_with_personal_details_masked(): void
    {
        $booking = ['new_customer' => ['first_name' => 'Taylor', 'last_name' => 'Test', 'email' => 'taylor@example.test', 'phone' => '+1 555 0199']] + $this->booking;
        unset($booking['customer_id']);

        $this->confirmWrite(BookAppointment::class, $booking, 'key-0000005');

        $this->assertSame(['preview', 'ok'], McpCall::orderBy('id')->pluck('outcome')->all());
        $call = McpCall::where('outcome', 'ok')->sole();
        $this->assertSame('book_appointment', $call->tool);
        $this->assertSame('ok', $call->outcome);
        $this->assertSame('Claude', $call->client_name);
        $this->assertSame(100, $call->appointment_id);
        $this->assertSame('t***@example.test', $call->arguments['new_customer']['email']);
        $this->assertSame('T****r', $call->arguments['new_customer']['first_name']);
        $this->assertSame('L******e', $call->arguments['notes']);
        $this->assertStringNotContainsString('0199', json_encode($call->arguments));
        $this->assertSame(['/customers', '/appointments'], array_column(array_filter($call->ea_requests, fn ($r) => $r['method'] === 'POST'), 'path'));
        $this->assertContainsOnly('int', array_column($call->ea_requests, 'status'));
    }

    public function test_an_expired_idempotency_key_can_be_used_again(): void
    {
        $this->confirmWrite(BookAppointment::class, $this->booking, 'key-0000006');
        $this->travel(25)->hours();

        $this->confirmWrite(BookAppointment::class, ['start' => '2026-11-05 13:00'] + $this->booking, 'key-0000006')
            ->assertHasNoErrors();

        $this->assertCount(2, $this->ea->appointments);
    }
}
