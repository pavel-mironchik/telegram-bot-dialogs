[![CI](https://github.com/pavel-mironchik/telegram-bot-dialogs/actions/workflows/ci.yml/badge.svg)](https://github.com/pavel-mironchik/telegram-bot-dialogs/actions/workflows/ci.yml)
[![Backward compatibility check](https://github.com/pavel-mironchik/telegram-bot-dialogs/actions/workflows/backward-compatibility-check.yml/badge.svg)](https://github.com/pavel-mironchik/telegram-bot-dialogs/actions/workflows/backward-compatibility-check.yml)

<p align="center"><img src="https://user-images.githubusercontent.com/5278175/176997422-79e5c4c1-ff43-438e-b30e-651bb8e17bcf.png" alt="Dialogs" width="400"></p>

# Dialogs plugin for Telegram Bot API PHP SDK

The extension for [Telegram Bot API PHP SDK](https://github.com/irazasyed/telegram-bot-sdk) v3.1+ that allows to implement dialogs for telegram bots.


## About this fork

This is the FlashAsia maintenance fork of `koot-labs/telegram-bot-dialogs`, based on
upstream 0.5.2 and the Laravel 10 maintenance branch. It preserves the legacy
`DialogManager::proceed()`, `Dialog::end()`, `jump()` and `remember()` APIs and
serialized Redis state used by FlashAsia.

**v0.6.0 is a release of this fork**, not the upstream release with the same number.
The upstream 1.x API is a separate line and is not a drop-in replacement for this
legacy API. The Composer package name and PHP namespace remain unchanged so
existing consumers can select this fork through a VCS repository.

This release adds Illuminate 11 support and an explicitly nullable constructor
argument for PHP 8.4. CI exercises Illuminate 10/11 on PHP 8.3/8.4 with provider,
container, configuration and serialized state tests. These component tests do not
replace application integration tests or test against live Telegram/Redis.

## Installation

Add the fork repository to your application's root `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/pavel-mironchik/telegram-bot-dialogs"
        }
    ],
    "require": {
        "koot-labs/telegram-bot-dialogs": "0.6.0"
    }
}
```

Then run `composer update koot-labs/telegram-bot-dialogs`. Keep the resulting lock
file and verify its source URL points to this fork. The explicit root VCS
repository is required to select this release rather than the upstream package
on Packagist. Composer infers the version from the real `v0.6.0` Git tag.

Package requires PHP >= 8.0. The new compatibility matrix covers PHP 8.3/8.4;
see CI for the inherited older-runtime checks.


## Usage

1. Create a Dialog class
2. [Create a Telegram command](https://telegram-bot-sdk.readme.io/docs/commands-system) and start a Dialog from `Command::handle()`.
3. Setup your controller class to proceed active Dialog on income webhook request.


### 1. Create a Dialog class

Each dialog should be implemented as class that extends basic `Dialog` as you can see in [HelloExampleDialog](https://github.com/pavel-mironchik/telegram-bot-dialogs/blob/v0.6.0/src/Dialogs/HelloExampleDialog.php) or the code bellow:

```php
use KootLabs\TelegramBotDialogs\Dialog;
use Telegram\Bot\Objects\Update;

final class HelloDialog extends Dialog
{
    // List of method to execute. The order defines the sequence.
    protected array $steps = ['sayHello', 'sayOk', 'sayBye'];

    public function sayHello(Update $update): void
    {
        $this->bot->sendMessage([
            'chat_id' => $this->getChatId(),
            'text' => 'Hello! How are you?',
        ]);
    }

    public function sayOk(Update $update): void
    {
        $this->bot->sendMessage([
            'chat_id' => $this->getChatId(),
            'text' => "I'm also OK :)",
        ]);
    }

    public function sayBye(Update $update): void
    {
        $this->bot->sendMessage([
            'chat_id' => $this->getChatId(),
            'text' => 'Bye!',
        ]);
        $this->jump('sayHello');
    }
}
```


### 2. Create a Telegram command

To initiate a dialog please use `DialogManager` (or, if you use Laravel, `Dialogs` Facade) — it will care about storing and recovering `Dialog` instance state between steps/requests.
To execute the first and next steps please call `Dialogs::proceed()` method with update object as an argument.
Also, it is possible to use dialogs with Telegram commands and DI through type hinting.

```php
use App\Dialogs\HelloExampleDialog;
use KootLabs\TelegramBotDialogs\Laravel\Facades\Dialogs;
use Telegram\Bot\Commands\Command;

final class HelloCommand extends Command
{
    /** @var string Command name */
    protected $name = 'hello';

    /** @var string Command description */
    protected $description = 'Just say "Hello" and ask few questions';

    public function handle(): void
    {
        $bot = $this->getTelegram();
        Dialogs::activate(new HelloExampleDialog($this->update->getChat()->id, $bot));
    }
}
```


### 3. Setup your controller

Process request inside your Laravel webhook controller:

```php
use Telegram\Bot\BotsManager;
use KootLabs\TelegramBotDialogs\DialogManager;

final class TelegramWebhookController
{
    public function handle(DialogManager $dialogs, BotsManager $botsManager): void
    {
        $bot = $botsManager->bot('your-bot-name');
        $update = $bot->commandsHandler(true);

        // optional, for multi-bot applications only, when a given bot is not a default one
        $dialogs->setBot($bot);

        $dialogs->exists($update)
            ? $dialogs->proceed($update)
            : $botsManager->bot('your-bot-name')->sendMessage([ // fallback message
                'chat_id' => $update->getChat()->id,
                'text' => 'There is no active dialog at this moment.',
            ]);
    }
}
```


### `Dialog` class API

- `start(\Telegram\Bot\Objects\Update $update)` - Start the dialog from the first step
- `proceed(\Telegram\Bot\Objects\Update $update)` - Proceed the dialog to the next step
- `isEnd()` - Check the end of the dialog
- 🔐 `end()` - End dialog
- 🔐 `jump(string $stepName)` - Jump to the particular step, where `$step` is the `public` method name
- 🔐 `remember(string $key, mixed $value)` - Add a new key-value to `Dialog::$memory` array to make this data available on next steps
- 🔐 `forget(string $key)` - Remove a value from `Dialog::$memory`by key.


### `DialogManager` class API

ℹ️ `Dialogs` [Facade](https://laravel.com/docs/master/facades) proxies calls to `DialogManager` class.

- `setBot(\Telegram\Bot\Api $bot)` - Use non-default Bot for API calls
- `activate(\KootLabs\TelegramBotDialogs\Dialog $dialog)` - Activate a new Dialog (without running it)
- `proceed(\Telegram\Bot\Objects\Update $update)` - Run the next step handler for the existing Dialog
- `exists(\Telegram\Bot\Objects\Update $update)` - Check for existing Dialog


## ToDo

- Add AI API support (e.g. [LUIS](https://www.luis.ai/), [Dataflow](https://cloud.google.com/dataflow))
- Improve documentation and examples
- Improve stability and DX for channel bots
- Improve test coverage


## Backward compatibility promise

Dialogs package uses [Semver 2.0](https://semver.org/). This means that versions are tagged with MAJOR.MINOR.PATCH.
Only a new major version will be allowed to break backward compatibility (BC).

Classes marked as `@experimental` or `@internal` are not included in our backward compatibility promise.
You are also not guaranteed that the value returned from a method is always the same.
You are guaranteed that the data type will not change.

PHP 8 introduced [named arguments](https://wiki.php.net/rfc/named_params), which increased the cost and reduces flexibility for package maintainers.
The names of the arguments for methods in Dialogs is not included in our BC promise.
