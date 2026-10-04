<?php
namespace Tests\Feature\Deepro;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class UmiRouteManifestTest extends TestCase
{
    public function test_both_frontend_manifests_contain_the_current_umi_route_contract(): void
    {
        if (!Route::has('admin.umi.continuity')) {
            Route::middleware('web')->group(base_path('routes/admin.php'));
        }
        if (!Route::has('umi.finance.preview')) Route::middleware('web')->group(base_path('routes/common.php'));
        Route::getRoutes()->refreshNameLookups();
        $expected = array_filter((new \Tightenco\Ziggy\Ziggy)->toArray()['routes'],
            fn ($name) => str_starts_with($name, 'umi.') || str_starts_with($name, 'admin.umi'), ARRAY_FILTER_USE_KEY);
        $this->assertArrayHasKey('admin.umi.continuity', $expected);
        $this->assertArrayHasKey('umi.finance.preview', $expected);
        $this->assertArrayHasKey('admin.umi.business.preview', $expected);
        foreach (['ziggy.js', 'ziggy_alternative.js'] as $file) {
            preg_match('/const Ziggy = (.*?);/s', file_get_contents(resource_path('js/'.$file)), $match);
            $actual = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR)['routes'];
            foreach ($expected as $name => $definition) {
                $this->assertArrayHasKey($name, $actual, $file.' missing '.$name);
                $this->assertSame($definition, $actual[$name], $file.' stale '.$name);
            }
        }
    }
}
