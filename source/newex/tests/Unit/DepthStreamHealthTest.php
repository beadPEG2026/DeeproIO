<?php
namespace Tests\Unit;
use App\Services\Market\DepthStreamHealth;
use PHPUnit\Framework\TestCase;
final class DepthStreamHealthTest extends TestCase
{
    public function test_connection_without_messages_expires(): void {
        $health=new DepthStreamHealth(100);$this->assertFalse($health->expired(130));$this->assertTrue($health->expired(131));
    }
    public function test_each_received_snapshot_renews_the_deadline(): void {
        $health=new DepthStreamHealth(100);$health->received(125);$this->assertFalse($health->expired(154));$this->assertTrue($health->expired(156));
    }
}
