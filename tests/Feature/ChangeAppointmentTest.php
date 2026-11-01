<?php

namespace Tests\Feature;

use App\Mcp\Servers\EasyAppointmentsServer;
use App\Mcp\Tools\CancelAppointment;
use App\Mcp\Tools\RescheduleAppointment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConnectsMcp;
use Tests\TestCase;

class ChangeAppointmentTest extends TestCase
{
    use ConnectsMcp, RefreshDatabase;

    private int $id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcp();
        $this->id = $this->ea->addAppointment(['start' => '2026-11-03 10:00', 'serviceId' => 1, 'providerId' => 10, 'customerId' => 1])['id'];
    }

    public function test_reschedule_previews_then_moves_the_appointment(): void
    {
        $move = ['appointment_id' => $this->id, 'new_start' => '2026-11-04 13:00'];

        $preview = $this->structured(EasyAppointmentsServer::tool(RescheduleAppointment::class, $move));
        $this->assertSame(['2026-11-03 10:00', '2026-11-04 13:00', 'Wednesday'], [$preview['from']['start'], $preview['to']['start'], $preview['to']['weekday']]);
        $this->assertSame([], $this->ea->writes());

        $done = $this->structured($this->confirmWrite(RescheduleAppointment::class, $move, 'move-000001'));
        $this->assertSame('rescheduled', $done['status']);
        $this->assertSame('2026-11-04 13:00', $done['appointment']['start']);
        $this->assertSame('2026-11-04 14:00', $done['appointment']['end']);
        $this->assertSame('Olivia Bennett', $done['appointment']['customer']);
        $this->assertSame(["PUT /appointments/{$this->id}"], $this->ea->writes());
    }

    public function test_reschedule_to_another_provider_checks_that_they_offer_the_service(): void
    {
        $this->structured(EasyAppointmentsServer::tool(RescheduleAppointment::class, ['appointment_id' => $this->id, 'new_start' => '2026-11-04 13:00', 'provider_id' => 11]));

        $sports = $this->ea->addAppointment(['start' => '2026-11-03 14:00', 'serviceId' => 2, 'providerId' => 10, 'customerId' => 2])['id'];
        EasyAppointmentsServer::tool(RescheduleAppointment::class, ['appointment_id' => $sports, 'new_start' => '2026-11-04 13:00', 'provider_id' => 11])
            ->assertHasErrors(['Marcus Reed does not offer Sports massage']);
    }

    public function test_an_overlapping_move_explains_the_api_limit(): void
    {
        $this->ea->open['10|2026-11-03'] = ['09:00', '10:00', '11:00'];

        EasyAppointmentsServer::tool(RescheduleAppointment::class, ['appointment_id' => $this->id, 'new_start' => '2026-11-03 10:30'])
            ->assertHasErrors(['overlaps the current booking']);
    }

    public function test_past_appointments_cannot_be_moved_or_cancelled(): void
    {
        $past = $this->ea->addAppointment(['start' => '2026-10-30 10:00', 'serviceId' => 1, 'providerId' => 10, 'customerId' => 1])['id'];

        EasyAppointmentsServer::tool(RescheduleAppointment::class, ['appointment_id' => $past, 'new_start' => '2026-11-04 13:00'])
            ->assertHasErrors(['already started or passed']);
        EasyAppointmentsServer::tool(CancelAppointment::class, ['appointment_id' => $past])
            ->assertHasErrors(['already started or passed']);
    }

    public function test_cancel_previews_with_a_warning_then_deletes(): void
    {
        $preview = $this->structured(EasyAppointmentsServer::tool(CancelAppointment::class, ['appointment_id' => $this->id, 'reason' => 'Feeling better']));
        $this->assertStringContainsString('cannot be undone', $preview['warning']);
        $this->assertSame('Olivia Bennett', $preview['appointment']['customer']);
        $this->assertSame([], $this->ea->writes());

        $confirm = ['appointment_id' => $this->id, 'reason' => 'Feeling better', 'confirm' => true, 'confirmation_token' => $preview['confirmation_token'], 'idempotency_key' => 'cancel-00001'];
        $done = $this->structured(EasyAppointmentsServer::tool(CancelAppointment::class, $confirm));
        $this->assertSame('cancelled', $done['status']);
        $this->assertSame([], $this->ea->appointments);

        // A retry after a dropped connection gets the same answer instead of a "not found".
        $again = $this->structured(EasyAppointmentsServer::tool(CancelAppointment::class, $confirm));
        $this->assertTrue($again['replayed']);
        $this->assertSame(["DELETE /appointments/{$this->id}"], $this->ea->writes());
    }

    public function test_an_unknown_appointment_gets_a_clear_message(): void
    {
        EasyAppointmentsServer::tool(CancelAppointment::class, ['appointment_id' => 999])
            ->assertHasErrors(['has no record']);
    }
}
