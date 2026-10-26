<?php declare(strict_types=1);

namespace KootLabs\TelegramBotDialogs\Tests;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Redis\Connection;
use KootLabs\TelegramBotDialogs\Dialog;
use KootLabs\TelegramBotDialogs\DialogManager;
use KootLabs\TelegramBotDialogs\Laravel\DialogsServiceProvider;
use KootLabs\TelegramBotDialogs\Laravel\Stores\RedisStoreAdapter;
use KootLabs\TelegramBotDialogs\Storages\Store;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Update;

/** @covers \KootLabs\TelegramBotDialogs\Laravel\DialogsServiceProvider
 * @covers \KootLabs\TelegramBotDialogs\Laravel\Stores\RedisStoreAdapter
 * @covers \KootLabs\TelegramBotDialogs\DialogManager
 * @covers \KootLabs\TelegramBotDialogs\Dialog
 */
final class LaravelIntegrationTest extends TestCase
{
    use CreatesUpdate;

    public function testProviderResolvesSingletonsAndPreservesConfiguredConnection(): void
    {
        $app = new class extends Container {
            public function runningInConsole(): bool { return false; }
        };
        $app->instance('config', new Repository([
            'telegramdialogs' => ['stores' => ['redis' => ['connection' => 'dialogs-test']]],
        ]));
        $connection = $this->createMock(Connection::class);
        $redis = new class ($connection) {
            public array $requested = [];
            private Connection $connection;
            public function __construct(Connection $connection) { $this->connection = $connection; }
            public function connection(string $name): Connection
            {
                $this->requested[] = $name;
                return $this->connection;
            }
        };
        $app->instance('redis', $redis);
        $app->instance('telegram.bot', $this->createMock(Api::class));
        $provider = new DialogsServiceProvider($app);
        $provider->register();

        $this->assertSame([], $redis->requested);
        $this->assertSame(['telegram.dialogs', DialogManager::class, Store::class], $provider->provides());
        $this->assertInstanceOf(RedisStoreAdapter::class, $app->make(Store::class));
        $this->assertSame($app->make(Store::class), $app->make(Store::class));
        $this->assertSame($app->make(DialogManager::class), $app->make('telegram.dialogs'));
        $this->assertSame(['dialogs-test'], $redis->requested);
    }

    public function testDialogSurvivesSerializedStorageAcrossRequestsAndCleansUpOnCompletion(): void
    {
        $values = [];
        $connection = $this->createMock(CallableRedisConnection::class);
        $connection->method('__call')->willReturnCallback(function (string $method, array $arguments) use (&$values) {
            [$key] = $arguments;
            switch ($method) {
                case 'setEx':
                    $this->assertSame('tg:dialog::42', $key);
                    $this->assertSame(300, $arguments[1]);
                    $values[$key] = $arguments[2];
                    return true;
                case 'exists': return isset($values[$key]);
                case 'get': return $values[$key] ?? null;
                case 'del': unset($values[$key]); return 1;
            }
            $this->fail('Unexpected Redis command: '.$method);
        });
        $store = new RedisStoreAdapter($connection);
        $bot = $this->createMock(Api::class);
        $bot->expects($this->never())->method('sendMessage');
        $manager = new DialogManager($bot, $store);
        $update = $this->buildTextMessageUpdate();
        $manager->activate(new StatefulTestDialog(42));
        $this->assertTrue($manager->exists($update));
        $manager->proceed($update);
        $restored = $store->get(42);
        $this->assertInstanceOf(StatefulTestDialog::class, $restored);
        $this->assertFalse($restored->isEnd());
        $this->assertSame($update->getMessage()->text, $restored->__serialize()['memory']['text']);
        $this->assertSame(1, $restored->__serialize()['next']);
        // A new manager represents the next webhook request, with a fresh bot binding.
        $manager = new DialogManager($bot, $store);
        $manager->proceed($update);
        $this->assertFalse($manager->exists($update));
        $this->assertSame([], $values);
        $manager->proceed($update);
        $this->assertSame([], $values);
    }
}

final class StatefulTestDialog extends Dialog
{
    protected array $steps = ['rememberText', 'complete'];

    public function rememberText(Update $update): void
    {
        $this->remember('text', $update->getMessage()->text);
    }

    public function complete(Update $update): void
    {
        \PHPUnit\Framework\Assert::assertSame($update->getMessage()->text, $this->memory['text']);
        \PHPUnit\Framework\Assert::assertInstanceOf(Api::class, $this->bot);
    }
}

interface CallableRedisConnection extends Connection
{
    public function __call($method, $parameters);
}
