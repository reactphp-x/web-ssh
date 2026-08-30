<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\TwoFactorService;
use PHPUnit\Framework\TestCase;

final class TwoFactorServiceTest extends TestCase
{
    public function testCreatesAndVerifiesTotpCode(): void
    {
        $service = new TwoFactorService('Web SSH Test');
        $secret = $service->createSecret();

        self::assertNotSame('', $secret);

        $tfa = new \RobThree\Auth\TwoFactorAuth(new \RobThree\Auth\Providers\Qr\BaconQrCodeProvider(format: 'svg'), 'Web SSH Test');
        $code = $tfa->getCode($secret);

        self::assertTrue($service->verifyCode($secret, $code));
        self::assertFalse($service->verifyCode($secret, '000000'));
    }

    public function testGeneratesQrCodeDataUri(): void
    {
        $service = new TwoFactorService('Web SSH Test');
        $secret = $service->createSecret();
        $dataUri = $service->qrCodeDataUri('admin:Phone', $secret);

        self::assertStringStartsWith('data:image/svg+xml;base64,', $dataUri);
    }
}
