<?php

namespace App\Mcp\Tools;

use App\Ea\EaClient;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('cancel_appointment')]
#[Title('Cancel an appointment')]
#[Description('Cancels an upcoming appointment. Easy!Appointments deletes it and emails the customer a cancellation notice; this cannot be undone. Two steps: call without confirm for a preview, then, after the person agrees, again with confirm: true, the confirmation_token from the preview and an idempotency_key.')]
#[IsDestructive]
#[IsIdempotent]
class CancelAppointment extends WriteTool
{
    protected function fields(JsonSchema $schema): array
    {
        return [
            'appointment_id' => $schema->integer()->description('From list_appointments.')->required(),
            'reason' => $schema->string()->max(300)->description('Optional reason. It is kept in this server\'s call log; Easy!Appointments has nowhere to store it.'),
        ];
    }

    protected function plan(Request $request, EaClient $ea, bool $confirming): PlannedChange
    {
        $request->validate([
            'appointment_id' => ['required', 'integer'],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);

        $current = $ea->appointment((int) $request->get('appointment_id'));
        $timezone = $current['timezone'];
        $start = CarbonImmutable::createFromFormat('!Y-m-d H:i', $current['start'], $timezone);

        if ($start->isPast()) {
            throw new ToolFailure('That appointment has already started or passed. Past appointments are kept as a record and cannot be cancelled here.');
        }

        $customer = $current['customer_id'] !== null ? $ea->customer($current['customer_id']) : null;

        $preview = [
            'action' => 'cancel',
            'timezone' => $timezone,
            'appointment' => [
                'id' => $current['id'],
                'service' => $current['service'],
                'provider' => $current['provider'],
                'start' => $current['start'],
                'weekday' => $start->format('l'),
                'customer' => $customer['name'] ?? null,
            ],
            'reason' => $request->get('reason'),
            'warning' => 'This deletes the appointment in Easy!Appointments and cannot be undone. The customer gets a cancellation email.',
        ];

        return new PlannedChange($preview, function () use ($ea, $current, $start, $timezone) {
            $ea->deleteAppointment($current['id']);
            $ea->forgetAvailability($current['provider_id'], $start->format('Y-m-d'));

            return [
                'status' => 'cancelled',
                'timezone' => $timezone,
                'appointment' => ['id' => $current['id'], 'start' => $current['start'], 'service' => $current['service'], 'provider' => $current['provider']],
            ];
        }, $current['id']);
    }
}
