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

#[Name('reschedule_appointment')]
#[Title('Reschedule an appointment')]
#[Description('Moves an appointment to a new start time, optionally with another provider who offers the same service. Two steps: call without confirm for a preview, then, after the person agrees, again with confirm: true, the confirmation_token from the preview and an idempotency_key. Easy!Appointments emails the customer about the change.')]
#[IsDestructive]
#[IsIdempotent]
class RescheduleAppointment extends WriteTool
{
    protected function fields(JsonSchema $schema): array
    {
        return [
            'appointment_id' => $schema->integer()->description('From list_appointments.')->required(),
            'new_start' => $schema->string()->pattern('^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$')->description('New start "YYYY-MM-DD HH:MM" in the time zone of the provider it moves to, from find_open_slots.')->required(),
            'provider_id' => $schema->integer()->description('Optional: move to this provider. Defaults to the current one.'),
        ];
    }

    protected function plan(Request $request, EaClient $ea, bool $confirming): PlannedChange
    {
        $request->validate([
            'appointment_id' => ['required', 'integer'],
            'new_start' => ['required', 'string'],
            'provider_id' => ['nullable', 'integer'],
        ]);

        $current = $ea->appointment((int) $request->get('appointment_id'));
        [$service, $provider] = $this->serviceOffered($ea, $current['service_id'], (int) ($request->get('provider_id') ?? $current['provider_id']));
        $timezone = $provider['timezone'];
        $newStart = $this->futureTime($request->get('new_start'), $timezone, 'new_start');
        $oldStart = CarbonImmutable::createFromFormat('!Y-m-d H:i', $current['start'], $current['timezone']);

        if ($oldStart->isPast()) {
            throw new ToolFailure('That appointment has already started or passed, so it cannot be moved. Book a new one instead.');
        }

        if ($newStart->eq($oldStart) && $provider['id'] === $current['provider_id']) {
            throw new ToolFailure('The appointment is already at that time with that provider.');
        }

        $oldEnd = CarbonImmutable::createFromFormat('!Y-m-d H:i', $current['end'], $current['timezone']);
        $overlapsItself = $provider['id'] === $current['provider_id']
            && $newStart->lt($oldEnd) && $newStart->addMinutes($service['duration'])->gt($oldStart);

        $checkSlot = function (bool $fresh) use ($ea, $provider, $service, $newStart, $overlapsItself, $current) {
            try {
                $this->assertSlotOpen($ea, $provider, $service, $newStart, $fresh);
            } catch (ToolFailure $e) {
                // The API cannot ignore the appointment being moved when it checks availability.
                throw $overlapsItself
                    ? new ToolFailure('The new time overlaps the current booking, and Easy!Appointments cannot check that kind of move through its API. Pick a time that does not overlap '.$current['start'].' to '.$current['end'].', or cancel and rebook.')
                    : $e;
            }
        };
        $checkSlot(false);

        $customer = $current['customer_id'] !== null ? $ea->customer($current['customer_id']) : null;
        $preview = [
            'action' => 'reschedule',
            'timezone' => $timezone,
            'appointment_id' => $current['id'],
            'service' => $service['name'],
            'customer' => $customer['name'] ?? null,
            'from' => ['start' => $current['start'], 'provider' => $current['provider'], 'timezone' => $current['timezone']],
            'to' => [
                'start' => $newStart->format('Y-m-d H:i'),
                'end' => $newStart->addMinutes($service['duration'])->format('Y-m-d H:i'),
                'weekday' => $newStart->format('l'),
                'provider' => $provider['name'],
            ],
            'emails' => 'Easy!Appointments will email the customer and provider about the change.',
        ];

        return new PlannedChange($preview, function () use ($ea, $current, $customer, $provider, $newStart, $oldStart, $timezone) {
            $appointment = $ea->updateAppointment($current['id'], [
                'start' => $newStart->format('Y-m-d H:i:00'),
                'end' => $newStart->addMinutes($ea->service($current['service_id'])['duration'])->format('Y-m-d H:i:00'),
                'providerId' => $provider['id'],
            ]);

            $ea->forgetAvailability($current['provider_id'], $oldStart->format('Y-m-d'));
            $ea->forgetAvailability($provider['id'], $newStart->format('Y-m-d'));

            return ['status' => 'rescheduled', 'timezone' => $timezone, 'appointment' => array_merge($appointment, ['customer' => $customer['name'] ?? null])];
        }, $current['id'],
            lock: $this->slotLock($ea, $provider['id'], $newStart),
            recheck: fn () => $checkSlot(true),
        );
    }
}
