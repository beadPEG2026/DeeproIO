<?php

namespace Tests\Feature\Deepro;

use App\Services\Wallet\BitcoinRpcHealth;
use Illuminate\Support\Facades\{DB, Http};
use Tests\TestCase;

final class BitcoinRpcHealthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('deepro_test', DB::connection()->getDatabaseName());
        config(['bitcoind.default.quicknode' => 'https://chain.example.invalid/key', 'bitcoind.default.scheme' => 'http',
            'bitcoind.default.host' => 'wallet.example.invalid', 'bitcoind.default.port' => 8332,
            'bitcoind.default.user' => 'private-wallet-user', 'bitcoind.default.password' => 'private-wallet-password']);
        Http::swap(new \Illuminate\Http\Client\Factory);
    }

    private function chain(string $network = 'main', bool $synced = true): array
    {
        return ['chain' => $network, 'blocks' => 968000, 'initialblockdownload' => !$synced, 'verificationprogress' => $synced ? 1 : 0.4];
    }

    public function test_external_query_works_without_claiming_a_loaded_wallet_or_sending_wallet_credentials(): void
    {
        Http::fake(['https://chain.example.invalid/*' => Http::response(['result' => $this->chain()]), '*' => Http::response([], 503)]);
        $r = app(BitcoinRpcHealth::class)->inspect();
        $this->assertTrue($r['rpc']);$this->assertTrue($r['chain_synced']);
        $this->assertFalse($r['wallet_loaded']);$this->assertFalse($r['wallet_can_sign']);
        $this->assertSame('external_chain_query', $r['rpc_mode']);
        Http::assertSent(fn ($q) => str_starts_with($q->url(), 'https://chain.') && !$q->hasHeader('Authorization') && $q['method'] === 'getblockchaininfo');
        Http::assertNotSent(fn ($q) => str_starts_with($q->url(), 'https://chain.') && $q['method'] === 'getwalletinfo');
        $this->assertStringNotContainsString('example.invalid', json_encode($r));
    }

    public function test_synced_external_provider_cannot_hide_unsynced_private_wallet_node(): void
    {
        Http::fake(['*' => function ($q) {
            if ($q['method'] === 'getwalletinfo') return Http::response(['result' => ['walletname' => 'test', 'private_keys_enabled' => true]]);
            return Http::response(['result' => $this->chain('main', str_starts_with($q->url(), 'https://chain.'))]);
        }]);
        $r = app(BitcoinRpcHealth::class)->inspect();
        $this->assertTrue($r['chain_synced']);$this->assertFalse($r['wallet_chain_synced']);
        $this->assertTrue($r['wallet_loaded']);
        $row = collect(app(\App\Services\Wallet\WalletReadiness::class)->inspect()['chains'])->firstWhere('chain', 'bitcoin');
        $this->assertSame('needs_configuration', $row['status']);
        $this->assertContains(__('Bitcoin Core 主网区块尚未同步完成'), $row['issues']);
    }

    public function test_wrong_network_or_rpc_error_is_not_healthy(): void
    {
        foreach ([['result' => $this->chain('test')], ['result' => $this->chain(), 'error' => ['code' => -1]]] as $body) {
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::fake(['*' => Http::response($body)]);
            $r = app(BitcoinRpcHealth::class)->inspect();
            $this->assertFalse($r['rpc']);$this->assertFalse($r['chain_synced']);$this->assertNull($r['height']);
        }
    }

    public function test_existing_wallet_node_configuration_and_locked_wallet_are_preserved(): void
    {
        config(['bitcoind.default.quicknode' => '']);
        Http::fake(['*' => fn ($q) => Http::response(['result' => $q['method'] === 'getblockchaininfo' ? $this->chain()
            : ['walletname' => 'test', 'private_keys_enabled' => true, 'unlocked_until' => 0]])]);
        $r = app(BitcoinRpcHealth::class)->inspect();
        $this->assertSame('wallet_node', $r['rpc_mode']);$this->assertTrue($r['chain_synced']);
        $this->assertTrue($r['wallet_loaded']);$this->assertFalse($r['wallet_can_sign']);
        Http::assertSentCount(2);
    }

    public function test_unavailable_provider_does_not_expose_credential_bearing_error(): void
    {
        Http::fake(['*' => fn () => throw new \RuntimeException('https://chain.example.invalid/key')]);
        $r = app(BitcoinRpcHealth::class)->inspect();
        $this->assertFalse($r['rpc']);$this->assertFalse($r['wallet_rpc']);
        $this->assertStringNotContainsString('example.invalid', json_encode($r));
    }
}
