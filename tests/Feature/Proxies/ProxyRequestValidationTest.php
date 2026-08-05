<?php

namespace Tests\Feature\Proxies;

use App\Http\Requests\StoreProxyRequest;
use App\Http\Requests\UpdateProxyRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProxyRequestValidationTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function validData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'My proxy',
            'mode' => 'simple',
            'destinations' => [
                ['url' => 'https://example.com/hook', 'http_method' => 'POST'],
            ],
        ], $overrides);
    }

    /**
     * @return array<int, array{0: class-string}>
     */
    public static function requestClasses(): array
    {
        return [
            'store' => [StoreProxyRequest::class],
            'update' => [UpdateProxyRequest::class],
        ];
    }

    private function validate(string $requestClass, array $data): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($data, (new $requestClass)->rules());
    }

    #[DataProvider('requestClasses')]
    public function test_valid_payload_passes(string $requestClass): void
    {
        $this->assertFalse($this->validate($requestClass, $this->validData())->fails());
    }

    #[DataProvider('requestClasses')]
    public function test_zero_destinations_rejected_under_destinations_key(string $requestClass): void
    {
        $validator = $this->validate($requestClass, $this->validData(['destinations' => []]));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('destinations', $validator->errors()->messages());
    }

    #[DataProvider('requestClasses')]
    public function test_http_url_is_rejected_under_row_url_key(string $requestClass): void
    {
        $validator = $this->validate($requestClass, $this->validData([
            'destinations' => [['url' => 'http://example.com/hook', 'http_method' => 'POST']],
        ]));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('destinations.0.url', $validator->errors()->messages());
    }

    #[DataProvider('requestClasses')]
    public function test_scheme_less_url_is_rejected_under_row_url_key(string $requestClass): void
    {
        $validator = $this->validate($requestClass, $this->validData([
            'destinations' => [['url' => 'example.com/hook', 'http_method' => 'POST']],
        ]));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('destinations.0.url', $validator->errors()->messages());
    }

    #[DataProvider('requestClasses')]
    public function test_valid_https_url_is_accepted(string $requestClass): void
    {
        $validator = $this->validate($requestClass, $this->validData([
            'destinations' => [['url' => 'https://example.com/hook', 'http_method' => 'POST']],
        ]));

        $this->assertArrayNotHasKey('destinations.0.url', $validator->errors()->messages());
    }

    #[DataProvider('requestClasses')]
    public function test_invalid_http_method_rejected_under_row_method_key(string $requestClass): void
    {
        $validator = $this->validate($requestClass, $this->validData([
            'destinations' => [['url' => 'https://example.com/hook', 'http_method' => 'DELETE']],
        ]));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('destinations.0.http_method', $validator->errors()->messages());
    }

    #[DataProvider('requestClasses')]
    public function test_missing_name_rejected_under_name_key(string $requestClass): void
    {
        $validator = $this->validate($requestClass, $this->validData(['name' => '']));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('name', $validator->errors()->messages());
    }

    #[DataProvider('requestClasses')]
    public function test_invalid_mode_rejected_under_mode_key(string $requestClass): void
    {
        $validator = $this->validate($requestClass, $this->validData(['mode' => 'turbo']));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('mode', $validator->errors()->messages());
    }

    /**
     * Values outside the fixed set {200, 202, 204} — including 2xx codes that are
     * no longer allowed (201, 203, 299) and non-2xx codes (AC4, refined 2026-08-04).
     *
     * @return array<string, array{0: int}>
     */
    public static function outOfSetStatuses(): array
    {
        return [
            'below range 199' => [199],
            'above range 300' => [300],
            'client error 404' => [404],
            '2xx not in set 201' => [201],
            '2xx not in set 203' => [203],
            '2xx not in set 299' => [299],
        ];
    }

    /**
     * @param  class-string  $requestClass
     */
    #[DataProvider('requestClasses')]
    public function test_response_status_null_or_absent_is_accepted(string $requestClass): void
    {
        // Explicit null.
        $validator = $this->validate($requestClass, $this->validData(['response_status' => null]));
        $this->assertArrayNotHasKey('response_status', $validator->errors()->messages());

        // Absent entirely.
        $validator = $this->validate($requestClass, $this->validData());
        $this->assertArrayNotHasKey('response_status', $validator->errors()->messages());
    }

    #[DataProvider('requestClasses')]
    public function test_out_of_set_response_status_is_rejected_under_response_status_key(string $requestClass): void
    {
        foreach (self::outOfSetStatuses() as [$status]) {
            $validator = $this->validate($requestClass, $this->validData(['response_status' => $status]));

            $this->assertTrue($validator->fails(), "Status {$status} should be rejected.");
            $this->assertArrayHasKey('response_status', $validator->errors()->messages());
        }
    }

    #[DataProvider('requestClasses')]
    public function test_allowed_statuses_200_202_204_are_accepted(string $requestClass): void
    {
        // 204 must pair with an empty body (asserted separately); pass no body here.
        foreach ([200, 202] as $status) {
            $validator = $this->validate($requestClass, $this->validData(['response_status' => $status]));
            $this->assertArrayNotHasKey('response_status', $validator->errors()->messages());
        }

        $validator = $this->validate($requestClass, $this->validData(['response_status' => 204]));
        $this->assertArrayNotHasKey('response_status', $validator->errors()->messages());
    }

    #[DataProvider('requestClasses')]
    public function test_204_with_non_empty_body_is_rejected_under_response_body_key(string $requestClass): void
    {
        $validator = $this->validate($requestClass, $this->validData([
            'response_status' => 204,
            'response_body' => 'not empty',
        ]));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('response_body', $validator->errors()->messages());
    }

    #[DataProvider('requestClasses')]
    public function test_204_with_empty_or_absent_body_is_accepted(string $requestClass): void
    {
        // Empty string body.
        $validator = $this->validate($requestClass, $this->validData([
            'response_status' => 204,
            'response_body' => '',
        ]));
        $this->assertArrayNotHasKey('response_body', $validator->errors()->messages());

        // Null body.
        $validator = $this->validate($requestClass, $this->validData([
            'response_status' => 204,
            'response_body' => null,
        ]));
        $this->assertArrayNotHasKey('response_body', $validator->errors()->messages());

        // Absent body.
        $validator = $this->validate($requestClass, $this->validData(['response_status' => 204]));
        $this->assertArrayNotHasKey('response_body', $validator->errors()->messages());
    }

    #[DataProvider('requestClasses')]
    public function test_200_with_non_empty_body_is_accepted(string $requestClass): void
    {
        // The 204-only body coupling must not reject bodies for 200/202.
        $validator = $this->validate($requestClass, $this->validData([
            'response_status' => 200,
            'response_body' => '{"ok":true}',
        ]));

        $this->assertArrayNotHasKey('response_body', $validator->errors()->messages());
    }

    #[DataProvider('requestClasses')]
    public function test_response_body_at_cap_is_accepted_and_over_cap_is_rejected(string $requestClass): void
    {
        $cap = (int) config('ingest.response_body_max_bytes');

        $atCap = $this->validate($requestClass, $this->validData(['response_body' => str_repeat('a', $cap)]));
        $this->assertArrayNotHasKey('response_body', $atCap->errors()->messages());

        $overCap = $this->validate($requestClass, $this->validData(['response_body' => str_repeat('a', $cap + 1)]));
        $this->assertTrue($overCap->fails());
        $this->assertArrayHasKey('response_body', $overCap->errors()->messages());
    }
}
