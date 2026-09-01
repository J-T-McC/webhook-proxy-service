<?php

namespace Tests\Unit\Enums;

use App\Enums\AttemptStatus;
use App\Enums\DeliveryStatus;
use App\Enums\DispatchKind;
use App\Enums\FifoDispatchStatus;
use App\Enums\HttpMethod;
use App\Enums\ProcessingMode;
use App\Enums\ProxyMode;
use App\Enums\RetryBackoffStrategy;
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

    public function test_fifo_dispatch_status_has_exactly_pending_claimed_settled_awaiting_retry(): void
    {
        $this->assertSame(
            ['pending', 'claimed', 'settled', 'awaiting_retry'],
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

    public function test_retry_backoff_strategy_has_exactly_exponential_and_fixed(): void
    {
        $this->assertSame(
            ['exponential', 'fixed'],
            array_map(fn (RetryBackoffStrategy $c) => $c->value, RetryBackoffStrategy::cases()),
        );
    }

    public function test_dispatch_kind_has_exactly_original_and_replay(): void
    {
        $this->assertSame(
            ['original', 'replay'],
            array_map(fn (DispatchKind $c) => $c->value, DispatchKind::cases()),
        );
    }

    public function test_delivery_status_has_exactly_pending_retrying_succeeded_failed_skipped(): void
    {
        // `skipped` added by ADR-028 (#18): a destination that lost validated
        // state before its delivery was sent. Terminal, but not a failure.
        $this->assertSame(
            ['pending', 'retrying', 'succeeded', 'failed', 'skipped'],
            array_map(fn (DeliveryStatus $c) => $c->value, DeliveryStatus::cases()),
        );
    }

    public function test_delivery_status_is_terminal_truth_table(): void
    {
        $this->assertFalse(DeliveryStatus::Pending->isTerminal());
        $this->assertFalse(DeliveryStatus::Retrying->isTerminal());
        $this->assertTrue(DeliveryStatus::Succeeded->isTerminal());
        $this->assertTrue(DeliveryStatus::Failed->isTerminal());
        // Terminal so the FIFO completion check settles the line rather than
        // holding it behind a destination nobody will contact (ADR-028).
        $this->assertTrue(DeliveryStatus::Skipped->isTerminal());
    }
}
