<?php
namespace App\Services\Wallet;

final class XLayerAddress
{
    /** XKO is an official display prefix for the same 20-byte EVM address. */
    public static function normalize(string $address): string
    {
        $address = trim($address);
        return preg_match('/^xko([0-9a-f]{40})$/iD', $address, $matches) ? '0x'.$matches[1] : $address;
    }
}
