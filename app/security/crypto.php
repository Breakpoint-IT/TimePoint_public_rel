<?php

function tpEncrypt(string $data): string
{
    $ivLength = openssl_cipher_iv_length(ENCRYPTION_METHOD);
    $iv = openssl_random_pseudo_bytes($ivLength);
    $encrypted = openssl_encrypt($data, ENCRYPTION_METHOD, ENCRYPTION_KEY, 0, $iv);

    return base64_encode($encrypted . '::' . $iv);
}

function tpDecrypt(?string $data): string
{
    if (!$data) {
        return '';
    }

    $decoded = base64_decode($data, true);
    if ($decoded === false || !str_contains($decoded, '::')) {
        return '';
    }

    [$encryptedData, $iv] = explode('::', $decoded, 2);
    $decrypted = openssl_decrypt($encryptedData, ENCRYPTION_METHOD, ENCRYPTION_KEY, 0, $iv);

    return $decrypted === false ? '' : $decrypted;
}
