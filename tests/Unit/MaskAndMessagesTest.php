<?php

namespace Tests\Unit;

use App\Ea\EaClient;
use App\Enums\AccessLevel;
use App\Support\Mask;
use PHPUnit\Framework\TestCase;

class MaskAndMessagesTest extends TestCase
{
    public function test_personal_fields_are_masked_at_any_depth_and_ids_are_kept(): void
    {
        $masked = Mask::arguments([
            'customer_id' => 7,
            'query' => 'Olivia',
            'new_customer' => ['first_name' => 'Al', 'email' => 'olivia@example.test', 'phone' => '+1 555 0101', 'zip_code' => '02134'],
        ]);

        $this->assertSame(7, $masked['customer_id']);
        $this->assertSame('O****a', $masked['query']);
        $this->assertSame('**', $masked['new_customer']['first_name']);
        $this->assertSame('o***@example.test', $masked['new_customer']['email']);
        $this->assertStringNotContainsString('0101', $masked['new_customer']['phone']);
        $this->assertSame('0***4', $masked['new_customer']['zip_code']);
    }

    public function test_easyappointments_record_dumps_are_stripped_from_messages(): void
    {
        $raw = "Not all required fields are provided: Array\n(\n    [first_name] => Taylor\n    [email] => taylor@example.test\n    [phone_number] => \n    [timezone] => America/New_York\n)\n";

        $clean = EaClient::cleanMessage($raw);

        $this->assertSame('Not all required fields are provided (missing: phone_number)', $clean);
        $this->assertStringNotContainsString('taylor', $clean);
        $this->assertSame('The selected time is not available', EaClient::cleanMessage('The selected time is not available.'));
        $this->assertSame('invalid data', EaClient::cleanMessage(null));
        // Some messages quote the customer's email; it must not reach the model, the log or the CSV.
        $this->assertSame('The provided email address is already in use: o***@example.test', EaClient::cleanMessage('The provided email address is already in use: olivia.bennett@example.test.'));
    }

    public function test_access_levels_cap_and_allow(): void
    {
        $this->assertTrue(AccessLevel::FrontDesk->allows(AccessLevel::Read));
        $this->assertFalse(AccessLevel::Read->allows(AccessLevel::FrontDesk));
        $this->assertSame(AccessLevel::Read, AccessLevel::FrontDesk->cap(AccessLevel::Read));
        $this->assertSame(AccessLevel::Read, AccessLevel::Read->cap(AccessLevel::FrontDesk));
    }
}
