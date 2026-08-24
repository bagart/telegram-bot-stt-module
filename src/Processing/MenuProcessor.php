<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Processing;

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\Outbound\TgSenderContract;
use BAGArt\TelegramBot\Contracts\Processing\Processors\TgModuleProcessorContract;
use BAGArt\TelegramBot\Contracts\TgApi\TgApiTypeDTOContract;
use BAGArt\TelegramBot\Processing\BotProcessorContext;
use BAGArt\TelegramBot\Processing\ErrorHandling\ProcessorErrorContext;
use BAGArt\TelegramBot\TgApi\Methods\DTO\AnswerCallbackQueryMethodDTO;
use BAGArt\TelegramBot\TgApi\Methods\DTO\SendMessageMethodDTO;
use BAGArt\TelegramBot\TgApi\Methods\Enum\ParseModeEnum;
use BAGArt\TelegramBot\TgApi\Types\DTO\CallbackQueryTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\InlineKeyboardMarkupTypeDTO;
use BAGArt\TelegramBotStt\I18n\Strings;
use BAGArt\TelegramBotStt\Models\SttToken;
use BAGArt\TelegramBotStt\ModuleFactory;
use BAGArt\TelegramBotStt\Provider\ProviderRegistry;
use BAGArt\TelegramBotStt\Settings\SttSettings;
use BAGArt\TelegramBotStt\Ui\CallbackRoute as Route;
use BAGArt\TelegramBotStt\Ui\PendingInputService;
use Throwable;

/**
 * Inline-keyboard router for the /text settings panel. Every press is
 * re-authorized; the callback chatId is cross-checked against the actual
 * callback chat (§10.2); menus are sent as fresh messages (the parsed DTO
 * carries no usable originating-message id to edit).
 */
class MenuProcessor implements TgModuleProcessorContract
{
    public function __construct(
        private readonly TgSenderContract $sender,
    ) {}

    public static function moduleId(): string
    {
        return ModuleFactory::MODULE_ID;
    }

    public static function build(BotProcessorContext $context): self
    {
        return new self($context->tgSender);
    }

    public function support(
        TgApiTypeDTOContract $dto,
        TgBotConfig $botConfig,
        ?string $action = null,
    ): bool {
        return $dto instanceof CallbackQueryTypeDTO
            && $dto->data !== null
            && Route::decode($dto->data) !== null;
    }

    public function isStrictOrdered(
        TgApiTypeDTOContract $dto,
        TgBotConfig $botConfig,
        ?string $action = null,
    ): bool {
        return false;
    }

    public function process(
        TgApiTypeDTOContract $dto,
        TgBotConfig $botConfig,
        ?string $action = null,
    ): void {
        assert($dto instanceof CallbackQueryTypeDTO);

        if (! ModuleFactory::inLaravel() || $dto->from === null) {
            return;
        }

        $route = Route::decode($dto->data);

        if ($route === null) {
            return;
        }

        // The parsed CallbackQuery DTO carries no usable originating-message
        // payload (MaybeInaccessibleMessage is an unimplemented oneOf stub),
        // so the embedded route chatId is the chat scope — re-authorized via
        // canManage() on every press (§10.2 mitigation).
        $chatId = $route['chatId'];

        try {
            $this->dispatchVerb($dto, $botConfig, $chatId, $route['verb'], $route['arg']);
        } catch (Throwable $e) {
            report($e);
            $this->answer($botConfig, $dto, 'Menu error', alert: true);
        }
    }

