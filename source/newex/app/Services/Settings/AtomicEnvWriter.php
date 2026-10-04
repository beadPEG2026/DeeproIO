<?php

namespace App\Services\Settings;

use Dotenv\Dotenv;
use RuntimeException;
use InvalidArgumentException;

/** Writes complete dotenv batches without altering secret characters. */
final class AtomicEnvWriter
{
    public function update(array $values, ?string $path = null): void
    {
        if (!$values) return;
        $path ??= app()->environmentFilePath();
        if (is_link($path) || !is_file($path)) throw new RuntimeException('Environment file is unavailable.');
        foreach ($values as $key => $value) {
            if (!preg_match('/^[A-Z_][A-Z0-9_]*$/D', $key) || !is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException('Invalid environment setting.');
            }
            if (preg_match('/[\x00-\x1f\x7f]/', (string) $value)) throw new InvalidArgumentException('Control characters are not allowed.');
        }
        if (is_link($path . '.lock')) throw new RuntimeException('Invalid environment lock file.');
        clearstatcache(true, $path);
        $metadata = stat($path);
        if (!$metadata) throw new RuntimeException('Cannot inspect environment ownership.');
        $lock = fopen($path . '.lock', 'c');
        if (!$lock) throw new RuntimeException('Cannot lock environment settings.');
        chmod($path . '.lock', 0600);
        if ((fileowner($path . '.lock') !== $metadata['uid'] && !chown($path . '.lock', $metadata['uid'])) || (filegroup($path . '.lock') !== $metadata['gid'] && !chgrp($path . '.lock', $metadata['gid']))) { fclose($lock); throw new RuntimeException('Cannot preserve environment lock ownership.'); }
        $temp = null;
        try {
            if (!flock($lock, LOCK_EX)) throw new RuntimeException('Cannot lock environment settings.');
            $text = file_get_contents($path);
            if ($text === false) throw new RuntimeException('Cannot read environment settings.');
            $newline = str_contains($text, "\r\n") ? "\r\n" : "\n";
            foreach ($values as $key => $value) {
                $value = $value === null ? '' : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
                $encoded = '"' . strtr($value, ['\\' => '\\\\', '"' => '\\"', '$' => '\\$']) . '"';
                $line = $key . '=' . $encoded;
                // Validate using the same dotenv parser as the application before any write.
                if ((Dotenv::parse($line)[$key] ?? null) !== $value) throw new InvalidArgumentException('Environment value cannot be represented safely.');
                $pattern = '/^[\t ]*(?:export[\t ]+)?' . preg_quote($key, '/') . '[\t ]*=.*$/m';
                $count = preg_match_all($pattern, $text);
                if ($count > 1) throw new RuntimeException('Duplicate environment setting: ' . $key);
                $text = $count ? preg_replace_callback($pattern, fn () => $line . ($newline === "\r\n" ? "\r" : ''), $text)
                    : rtrim($text, "\r\n") . $newline . $line . $newline;
            }
            Dotenv::parse($text);
            $temp = tempnam(dirname($path), '.env-write-');
            if ($temp === false || !chmod($temp, fileperms($path) & 0777)) throw new RuntimeException('Cannot prepare environment settings.');
            // A privileged release must not replace www-owned dotenv with a root-only file.
            if ((fileowner($temp) !== $metadata['uid'] && !chown($temp, $metadata['uid'])) || (filegroup($temp) !== $metadata['gid'] && !chgrp($temp, $metadata['gid']))) throw new RuntimeException('Cannot preserve environment ownership.');
            $handle = fopen($temp, 'wb');
            if (!$handle) throw new RuntimeException('Cannot write environment settings.');
            try {
                if (fwrite($handle, $text) !== strlen($text) || !fflush($handle)) throw new RuntimeException('Incomplete environment write.');
                if (function_exists('fsync')) fsync($handle);
            } finally { fclose($handle); }
            if (!rename($temp, $path)) throw new RuntimeException('Cannot replace environment settings.');
            $temp = null;
        } finally {
            if ($temp !== null && is_file($temp)) unlink($temp);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
