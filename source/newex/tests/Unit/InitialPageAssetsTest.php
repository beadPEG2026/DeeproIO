<?php

namespace Tests\Unit;

use App\Support\InitialPageAssets;
use PHPUnit\Framework\TestCase;

final class InitialPageAssetsTest extends TestCase
{
    private string $root;
    private string $chunk = '/frontend/js/chunks/page-Home-Home-vue.123456abcdef.js';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/deepro-preloads-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/frontend/js/chunks', 0700, true);
        file_put_contents($this->root . '/frontend/js/app.js', 'compiled entry');
        file_put_contents($this->root . $this->chunk, 'compiled page');
        $this->manifest();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/frontend/js/chunks/*') as $path) unlink($path);
        unlink($this->root . '/frontend/js/app.js');
        if (is_file($this->root . '/frontend/page-preloads.json')) unlink($this->root . '/frontend/page-preloads.json');
        rmdir($this->root . '/frontend/js/chunks'); rmdir($this->root . '/frontend/js');
        rmdir($this->root . '/frontend'); rmdir($this->root);
    }

    private function manifest(?array $files = null, ?string $digest = null): void
    {
        file_put_contents($this->root . '/frontend/page-preloads.json', json_encode([
            'version' => 1, 'entry_sha256' => $digest ?? hash('sha256', 'compiled entry'),
            'pages' => ['Home/Home' => $files ?? [$this->chunk]],
        ]));
    }

    public function test_only_the_current_page_is_preloaded(): void
    {
        $this->assertSame([$this->chunk], InitialPageAssets::scripts('Home/Home', $this->root));
        $this->assertSame([], InitialPageAssets::scripts('Auth/Login', $this->root));
        $this->assertSame([], InitialPageAssets::scripts('Market/Market', $this->root));
    }

    public function test_rolling_deploy_mismatch_or_missing_metadata_falls_back_without_breaking_page(): void
    {
        file_put_contents($this->root . '/frontend/js/app.js', 'next entry');
        $this->assertSame([], InitialPageAssets::scripts('Home/Home', $this->root));
        file_put_contents($this->root . '/frontend/page-preloads.json', '{bad');
        $this->assertSame([], InitialPageAssets::scripts('Home/Home', $this->root));
        unlink($this->root . '/frontend/page-preloads.json');
        $this->assertSame([], InitialPageAssets::scripts('Home/Home', $this->root));
    }

    public function test_unsafe_missing_or_excessive_references_are_never_emitted(): void
    {
        foreach ([['//other.example/code.js'], ['/frontend/js/chunks/../app.js'], ['/frontend/js/chunks/missing.123456abcdef.js'], array_fill(0, 7, $this->chunk)] as $files) {
            $this->manifest($files);
            $this->assertSame([], InitialPageAssets::scripts('Home/Home', $this->root));
        }
        $this->manifest(); unlink($this->root . $this->chunk);
        symlink($this->root . '/frontend/js/app.js', $this->root . $this->chunk);
        $this->assertSame([], InitialPageAssets::scripts('Home/Home', $this->root));
    }

    public function test_io_failures_in_optional_hint_cannot_break_page_rendering(): void
    {
        $path = $this->root . '/frontend/page-preloads.json';
        chmod($path, 0000);
        if (is_readable($path)) {
            chmod($path, 0600);
            $this->markTestSkipped('Privileged process can read mode 0000; non-root acceptance covers this branch.');
        }
        set_error_handler(static function ($severity, $message, $file, $line): never {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            $this->assertSame([], InitialPageAssets::scripts('Home/Home', $this->root));
        } finally {
            restore_error_handler(); chmod($path, 0600);
        }
    }
}
