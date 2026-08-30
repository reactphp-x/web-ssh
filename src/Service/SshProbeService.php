<?php

declare(strict_types=1);

namespace App\Service;

use App\Ssh\Ssh2Client;
use App\Ssh\SshErrorFormatter;
use App\Ssh\SshTarget;
use React\EventLoop\Loop as ReactLoop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Throwable;

final class SshProbeService
{
    /**
     * @return PromiseInterface<array{success: bool, message: string}>
     */
    public function test(SshTarget $target): PromiseInterface
    {
        $deferred = new Deferred();

        ReactLoop::addTimer(0.0, static function () use ($target, $deferred): void {
            try {
                Ssh2Client::probe($target);
                $deferred->resolve(['success' => true, 'message' => '连接成功']);
            } catch (Throwable $exception) {
                $formatted = SshErrorFormatter::format($exception, $target, false);
                $deferred->resolve(['success' => false, 'message' => $formatted['message']]);
            }
        });

        return $deferred->promise();
    }
}
