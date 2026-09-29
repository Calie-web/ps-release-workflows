<?php
/**
 * Signs a file with Ed25519. Usage: php sign.php <file> <outSig>
 * Env: TPU_SIGNING_KEY (base64 Ed25519 secret key) — provided as a GitHub secret.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

[$self, $file, $out] = array_pad($argv, 3, null);
if ($file === null || $out === null) {
    fwrite(STDERR, "Usage: php sign.php <file> <outSig>\n");
    exit(2);
}
$key = getenv('TPU_SIGNING_KEY');
if (!is_string($key) || $key === '') {
    fwrite(STDERR, "TPU_SIGNING_KEY is not set.\n");
    exit(2);
}

try {
    $sig = tpu_sign((string) file_get_contents($file), $key);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
file_put_contents($out, $sig . "\n");
fwrite(STDOUT, "Signed -> $out\n");
exit(0);
