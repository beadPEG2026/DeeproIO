<?php
namespace Tests;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
abstract class TestCase extends BaseTestCase
{
    private mixed $runnerExceptionHandler = null;
    protected function setUp(): void
    {
        $this->runnerExceptionHandler = set_exception_handler(static function (\Throwable $e): void {});
        restore_exception_handler();
        parent::setUp();
        config(['performance.cache_store'=>'array']);
        // The original app uses require_once for shared routes; PHPUnit boots
        // multiple applications in one process. Re-register only when absent.
        if (!\Illuminate\Support\Facades\Route::has('two-factor.pending-enable')) {
            require base_path('routes/jetstream.php');
        }
        if (!\Illuminate\Support\Facades\Route::has('stocks')) {
            \Illuminate\Support\Facades\Route::middleware('web')->group(base_path('routes/common.php'));
        }
        // Late route registration in repeated test boots must refresh named redirects too.
        \Illuminate\Support\Facades\Route::getRoutes()->refreshNameLookups();
        \Illuminate\Support\Facades\Route::getRoutes()->refreshActionLookups();
    }
    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            // Laravel 12 flushes the entire global exception-handler stack. Preserve
            // the runner's pre-test handler; leave any unexpected handler visible to PHPUnit.
            $current = set_exception_handler(static function (\Throwable $e): void {});
            restore_exception_handler();
            if ($current === null && $this->runnerExceptionHandler !== null) {
                set_exception_handler($this->runnerExceptionHandler);
            }
        }
    }
}
