<?php
namespace App\Services\Market;

/** Monitor delivery only; never change source mapping, prices or execution rules. */
final class DepthStreamHealth
{
    private float $lastReceived;
    public function __construct(float $started) { $this->lastReceived = $started; }
    public function received(float $at): void { $this->lastReceived = $at; }
    public function expired(float $now): bool { return $now - $this->lastReceived > 30; }
}
