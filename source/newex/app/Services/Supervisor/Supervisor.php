<?php

namespace App\Services\Supervisor;

use JalalLinuX\Pm2\Pm2;

class Supervisor extends Pm2 {

    public function start(string $command = null, array $options = []): bool
    {
        $options = $this->makeOptions($options);

        return ! is_null($this->runCommand('start'.(! is_null($command) ? " \"{$command}\" {$options}" : '')));
    }
}
