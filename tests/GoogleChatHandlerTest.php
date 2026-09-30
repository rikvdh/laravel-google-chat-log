<?php

declare(strict_types=1);

namespace Enigma\Tests;

use Enigma\GoogleChatHandler;
use Enigma\Providers\GoogleChatServiceProvider;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Monolog\Handler\Curl\Util;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

use function Enigma\container;

final class GoogleChatHandlerTest extends TestCase
{
    private ConfigRepository $config;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-11 14:32:18');
        $app = container();
        $app->instance('cache', new CacheRepository(new ArrayStore()));
        $this->config = new ConfigRepository(['app' => ['name' => 'FixPart']]);
        $app->instance('config', $this->config);

        (new GoogleChatServiceProvider($app))->register();

        Container::setInstance($app);
        Facade::setFacadeApplication($app);
        Util::$executions = [];
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testRepeatedErrorsAreSentToTheSameThreadWithATimestamp(): void
    {
        $handler = new GoogleChatHandler('https://example.test/webhook?key=test');
        $record = $this->createRecord();

        $handler->handle($record);
        $handler->handle($record);

        self::assertCount(2, Util::$executions);

        $firstRequest = Util::$executions[0];
        $secondRequest = Util::$executions[1];
        $firstPayload = json_decode($firstRequest[CURLOPT_POSTFIELDS], true, flags: JSON_THROW_ON_ERROR);
        $secondPayload = json_decode($secondRequest[CURLOPT_POSTFIELDS], true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(
            '*AppName : Error:* payment status missing whilst waiting for shipped status with klarna',
            $firstPayload['text'],
        );
        self::assertArrayHasKey('cardsV2', $firstPayload);
        self::assertMatchesRegularExpression(
            '/^Occurred again at \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $secondPayload['text'],
        );
        self::assertSame($firstPayload['thread'], $secondPayload['thread']);
        self::assertStringContainsString(
            'messageReplyOption=REPLY_MESSAGE_FALLBACK_TO_NEW_THREAD',
            $firstRequest[CURLOPT_URL],
        );
    }

    public function testErrorStartsANewThreadAfterOneHour(): void
    {
        $handler = new GoogleChatHandler('https://example.test/webhook?key=test');
        $record = $this->createRecord();

        $handler->handle($record);
        Carbon::setTestNow(Carbon::now()->addHour()->addSecond());
        $handler->handle($record);

        self::assertCount(2, Util::$executions);

        $firstPayload = json_decode(
            Util::$executions[0][CURLOPT_POSTFIELDS],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $secondPayload = json_decode(
            Util::$executions[1][CURLOPT_POSTFIELDS],
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame($firstPayload['text'], $secondPayload['text']);
        self::assertArrayHasKey('cardsV2', $secondPayload);
        self::assertNotSame($firstPayload['thread'], $secondPayload['thread']);
    }

    public function testFullErrorIsSentToTheSameThreadWhenCacheIsUnavailable(): void
    {
        Container::getInstance()->forgetInstance('cache');
        Facade::clearResolvedInstance('cache');

        $handler = new GoogleChatHandler('https://example.test/webhook?key=test');
        $record = $this->createRecord();

        $handler->handle($record);
        $handler->handle($record);

        self::assertCount(2, Util::$executions);

        $firstPayload = json_decode(
            Util::$executions[0][CURLOPT_POSTFIELDS],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $secondPayload = json_decode(
            Util::$executions[1][CURLOPT_POSTFIELDS],
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame($firstPayload['text'], $secondPayload['text']);
        self::assertArrayHasKey('cardsV2', $firstPayload);
        self::assertArrayHasKey('cardsV2', $secondPayload);
        self::assertSame($firstPayload['thread'], $secondPayload['thread']);
    }

    public function testFullErrorIsSentWhenCachingIsDisabled(): void
    {
        $this->config->set('google-chat.cache.enabled', false);

        $handler = new GoogleChatHandler('https://example.test/webhook?key=test');
        $record = $this->createRecord();

        $handler->handle($record);
        $handler->handle($record);

        self::assertCount(2, Util::$executions);

        $firstPayload = json_decode(
            Util::$executions[0][CURLOPT_POSTFIELDS],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $secondPayload = json_decode(
            Util::$executions[1][CURLOPT_POSTFIELDS],
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame($firstPayload['text'], $secondPayload['text']);
        self::assertArrayHasKey('cardsV2', $firstPayload);
        self::assertArrayHasKey('cardsV2', $secondPayload);
        self::assertSame($firstPayload['thread'], $secondPayload['thread']);
    }

    private function createRecord(): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable('2026-09-11 14:32:18'),
            channel: 'production',
            level: Level::Error,
            message: 'payment status missing whilst waiting for shipped status with klarna',
            context: [
                'order_id' => 123123123,
                'status' => 'SENT',
                'invoice' => 230123123123,
                'payment_method' => 'KLARNA',
                'total_amount' => 69.67,
                'paid_amount' => 69.67,
                'currency' => 'EUR',
            ],
            extra: [
                'hostname' => 'srv01.fixpart.com',
                'command' => 'klarna:shipments',
            ],
        );
    }
}
