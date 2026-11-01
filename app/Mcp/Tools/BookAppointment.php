<?php

namespace App\Mcp\Tools;

use App\Ea\EaApiException;
use App\Ea\EaClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('book_appointment')]
#[Title('Book an appointment')]
#[Description('Books an appointment for an existing customer (customer_id) or a new one (new_customer). Two steps: first call without confirm to get a preview and show it to the person; only after they agree, call again with the same arguments, confirm: true, the confirmation_token from the preview and an idempotency_key. Easy!Appointments then sends its usual confirmation emails.')]
#[IsDestructive(false)]
#[IsIdempotent]
class BookAppointment extends WriteTool
{
    protected function fields(JsonSchema $schema): array
    {
        return [
            'service_id' => $schema->integer()->description('From list_services.')->required(),
            'provider_id' => $schema->integer()->description('From list_services or find_open_slots.')->required(),
            'start' => $schema->string()->pattern('^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$')->description('Start time "YYYY-MM-DD HH:MM" in the provider\'s time zone, exactly as find_open_slots returned it.')->required(),
            'customer_id' => $schema->integer()->description('An existing customer, from find_customer. Give this or new_customer.'),
            'new_customer' => $schema->object([
                'first_name' => $schema->string()->required(),
                'last_name' => $schema->string()->required(),
                'email' => $schema->string()->format('email')->required(),
                'phone' => $schema->string(),
                'address' => $schema->string(),
                'city' => $schema->string(),
                'zip_code' => $schema->string(),
            ])->description('Details for someone not yet in the system. If the email already belongs to a customer, that customer is used. Some businesses also require phone, address, city or zip_code; the preview says which are missing.'),
            'notes' => $schema->string()->max(500)->description('Optional note visible to staff.'),
        ];
    }

    protected function plan(Request $request, EaClient $ea, bool $confirming): PlannedChange
    {
        $request->validate([
            'service_id' => ['required', 'integer'],
            'provider_id' => ['required', 'integer'],
            'start' => ['required', 'string'],
            'customer_id' => ['nullable', 'integer', 'required_without:new_customer', 'prohibits:new_customer'],
            'new_customer' => ['nullable', 'array'],
            'new_customer.first_name' => ['required_with:new_customer', 'string', 'max:100'],
            'new_customer.last_name' => ['required_with:new_customer', 'string', 'max:100'],
            'new_customer.email' => ['required_with:new_customer', 'email:filter', 'max:255'],
            'new_customer.phone' => ['nullable', 'string', 'max:50'],
            'new_customer.address' => ['nullable', 'string', 'max:255'],
            'new_customer.city' => ['nullable', 'string', 'max:100'],
            'new_customer.zip_code' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'customer_id.required_without' => 'Give customer_id for an existing customer, or new_customer for someone new.',
            'customer_id.prohibits' => 'Give customer_id or new_customer, not both.',
        ]);

        [$service, $provider] = $this->serviceOffered($ea, (int) $request->get('service_id'), (int) $request->get('provider_id'));
        $timezone = $provider['timezone'];
        $start = $this->futureTime($request->get('start'), $timezone, 'start');
        $this->assertSlotOpen($ea, $provider, $service, $start, false);

        $newCustomer = $request->get('new_customer');
        $customer = null;

        if ($request->get('customer_id') !== null) {
            $customer = $ea->customer((int) $request->get('customer_id'));
        } else {
            $email = mb_strtolower(trim($newCustomer['email']));
            $customer = collect($ea->searchCustomers($email))->first(fn ($c) => mb_strtolower((string) $c['email']) === $email);
        }

        if ($customer === null) {
            $missing = collect($ea->requiredCustomerFields())
                ->filter(fn (string $field) => trim((string) ($newCustomer[$field] ?? '')) === '')
                ->values();

            if ($missing->isNotEmpty()) {
                throw new ToolFailure('This business requires '.$missing->map(fn ($f) => 'new_customer.'.$f)->implode(', ').' for new customers. Ask the person for it and call book_appointment again.');
            }
        }

        $notes = $request->get('notes');
        $preview = [
            'action' => 'book',
            'timezone' => $timezone,
            'service' => $service['name'],
            'duration_minutes' => $service['duration'],
            'provider' => $provider['name'],
            'start' => $start->format('Y-m-d H:i'),
            'end' => $start->addMinutes($service['duration'])->format('Y-m-d H:i'),
            'weekday' => $start->format('l'),
            'customer' => $customer ?? [
                'name' => trim($newCustomer['first_name'].' '.$newCustomer['last_name']),
                'email' => $newCustomer['email'],
                'phone' => $newCustomer['phone'] ?? null,
                'new' => true,
            ],
            'notes' => $notes,
            'emails' => 'Easy!Appointments will send its usual confirmation email to the customer and the provider.',
        ];

        if ($customer !== null && $newCustomer !== null) {
            $preview['customer_note'] = 'A customer with this email already exists, so the booking uses that record instead of creating a new one.';
        }

        return new PlannedChange($preview, function () use ($ea, $service, $provider, $start, $customer, $newCustomer, $notes, $timezone) {
            $createdCustomer = $customer === null;

            if ($createdCustomer) {
                // Room for the customer, the booking and a clean-up, so the shared budget cannot
                // run out between them and leave a new customer with no booking.
                $ea->reserve(3);
                $customer = $ea->createCustomer($newCustomer);
            }

            $before = $ea->appointmentChanges();

            try {
                $appointment = $ea->createAppointment([
                    'start' => $start->format('Y-m-d H:i:00'),
                    'customerId' => $customer['id'],
                    'providerId' => $provider['id'],
                    'serviceId' => $service['id'],
                    'notes' => $notes,
                ]);
            } catch (EaApiException $e) {
                // Only a clear refusal (4xx) of the booking itself means nothing was booked, so only
                // then remove the customer made for it. After no answer, a server error, or any
                // error once the booking was saved, it may exist, and Easy!Appointments deletes a
                // customer's bookings with the customer.
                if ($createdCustomer && ! $e->outcomeUnknown() && $ea->appointmentChanges() === $before) {
                    try {
                        $ea->deleteCustomer($customer['id']);
                    } catch (EaApiException) {
                        // Best effort: a leftover customer record is harmless.
                    }
                }

                throw $e;
            }

            $ea->forgetAvailability($provider['id'], $start->format('Y-m-d'));

            return [
                'status' => 'booked',
                'timezone' => $timezone,
                // Names come from what was checked before booking, in case reading them back failed.
                'appointment' => array_merge($appointment, [
                    'customer' => $customer['name'],
                    'service' => $appointment['service'] ?? $service['name'],
                    'provider' => $appointment['provider'] ?? $provider['name'],
                    'timezone' => $appointment['timezone'] ?? $timezone,
                ]),
            ];
        },
            lock: $this->slotLock($ea, $provider['id'], $start),
            recheck: fn () => $this->assertSlotOpen($ea, $provider, $service, $start, true),
        );
    }
}
