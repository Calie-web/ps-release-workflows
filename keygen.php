<?php
/**
 * Generates an Ed25519 signing key pair for releases. Run once, offline.
 *  - PUBLIC  -> paste into each shop's TPU_TRUSTED_KEYS (updater config)
 *  - SECRET  -> store as the GitHub Actions secret TPU_SIGNING_KEY
 * Keep the SECRET out of every repository.
 */

declare(strict_types=1);

$pair = sodium_crypto_sign_keypair();
echo "PUBLIC  (TPU_TRUSTED_KEYS): " . base64_encode(sodium_crypto_sign_publickey($pair)) . "\n";
echo "SECRET  (TPU_SIGNING_KEY) : " . base64_encode(sodium_crypto_sign_secretkey($pair)) . "\n";
