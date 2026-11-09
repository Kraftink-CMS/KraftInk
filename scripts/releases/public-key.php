<?php

declare(strict_types=1);

require_once __DIR__.'/configuration.php';

$publicKey = KraftReleaseConfiguration::signingKey(dirname(__DIR__, 2));
$encoded = getenv('KRAFTINK_CMS_SIGNING_PRIVATE_KEY');
if (is_string($encoded) && trim($encoded) !== '') {
    $secret = base64_decode(trim($encoded), true);
    if (!extension_loaded('sodium') || !is_string($secret) || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        throw new RuntimeException('Configure a valid Ed25519 signing key before publishing.');
    }
    $matches = hash_equals($publicKey, base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret)));
    sodium_memzero($secret);
    if (!$matches) {
        throw new RuntimeException('The signing key does not match the source signing key registry.');
    }
}
fwrite(STDOUT, $publicKey.PHP_EOL);
