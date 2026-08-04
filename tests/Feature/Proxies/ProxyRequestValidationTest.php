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
     * @return array<string, array{0: int}>
     */
    public static function nonTwoXxStatuses(): array
    {
        return [
            'below range 199' => [199],
            'above range 300' => [300],
            'client error 404' => [404],
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
    public function test_non_2xx_response_status_is_rejected_under_response_status_key_on_store(string $requestClass): void
    {
        foreach (self::nonTwoXxStatuses() as [$status]) {
            $validator = $this->validate($requestClass, $this->validData(['response_status' => $status]));

            $this->assertTrue($validator->fails(), "Status {$status} should be rejected.");
            $this->assertArrayHasKey('response_status', $validator->errors()->messages());
        }
    }

    #[DataProvider('requestClasses')]
    public function test_2xx_boundary_statuses_200_and_299_are_accepted(string $requestClass): void
    {
        foreach ([200, 299] as $status) {
            $validator = $this->validate($requestClass, $this->validData(['response_status' => $status]));

            $this->assertArrayNotHasKey('response_status', $validator->errors()->messages());
        }
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
