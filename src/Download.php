<?php
declare(strict_types=1);

function sendObjectBytes($stream, int $remaining): void
{
    // Some PHP output handlers allocate one buffer per write.
    while ($remaining > 0) {
        $chunk = fread($stream, min(262144, $remaining));
        if ($chunk === false || $chunk === '') {
            throw new RuntimeException('Incomplete object transfer.');
        }
        echo $chunk;
        $remaining -= strlen($chunk);
    }
}
