<?php

declare(strict_types=1);

/*
 * Development only: prints a new Ed25519 keypair for signing releases.
 * The public half goes in app/release-key.pub; the secret half is the
 * RELEASE_SIGNING_KEY secret the release build reads. Never commit it.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$pair = sodium_crypto_sign_keypair();
echo 'public (app/release-key.pub): ' . base64_encode(sodium_crypto_sign_publickey($pair)) . "\n";
echo 'secret (RELEASE_SIGNING_KEY): ' . base64_encode(sodium_crypto_sign_secretkey($pair)) . "\n";