    private function dispatchVerb(
        CallbackQueryTypeDTO $query,
        TgBotConfig $botConfig,
        int $chatId,
        string $verb,
        ?string $arg,
    ): void {
        $isPrivate = self::isPrivateChatId($chatId);

        if (! ModuleFactory::access()->canManage($botConfig, $chatId, $query->from, $isPrivate)) {
            $this->answer($botConfig, $query, Strings::t('ru', 'panel.denied_group'), alert: true);

            return;
        }

        switch ($verb) {
            case Route::VERB_MENU:
                $this->renderMain($botConfig, $query, $chatId);
                break;

            case Route::VERB_AUTO_ON:
            case Route::VERB_AUTO_OFF:
                $this->toggleAuto($botConfig, $query, $chatId, $verb === Route::VERB_AUTO_ON);
                break;

            case Route::VERB_PAGE_PROVIDERS:
                $this->renderPage($botConfig, $query, $chatId, fn (SttSettings $s): array => ModuleFactory::menuRenderer()->providers($chatId, $s));
                break;

            case Route::VERB_SELECT_PROVIDER:
                $this->selectProvider($botConfig, $query, $chatId, (string) $arg);
                break;

            case Route::VERB_CUSTOM_PROVIDER:
                $this->startCustomJsonEditor($botConfig, $query, $chatId);
                break;

            case Route::VERB_PAGE_LANGUAGE:
                $this->renderPage($botConfig, $query, $chatId, fn (SttSettings $s): array => ModuleFactory::menuRenderer()->language($chatId, $s));
                break;

            case Route::VERB_SET_LANGUAGE:
                $this->setLanguage($botConfig, $query, $chatId, (string) $arg);
                break;

            case Route::VERB_PAGE_TRIGGER:
                $this->renderPage($botConfig, $query, $chatId, fn (SttSettings $s): array => ModuleFactory::menuRenderer()->trigger($chatId, $s));
                break;

            case Route::VERB_SET_TRIGGER:
                $this->setTrigger($botConfig, $query, $chatId, (string) $arg);
                break;

            case Route::VERB_EDIT_TEMPLATE:
                $this->startTemplateEditor($botConfig, $query, $chatId);
                break;

            case Route::VERB_PAGE_ERROR:
                $this->renderPage($botConfig, $query, $chatId, fn (SttSettings $s): array => ModuleFactory::menuRenderer()->errorMode($chatId, $s));
                break;

            case Route::VERB_SET_ERROR:
                $this->setErrorMode($botConfig, $query, $chatId, (string) $arg);
                break;

            case Route::VERB_CLOSE:
                $this->answer($botConfig, $query, 'Closed');
                break;

            default:
                $this->answer($botConfig, $query, 'Unsupported action', alert: true);
        }
    }

    /**
     * @param  callable(SttSettings): array{text: string, keyboard: InlineKeyboardMarkupTypeDTO}  $pageBuilder
     */
    private function renderPage(TgBotConfig $botConfig, CallbackQueryTypeDTO $query, int $chatId, callable $pageBuilder): void
    {
        $this->sendPage($botConfig, $chatId, $pageBuilder($this->settingsOf($botConfig, $query, $chatId)));
        $this->answer($botConfig, $query);
    }

    private function renderMain(TgBotConfig $botConfig, CallbackQueryTypeDTO $query, int $chatId): void
    {
        $settings = $this->settingsOf($botConfig, $query, $chatId);
        $this->sendPage($botConfig, $chatId, ModuleFactory::menuRenderer()->main($chatId, $settings, self::isPrivateChatId($chatId)));
        $this->answer($botConfig, $query);
    }

    private function toggleAuto(TgBotConfig $botConfig, CallbackQueryTypeDTO $query, int $chatId, bool $enable): void
    {
        ModuleFactory::settings()->patch((string) $botConfig->botId, $chatId, ['auto_enabled' => $enable]);
        $this->answer($botConfig, $query, $enable ? 'Auto ON' : 'Auto OFF');
        $this->renderMain($botConfig, $query, $chatId);
    }

    private function selectProvider(TgBotConfig $botConfig, CallbackQueryTypeDTO $query, int $chatId, string $key): void
    {
        $registry = ModuleFactory::providers();

        if (! $registry->has($key)) {
            $this->answer($botConfig, $query, 'Unknown provider', alert: true);

            return;
        }

        $needsToken = $key !== ProviderRegistry::CUSTOM_KEY && (bool) $registry->get($key)?->needsToken;
        $hasVaultToken = $key !== ProviderRegistry::CUSTOM_KEY
            && SttToken::query()->where('bot_id', (string) $botConfig->botId)->where('provider_key', $key)->exists();

        ModuleFactory::settings()->patch((string) $botConfig->botId, $chatId, ['provider_key' => $key]);

        if ($needsToken && ! $hasVaultToken) {
            // chain straight into the key paste flow (US3: pick provider → paste once)
            $this->startTokenInput($botConfig, $query, $chatId, $key);

            return;
        }

        $this->answer($botConfig, $query, 'Provider selected');
        $this->renderPage($botConfig, $query, $chatId, fn (SttSettings $s): array => ModuleFactory::menuRenderer()->providers($chatId, $s));
    }

    private function setLanguage(TgBotConfig $botConfig, CallbackQueryTypeDTO $query, int $chatId, string $value): void
    {
        $language = strtolower($value) === 'auto' ? null : mb_substr(strtolower($value), 0, 8);

        ModuleFactory::settings()->patch((string) $botConfig->botId, $chatId, ['language' => $language]);
        $this->answer($botConfig, $query, 'Language updated');
        $this->renderPage($botConfig, $query, $chatId, fn (SttSettings $s): array => ModuleFactory::menuRenderer()->language($chatId, $s));
    }

