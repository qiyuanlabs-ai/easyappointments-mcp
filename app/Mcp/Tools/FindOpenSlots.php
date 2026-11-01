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

#[Name('find_open_slots')]
#[Title('Find open appointment slots')]
#[Description('Finds open start times for one service over a range of days, across every provider who offers it or just one. Times are in the time zone of each provider, given in the result. Use this before booking or rescheduling.')]
#[IsReadOnly]
class FindOpenSlots extends EaTool
{
    private const MAX_SLOTS = 60;

    public function schema(JsonSchema $schema): array
    {
        return [
            'service_id' => $schema->integer()->description('The service, from list_services.')->required(),
            'provider_id' => $schema->integer()->description('Optional: only this provider.'),
            'date_from' => $schema->string()->format('date')->description('First day to search, YYYY-MM-DD. Defaults to today.'),
            'days' => $schema->integer()->min(1)->max(14)->default(7)->description('How many days to search, 1 to 14. Default 7.'),
            'part_of_day' => $schema->string()->enum(['any', 'morning', 'afternoon', 'evening'])->default('any')->description('morning is before 12:00, afternoon 12:00 to 17:00, evening from 17:00.'),
        ];
    }

    protected function perform(Request $request, EaClient $ea, User $user): ToolResult
    {
        $request->validate([
            'service_id' => ['required', 'integer'],
            'provider_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'days' => ['nullable', 'integer', 'min:1', 'max:14'],
            'part_of_day' => ['nullable', 'in:any,morning,afternoon,evening'],
        ]);

        $timezone = $ea->timezone();
        $service = $ea->service((int) $request->get('service_id'))
            ?? throw new ToolFailure('No service with id '.$request->get('service_id').'. Use list_services to see the ids.');

        $providers = collect($ea->providers())->filter(fn ($p) => in_array($service['id'], $p['services'], true));

        if ($request->get('provider_id') !== null) {
            $providers = $providers->where('id', (int) $request->get('provider_id'));

            if ($providers->isEmpty()) {
                throw new ToolFailure('That provider does not offer '.$service['name'].'. Use list_services to see who does.');
            }
        }

        if ($providers->isEmpty()) {
            throw new ToolFailure('Nobody offers '.$service['name'].' at the moment.');
        }

        $today = CarbonImmutable::now($timezone)->startOfDay();
        $from = $request->get('date_from') ? CarbonImmutable::createFromFormat('!Y-m-d', $request->get('date_from'), $timezone) : $today;
        $from = $from->lt($today) ? $today : $from;
        $days = (int) ($request->get('days') ?? 7);

        // Easy!Appointments answers one provider and one day per request, and rate limits per IP.
        $maxDays = max(1, intdiv(config('easyappointments.max_availability_lookups'), $providers->count()));
        $note = null;

        if ($days > $maxDays) {
            $note = "Searched $maxDays days instead of $days to stay within the booking system's request limit. Ask for one provider, or search again from a later date_from, to see more.";
            $days = $maxDays;
        }

        $dates = collect(range(0, $days - 1))->map(fn ($i) => $from->addDays($i)->format('Y-m-d'));
        $lookups = $dates->crossJoin($providers->pluck('id'))->map(fn ($pair) => [$pair[1], $service['id'], $pair[0]])->all();
        $hours = $ea->availabilityMany($lookups);
        $names = $providers->pluck('name', 'id');
        $zones = $providers->pluck('timezone', 'id');
        // Easy!Appointments answers in each provider's own time zone. Usually they all share one.
        $oneZone = $zones->unique()->count() === 1 ? $zones->first() : null;
        $part = $request->get('part_of_day') ?? 'any';
        $now = CarbonImmutable::now();

        $byDay = [];
        foreach ($lookups as $i => [$providerId, , $date]) {
            foreach ($hours[$i] as $time) {
                if ($this->inPartOfDay($time, $part) && CarbonImmutable::parse("$date $time", $zones[$providerId])->gt($now)) {
                    $byDay[$date][] = array_filter([
                        'time' => $time,
                        'provider_id' => $providerId,
                        'provider' => $names[$providerId],
                        'timezone' => $oneZone === null ? $zones[$providerId] : null,
                    ], fn ($v) => $v !== null);
                }
            }
        }

        $total = 0;
        $shown = 0;
        $result = [];
        foreach ($dates as $date) {
            $slots = collect($byDay[$date] ?? [])->sortBy(['time', 'provider'])->values();
            $total += $slots->count();
            $take = max(0, min($slots->count(), self::MAX_SLOTS - $shown));
            $shown += $take;
            $result[] = [
                'date' => $date,
                'weekday' => CarbonImmutable::parse($date)->format('l'),
                'slots' => $slots->take($take)->all(),
            ];
        }

        if ($shown < $total) {
            $note = trim(($note ?? '')." Showing the first $shown of $total open times; narrow by provider_id, part_of_day or fewer days for the rest.");
        }

        return new ToolResult(array_filter([
            'timezone' => $oneZone ?? 'Differs by provider; each slot gives its own.',
            'service' => ['id' => $service['id'], 'name' => $service['name'], 'duration_minutes' => $service['duration']],
            'from' => $dates->first(),
            'to' => $dates->last(),
            'total_open_times' => $total,
            'days' => $result,
            'note' => $note,
        ], fn ($v) => $v !== null));
    }

    private function inPartOfDay(string $time, string $part): bool
    {
        return match ($part) {
            'morning' => $time < '12:00',
            'afternoon' => $time >= '12:00' && $time < '17:00',
            'evening' => $time >= '17:00',
            default => true,
        };
    }
}
