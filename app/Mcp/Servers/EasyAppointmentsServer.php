<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\BookAppointment;
use App\Mcp\Tools\CancelAppointment;
use App\Mcp\Tools\FindCustomer;
use App\Mcp\Tools\FindOpenSlots;
use App\Mcp\Tools\ListAppointments;
use App\Mcp\Tools\ListServices;
use App\Mcp\Tools\RescheduleAppointment;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Easy!Appointments')]
#[Version('0.1.0')]
#[Instructions(<<<'MD'
Front-desk tools for an Easy!Appointments booking system.

- Start with list_services to get service and provider ids.
- All times are in the business time zone given in each result. Say the time zone when you tell the person a time.
- Use find_open_slots before booking or rescheduling. Use find_customer before booking for someone who may already be a customer, and ask which person is meant if several match.
- book_appointment, reschedule_appointment and cancel_appointment work in two steps. First call without confirm and show the person the preview. Only after they clearly agree, call again with confirm: true and a new idempotency_key. Never confirm on your own.
- If a tool is missing, this connection has read-only access. Tell the person they can reconnect with Front desk access.
MD)]
class EasyAppointmentsServer extends Server
{
    protected array $tools = [
        ListServices::class,
        FindOpenSlots::class,
        FindCustomer::class,
        ListAppointments::class,
        BookAppointment::class,
        RescheduleAppointment::class,
        CancelAppointment::class,
    ];
}
