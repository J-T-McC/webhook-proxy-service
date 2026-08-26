<?php

namespace Tests\Unit\Enums;

use App\Enums\SeriesBucket;
use Tests\TestCase;

class SeriesBucketTest extends TestCase
{
    public function test_it_has_exactly_the_two_documented_cases(): void
    {
        $this->assertSame(
            ['hour', 'day'],
            array_map(fn (SeriesBucket $c) => $c->value, SeriesBucket::cases()),
        );
    }

    /**
     * Nothing outside `AnalyticsWindow::bucket()` may construct a
     * `SeriesBucket` from a window value (plan-11 Technical ruling 11) — a
     * tokenized-source check mirroring
     * `DeliveryStatisticsScopingTest::test_the_service_never_reads_the_mode_or_processing_mode_columns()`'s
     * technique: strip comments/doc-blocks so only executable code is
     * checked, then assert the construction calls (`::from()`/`::tryFrom()`)
     * are absent — a plain grep could false-positive on a doc-comment
     * mentioning `SeriesBucket::from()` in prose, which this avoids.
     */
    public function test_no_code_outside_analytics_window_constructs_a_series_bucket_from_a_value(): void
    {
        $allowedFile = app_path('Enums/AnalyticsWindow.php');

        foreach ($this->appPhpFiles() as $file) {
            if ($file === $allowedFile) {
                continue;
            }

            $codeOnly = $this->stripComments((string) file_get_contents($file));

            $this->assertStringNotContainsString('SeriesBucket::from(', $codeOnly, "{$file} constructs a SeriesBucket via ::from()");
            $this->assertStringNotContainsString('SeriesBucket::tryFrom(', $codeOnly, "{$file} constructs a SeriesBucket via ::tryFrom()");
        }
    }

    private function stripComments(string $source): string
    {
        $codeOnly = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $codeOnly .= is_array($token) ? $token[1] : $token;
        }

        return $codeOnly;
    }

    /**
     * @return list<string>
     */
    private function appPhpFiles(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
