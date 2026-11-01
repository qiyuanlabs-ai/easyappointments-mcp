<?php

namespace App\Mcp\Tools;

use App\Ea\EaClient;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('find_customer')]
#[Title('Find a customer')]
#[Description('Searches customers by name, email or phone and returns up to 10 matches with their customer_id. If several people match, ask which one is meant before booking.')]
#[IsReadOnly]
class FindCustomer extends EaTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Part of a name, an email address or a phone number.')->required(),
        ];
    }

    protected function perform(Request $request, EaClient $ea, User $user): ToolResult
    {
        $request->validate(['query' => ['required', 'string', 'min:2', 'max:100']]);
        $customers = $ea->searchCustomers(trim($request->get('query')));

        return new ToolResult([
            'count' => count($customers),
            'customers' => $customers,
        ]);
    }
}
