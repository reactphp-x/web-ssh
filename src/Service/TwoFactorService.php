<?php

declare(strict_types=1);

namespace App\Service;

use RobThree\Auth\Providers\Qr\BaconQrCodeProvider;
use RobThree\Auth\TwoFactorAuth;

final class TwoFactorService
{
    private readonly TwoFactorAuth $tfa;

    public function __construct(string $issuer = 'Web SSH')
    {
        $this->tfa = new TwoFactorAuth(new BaconQrCodeProvider(format: 'svg'), $issuer);
    }

    public function createSecret(): string
    {
        return $this->tfa->createSecret();
    }

    public function verifyCode(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', trim($code)) ?? '';

        return $code !== '' && $this->tfa->verifyCode($secret, $code);
    }

    public function qrCodeDataUri(string $label, string $secret): string
    {
        return $this->tfa->getQRCodeImageAsDataUri($label, $secret, 220);
    }
}