    private function setTrigger(TgBotConfig $botConfig, CallbackQueryTypeDTO $query, int $chatId, string $value): void
    {
        if (! in_array($value, SttSettings::GROUP_TRIGGERS, true)) {
            $this->answer($botConfig, $query, 'Unknown trigger', alert: true);

            return;
        }

        ModuleFactory::settings()->patch((string) $botConfig->botId, $chatId, ['group_trigger' => $value]);
        $this->answer($botConfig, $query, 'Trigger updated');
        $this->renderPage($botConfig, $query, $chatId, fn (SttSettings $s): array => ModuleFactory::menuRenderer()->trigger($chatId, $s));
    }

    private function setErrorMode(TgBotConfig $botConfig, CallbackQueryTypeDTO $query, int $chatId, string $value): void
    {
        if (! in_array($value, SttSettings::ERROR_MODES, true)) {
            $this->answer($botConfig, $query, 'Unknown mode', alert: true);

            return;
        }

        ModuleFactory::settings()->patch((string) $botConfig->botId, $chatId, ['on_error' => $value]);
        $this->answer($botConfig, $query, 'Mode updated');
        $this->renderPage($botConfig, $query, $chatId, fn (SttSettings $s): array => ModuleFactory::menuRenderer()->errorMode($chatId, $s));
    }

    private function startTemplateEditor(TgBotConfig $botConfig, CallbackQueryTypeDTO $query, int $chatId): void
    {
        ModuleFactory::pending()->start(
            (string) $botConfig->botId,
            $chatId,
            (int) $query->from->id,
            PendingInputService::ACTION_TEMPLATE,
        );

        $this->answer($botConfig, $query, 'Waiting for template text');
        $this->sendText($botConfig, $chatId, Strings::t($this->settingsOf($botConfig, $query, $chatId)->locale, 'input.template'));
    }

    private function startCustomJsonEditor(TgBotConfig $botConfig, CallbackQueryTypeDTO $query, int $chatId): void
    {
        ModuleFactory::pending()->start(
            (string) $botConfig->botId,
            $chatId,
            (int) $query->from->id,
            PendingInputService::ACTION_PROVIDER_JSON,
        );

        $this->answer($botConfig, $query, 'Waiting for provider JSON');

        $locale = $this->settingsOf($botConfig, $query, $chatId)->locale;
        $json = htmlspecialchars(ModuleFactory::providers()->customTemplateJson(), ENT_QUOTES, 'UTF-8');

        $this->sendText($botConfig, $chatId, Strings::t($locale, 'input.json', ['json' => $json]));
    }

    private function startTokenInput(TgBotConfig $botConfig, CallbackQueryTypeDTO $query, int $chatId, string $providerKey): void
    {
        if (! ModuleFactory::providers()->has($providerKey)) {
            $this->answer($botConfig, $query, 'Unknown provider', alert: true);

            return;
        }

        ModuleFactory::pending()->start(
            (string) $botConfig->botId,
            $chatId,
            (int) $query->from->id,
            PendingInputService::ACTION_TOKEN,
            ['provider_key' => $providerKey],
        );

        $this->answer($botConfig, $query, 'Waiting for the key');

        $locale = $this->settingsOf($botConfig, $query, $chatId)->locale;
        $name = htmlspecialchars(ModuleFactory::menuRenderer()->providerName($providerKey), ENT_QUOTES, 'UTF-8');

        $this->sendText($botConfig, $chatId, Strings::t($locale, 'input.token', ['provider' => $name]));
    }

    private function settingsOf(TgBotConfig $botConfig, CallbackQueryTypeDTO $query, int $chatId): SttSettings
    {
        return ModuleFactory::settings()->get((string) $botConfig->botId, $chatId, self::isPrivateChatId($chatId));
    }

    /** Private Telegram chats have positive ids; groups/supergroups are negative. */
    public static function isPrivateChatId(int $chatId): bool
    {
        return $chatId > 0;
    }

    /**
     * @param  array{text: string, keyboard: InlineKeyboardMarkupTypeDTO}  $page
     */
    private function sendPage(TgBotConfig $botConfig, int $chatId, array $page): void
    {
        $this->sender->send($botConfig, new SendMessageMethodDTO(
            chatId: (string) $chatId,
            text: $page['text'],
            parseMode: ParseModeEnum::HTML,
            replyMarkup: $page['keyboard'],
        ));
    }

    private function sendText(TgBotConfig $botConfig, int $chatId, string $text): void
    {
        $this->sender->send($botConfig, new SendMessageMethodDTO(
            chatId: (string) $chatId,
            text: $text,
            parseMode: ParseModeEnum::HTML,
        ));
    }

    private function answer(TgBotConfig $botConfig, CallbackQueryTypeDTO $query, ?string $text = null, bool $alert = false): void
    {
        $this->sender->send($botConfig, new AnswerCallbackQueryMethodDTO(
            callbackQueryId: $query->id,
            text: $text,
            showAlert: $alert ? true : null,
        ));
    }

    public function onException(ProcessorErrorContext $context): void {}
}
