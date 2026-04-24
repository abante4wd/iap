<?php

namespace Abante4wd\Iap\Store;

final class AppleJwsVerifier
{
    /**
     * Apple Root CA - G3 の PEM 証明書。
     *
     * StoreKit 2 の JWS 署名チェーン検証に使用する固定のルート証明書。
     * すべてのアプリ・開発者アカウントで共通であり、外部ファイルや設定は不要。
     */
    private const APPLE_ROOT_CA_G3 = <<<'PEM'
-----BEGIN CERTIFICATE-----
MIICQzCCAcmgAwIBAgIILcX8iNLFS5UwCgYIKoZIzj0EAwMwZzEbMBkGA1UEAwwS
QXBwbGUgUm9vdCBDQSAtIEczMSYwJAYDVQQLDB1BcHBsZSBDZXJ0aWZpY2F0aW9u
IEF1dGhvcml0eTETMBEGA1UECgwKQXBwbGUgSW5jLjELMAkGA1UEBhMCVVMwHhcN
MTQwNDMwMTgxOTA2WhcNMzkwNDMwMTgxOTA2WjBnMRswGQYDVQQDDBJBcHBsZSBS
b290IENBIC0gRzMxJjAkBgNVBAsMHUFwcGxlIENlcnRpZmljYXRpb24gQXV0aG9y
aXR5MRMwEQYDVQQKDApBcHBsZSBJbmMuMQswCQYDVQQGEwJVUzB2MBAGByqGSM49
AgEGBSuBBAAiA2IABJjpLz1AcqTtkyJygRMc3RCV8cWjTnHcFBbZDuWmBSp3ZHtf
TjjTuxxEtX/1H7YyYl3J6YRbTzBPEVoA/VhYDKX1DyxNB0cTddqXl5dvMVztK517
IDvYuVTZXpmkOlEKMaNCMEAwHQYDVR0OBBYEFLuw3qFYM4iapIqZ3r6966/ayySr
MA8GA1UdEwEB/wQFMAMBAf8wDgYDVR0PAQH/BAQDAgEGMAoGCCqGSM49BAMDA2gA
MGUCMQCD6cHEFl4aXTQY2e3v9GwOAEZLuN+yRhHFD/3meoyhpmvOwgPUnPWTxnS4
at+qIxUCMG1mihDK1A3UT82NQz60imOlM27jbdoXt2QfyFMm+YhidDkLF1vLUagM
6BgD56KyKA==
-----END CERTIFICATE-----
PEM;

    /**
     * JWS を検証してペイロードを返す。
     * Apple Root CA-G3 による証明書チェーン検証と ECDSA-SHA256 署名検証を行う。
     *
     * @throws \RuntimeException 検証失敗時
     */
    public function verify(string $jws): array
    {
        $parts = explode('.', $jws);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Invalid JWS format');
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $header = json_decode(base64_decode(strtr($headerB64, '-_', '+/')), true);
        if (
            ! is_array($header)
            || ! isset($header['x5c'])
            || ! is_array($header['x5c'])
            || count($header['x5c']) < 3
        ) {
            throw new \RuntimeException('Invalid certificate chain in JWS header');
        }

        $certs = array_map(
            fn(string $base64Der) => "-----BEGIN CERTIFICATE-----\n" . chunk_split($base64Der, 64, "\n") . "-----END CERTIFICATE-----",
            $header['x5c']
        );

        $rootFingerprint = openssl_x509_fingerprint($certs[2], 'sha256');
        $appleRootFingerprint = openssl_x509_fingerprint(self::APPLE_ROOT_CA_G3, 'sha256');
        if ($rootFingerprint !== $appleRootFingerprint) {
            throw new \RuntimeException('Certificate chain root does not match Apple Root CA');
        }

        $rootKey = openssl_pkey_get_public($certs[2]);
        if ($rootKey === false) {
            throw new \RuntimeException('Failed to extract public key from root certificate');
        }
        $intermediateKey = openssl_pkey_get_public($certs[1]);
        if ($intermediateKey === false) {
            throw new \RuntimeException('Failed to extract public key from intermediate certificate');
        }

        if (openssl_x509_verify($certs[1], $rootKey) !== 1) {
            throw new \RuntimeException('Invalid certificate chain: intermediate not signed by root');
        }
        if (openssl_x509_verify($certs[0], $intermediateKey) !== 1) {
            throw new \RuntimeException('Invalid certificate chain: leaf not signed by intermediate');
        }

        $signingInput = $headerB64 . '.' . $payloadB64;
        $rawSignature = base64_decode(strtr($signatureB64, '-_', '+/'));
        $derSignature = $this->convertEcSignatureToDer($rawSignature);

        $leafKey = openssl_pkey_get_public($certs[0]);
        if ($leafKey === false) {
            throw new \RuntimeException('Failed to extract public key from leaf certificate');
        }
        if (openssl_verify($signingInput, $derSignature, $leafKey, OPENSSL_ALGO_SHA256) !== 1) {
            throw new \RuntimeException('JWS signature verification failed');
        }

        $payload = json_decode(base64_decode(strtr($payloadB64, '-_', '+/')), true);
        if (! is_array($payload)) {
            throw new \RuntimeException('Failed to decode JWS payload');
        }

        return $payload;
    }

    private function convertEcSignatureToDer(string $signature): string
    {
        if (strlen($signature) !== 64) {
            throw new \InvalidArgumentException(
                'Invalid ECDSA signature length: expected 64 bytes for ES256, got ' . strlen($signature)
            );
        }

        $len = 32;
        $r = ltrim(substr($signature, 0, $len), "\x00");
        $s = ltrim(substr($signature, $len), "\x00");

        if ($r === '' || ord($r[0]) >= 0x80) {
            $r = "\x00" . $r;
        }
        if ($s === '' || ord($s[0]) >= 0x80) {
            $s = "\x00" . $s;
        }

        $rLen = strlen($r);
        $sLen = strlen($s);

        return "\x30" . chr($rLen + $sLen + 4) . "\x02" . chr($rLen) . $r . "\x02" . chr($sLen) . $s;
    }
}
