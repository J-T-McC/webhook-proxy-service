<?php

namespace Tests\Unit\Models;

use App\Enums\HttpMethod;
use App\Models\Destination;
use App\Models\Proxy;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DestinationTest extends TestCase
{
    public function test_http_method_casts_to_enum(): void
    {
        $destination = Destination::factory()->create(['http_method' => HttpMethod::Put]);

        $this->assertInstanceOf(HttpMethod::class, $destination->fresh()->http_method);
        $this->assertSame(HttpMethod::Put, $destination->fresh()->http_method);
    }

    public function test_proxy_relation_returns_the_owning_proxy(): void
    {
        $proxy = Proxy::factory()->create();
        $destination = Destination::factory()->for($proxy)->create();

        $this->assertTrue($destination->proxy->is($proxy));
    }

    public function test_delete_soft_deletes_the_destination(): void
    {
        $destination = Destination::factory()->create();
        $destination->delete();

        $this->assertSoftDeleted($destination);
    }

    public function test_soft_deleted_destination_is_excluded_from_proxy_destinations(): void
    {
        $proxy = Proxy::factory()->create();
        $live = Destination::factory()->for($proxy)->create();
        $trashed = Destination::factory()->for($proxy)->create();
        $trashed->delete();

        $ids = $proxy->destinations()->pluck('id');

        $this->assertTrue($ids->contains($live->id));
        $this->assertFalse($ids->contains($trashed->id));
    }

    public function test_foreign_key_is_not_on_delete_cascade(): void
    {
        $rule = DB::selectOne(
            'SELECT DELETE_RULE
             FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND REFERENCED_TABLE_NAME = ?',
            ['destinations', 'proxies'],
        );

        $this->assertNotNull($rule);
        $this->assertNotSame('CASCADE', strtoupper((string) $rule->DELETE_RULE));
    }
}
