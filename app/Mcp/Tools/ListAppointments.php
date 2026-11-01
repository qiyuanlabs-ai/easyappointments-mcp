<?php

namespace App\Mcp\Tools;

use App\Ea\EaClient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_appointments')]
#[Title('List appointments')]
#[Description('Lists appointments for a customer, a provider and/or a date range, earliest first. Give at least one of customer_id, provider_id or date_from. Without dates it shows the next 30 days.')]
#[IsReadOnly]
class ListAppointments extends EaTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'customer_id' => $schema->integer()->description('From find_customer.'),
            'provider_id' => $schema->integer()->description('From list_services.'),
            'date_from' => $schema->string()->format('date')->description('YYYY-MM-DD. Defaults to today; give an earlier date to see past visits.'),
            'date_to' => $schema->string()->format('date')->description('YYYY-MM-DD, inclusive. Defaults to 30 days after date_from.'),
        ];
    }

    protected function perform(Request $request, EaClient $ea, User $user): ToolResult
    {
        $request->validate([
            'customer_id' => ['nullable', 'integer', 'required_without_all:provider_id,date_from'],
            'provider_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ], [
            'customer_id.required_without_all' => 'Give at least one of customer_id, provider_id or date_from.',
        ]);

        $timezone = $ea->timezone();
        $from = $request->get('date_from') ?? CarbonImmutable::now($timezone)->format('Y-m-d');
        $to = $request->get('date_to') ?? CarbonImmutable::parse($from)->addDays(30)->format('Y-m-d');

        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 92) {
            throw new ToolFailure('Ask for at most about three months at a time.');
        }

        $appointments = $ea->appointments([
            'customerId' => $request->get('customer_id'),
            'providerId' => $request->get('provider_id'),
            'from' => $from,
            'till' => $to,
        ]);

        return new ToolResult(array_filter([
            'timezone' => $timezone,
            'from' => $from,
            'to' => $to,
            'count' => count($appointments),
            'appointments' => $appointments,
            'note' => count($appointments) >= 50 ? 'Only the first 50 are shown. Narrow the dates to see the rest.' : null,
        ], fn ($v) => $v !== null));
    }
}
