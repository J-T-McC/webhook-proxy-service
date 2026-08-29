<?php

namespace Tests\Unit\Support;

use App\Support\StandardWebhooks;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * T7 — `App\Support\StandardWebhooks` (AC52, AC53, AC55).
 *
 * The tolerance check (AC53) lives inside `verify()` itself, not deferred to
 * a T17 scheme wrapper — the task's own Testing section requires this file
 * to cover the tolerance boundary directly against the class. Because
 * `verify()` therefore rejects anything outside `TOLERANCE_SECONDS` of the
 * real wall clock, the specification's own published fixed-timestamp vector
 * (2021) cannot be run through `verify()` today; it is used instead to pin
 * `sign()`'s HMAC/base64 construction directly, which is the part of the
 * specification that fixture exists to prove. The `verify()` round-trip
 * tests below use a current timestamp with a signature computed by the
 * already-pinned `sign()`.
 */
class StandardWebhooksTest extends TestCase
{
    /**
     * The Standard Webhooks specification's own reference example, published
     * in its verification test suite (svix-webhooks): secret, id, timestamp,
     * payload and the expected `v1` signature.
     */
    private const SPEC_SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';

    private const SPEC_ID = 'msg_p5jXN8AQM9LWM0D4loKWxJek';

    private const SPEC_TIMESTAMP = 1614265330;

    private const SPEC_BODY = '{"test": 2432232314}';

    private const SPEC_SIGNATURE = 'g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=';

    #[Test]
    public function sign_matches_the_specifications_published_reference_vector(): void
    {
        $this->assertSame(
            self::SPEC_SIGNATURE,
            StandardWebhooks::sign(self::SPEC_ID, self::SPEC_TIMESTAMP, self::SPEC_BODY, self::SPEC_SECRET),
        );
    }

    #[Test]
    public function a_specification_computed_signature_verifies(): void
    {
        $timestamp = time();
        $signature = 'v1,'.StandardWebhooks::sign(self::SPEC_ID, $timestamp, self::SPEC_BODY, self::SPEC_SECRET);

        $this->assertTrue(StandardWebhooks::verify(self::SPEC_ID, $timestamp, self::SPEC_BODY, $signature, [self::SPEC_SECRET]));
    }

    #[Test]
    public function a_multi_entry_signature_list_verifies_when_only_the_second_entry_matches(): void
    {
        $timestamp = time();
        $real = StandardWebhooks::sign(self::SPEC_ID, $timestamp, self::SPEC_BODY, self::SPEC_SECRET);
        $header = 'v1,not-the-right-signature== v1,'.$real;

        $this->assertTrue(StandardWebhooks::verify(self::SPEC_ID, $timestamp, self::SPEC_BODY, $header, [self::SPEC_SECRET]));
    }

    #[Test]
    public function a_non_v1_entry_is_skipped_rather_than_causing_a_failure_when_a_later_entry_matches(): void
    {
        $timestamp = time();
        $real = StandardWebhooks::sign(self::SPEC_ID, $timestamp, self::SPEC_BODY, self::SPEC_SECRET);
        $header = 'v2,'.$real.' v1,'.$real;

        $this->assertTrue(StandardWebhooks::verify(self::SPEC_ID, $timestamp, self::SPEC_BODY, $header, [self::SPEC_SECRET]));
    }

    #[Test]
    public function a_timestamp_outside_tolerance_is_rejected(): void
    {
        $id = 'msg_test';
        $body = '{"a":1}';
        $secret = 'whsec_'.base64_encode('a-test-secret-value');
        $timestamp = time() - (StandardWebhooks::TOLERANCE_SECONDS + 1);
        $signature = 'v1,'.StandardWebhooks::sign($id, $timestamp, $body, $secret);

        $this->assertFalse(StandardWebhooks::verify($id, $timestamp, $body, $signature, [$secret]));
    }

    #[Test]
    public function a_timestamp_one_second_inside_tolerance_is_accepted(): void
    {
        $id = 'msg_test';
        $body = '{"a":1}';
        $secret = 'whsec_'.base64_encode('a-test-secret-value');
        $timestamp = time() - (StandardWebhooks::TOLERANCE_SECONDS - 1);
        $signature = 'v1,'.StandardWebhooks::sign($id, $timestamp, $body, $secret);

        $this->assertTrue(StandardWebhooks::verify($id, $timestamp, $body, $signature, [$secret]));
    }

    #[Test]
    public function a_whsec_prefixed_secret_and_a_bare_base64_secret_produce_the_same_signature(): void
    {
        $bare = base64_encode('a-shared-secret-value');
        $prefixed = 'whsec_'.$bare;

        $this->assertSame(
            StandardWebhooks::sign('msg_1', 1700000000, '{}', $bare),
            StandardWebhooks::sign('msg_1', 1700000000, '{}', $prefixed),
        );
    }

    #[Test]
    public function a_whsec_prefixed_secret_verifies_the_same_request_as_its_bare_equivalent(): void
    {
        $timestamp = time();
        $bare = base64_encode('a-shared-secret-value');
        $prefixed = 'whsec_'.$bare;
        $signature = 'v1,'.StandardWebhooks::sign('msg_1', $timestamp, '{}', $bare);

        $this->assertTrue(StandardWebhooks::verify('msg_1', $timestamp, '{}', $signature, [$prefixed]));
    }

    #[Test]
    public function hex_encoded_input_where_base64_is_expected_fails_to_verify(): void
    {
        $id = 'msg_test';
        $body = '{"a":1}';
        $timestamp = time();
        $realSecretBytes = 'a-real-secret-value';
        $secret = base64_encode($realSecretBytes);
        $signature = 'v1,'.StandardWebhooks::sign($id, $timestamp, $body, $secret);

        // The same bytes, hex-encoded instead of base64-encoded — a plausible
        // mistake if a secret were generated/stored in the wrong encoding.
        $hexSecret = bin2hex($realSecretBytes);

        $this->assertFalse(StandardWebhooks::verify($id, $timestamp, $body, $signature, [$hexSecret]));
    }

    #[Test]
    public function an_empty_secret_list_never_verifies(): void
    {
        $timestamp = time();
        $signature = 'v1,'.StandardWebhooks::sign('msg_1', $timestamp, '{}', base64_encode('x'));

        $this->assertFalse(StandardWebhooks::verify('msg_1', $timestamp, '{}', $signature, []));
    }
}
