<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * bin/console app:push:test (spec §5.11, §11 décision 70): what an
 * operator reads when an account gets no push.
 */
final class PushTestCommandTest extends AuthTestCase
{
    use ApiRequestTrait;
    use NotificationTestTrait;

    public function testExplainsWhyAnAccountGetsNoPush(): void
    {
        $token = $this->registerVerifyAndLogin('ptc-lea@example.com');

        $tester = $this->runCommand('ptc-lea@example.com');
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('NOT configured', $display, 'no VAPID key in the test env');
        self::assertStringContainsString('Push consent: NOT granted', $display);
        self::assertStringContainsString('No device registered', $display);

        $this->consentToEverything($token);
        $this->expect(201, $token, 'POST', '/api/device-tokens', ['token' => 'https://fcm.googleapis.com/fcm/send/lea', 'platform' => 'web', 'keys' => ['p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM', 'auth' => 'tBHItJI5svbpez7KI4CCXg']]);

        $tester = $this->runCommand('ptc-lea@example.com');
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('Push consent: granted on', $display);
        self::assertStringContainsString('fcm.googleapis.com', $display);
        self::assertStringContainsString('not sent: no VAPID key', $display);

        self::assertSame(Command::FAILURE, $this->runCommand('nobody@example.com')->getStatusCode());
    }

    private function runCommand(string $email): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('app:push:test'));
        $tester->execute(['email' => $email]);

        return $tester;
    }
}
