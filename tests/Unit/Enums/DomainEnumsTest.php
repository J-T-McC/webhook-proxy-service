<?php

namespace Tests\Unit\Enums;

use App\Enums\AttemptStatus;
use App\Enums\FifoDispatchStatus;
use App\Enums\HttpMethod;
use App\Enums\ProcessingMode;
use App\Enums\ProxyMode;
use App\Enums\StoredPayloadState;
use PHPUnit\Framework\TestCase;

class DomainEnumsTest extends TestCase
{
    public function test_proxy_mode_has_exactly_simple_and_enhanced(): void
    {
        $this->assertSame(
            ['simple', 'enhanced'],
            array_map(fn (ProxyMode $c) => $c->value, ProxyMode::cases()),
        );
    }

    public function test_http_method_has_exactly_post_and_put(): void
    {
        $this->assertSame(
            ['POST', 'PUT'],
            array_map(fn (HttpMethod $c) => $c->value, HttpMethod::cases()),
        );
    }

    public function test_attempt_status_has_exactly_dispatched_succeeded_failed(): void
    {
        $this->assertSame(
            ['dispatched', 'succeeded', 'failed'],
            array_map(fn (AttemptStatus $c) => $c->value, AttemptStatus::cases()),
        );
    }

    public function test_processing_mode_has_exactly_async_and_fifo(): void
    {
        $this->assertSame(
            ['async', 'fifo'],
            array_map(fn (ProcessingMode $c) => $c->value, ProcessingMode::cases()),
        );
    }

    public function test_fifo_dispatch_status_has_exactly_pending_claimed_settled(): void
    {
        $this->assertSame(
            ['pending', 'claimed', 'settled'],
            array_map(fn (FifoDispatchStatus $c) => $c->value, FifoDispatchStatus::cases()),
        );
    }

    public function test_stored_payload_state_has_exactly_retained_cleaned_never_captured(): void
    {
        $this->assertSame(
            ['retained', 'cleaned', 'never_captured'],
            array_map(fn (StoredPayloadState $c) => $c->value, StoredPayloadState::cases()),
        );
    }
}
