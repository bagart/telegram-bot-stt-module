<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Tests\Unit;

use BAGArt\TelegramBotStt\Provider\ProviderRegistry;
use BAGArt\TelegramBotStt\Settings\SttSettings;
use BAGArt\TelegramBotStt\Ui\CallbackRoute;
use BAGArt\TelegramBotStt\Ui\MenuRenderer;
use PHPUnit\Framework\TestCase;

final class MenuRendererTest extends TestCase
{
    private MenuRenderer $menu;

    protected function setUp(): void
    {
        $this->menu = new MenuRenderer(new ProviderRegistry);
    }

    public function test_main_keyboard_carries_valid_callback_data_within_64_bytes(): void
    {
        foreach ([true, false] as $isPrivate) {
            $page = $this->menu->main(-1001234567890, SttSettings::fromArray([], $isPrivate), $isPrivate);

            foreach ($page['keyboard']->inlineKeyboard as $row) {
                foreach ($row as $button) {
                    self::assertNotNull($button->callbackData);
                    self::assertLessThanOrEqual(64, strlen($button->callbackData));
                    self::assertNotNull(CallbackRoute::decode($button->callbackData));
                }
            }
        }
    }

    public function test_private_main_hides_group_only_rows_and_shows_language(): void
    {
        $page = $this->menu->main(1, SttSettings::fromArray(['locale' => 'en'], true), true);

        self::assertStringContainsString('Auto-transcribe', $page['text']);

        $verbs = $this->verbsOf($page);
        self::assertContains(CallbackRoute::VERB_PAGE_LANGUAGE, $verbs);
        self::assertNotContains(CallbackRoute::VERB_PAGE_TRIGGER, $verbs);
        self::assertNotContains(CallbackRoute::VERB_PAGE_ERROR, $verbs);
    }

    public function test_group_main_shows_trigger_and_error_rows_without_language(): void
    {
        $page = $this->menu->main(1, SttSettings::fromArray(['locale' => 'ru'], false), false);

        $verbs = $this->verbsOf($page);
        self::assertContains(CallbackRoute::VERB_PAGE_TRIGGER, $verbs);
        self::assertContains(CallbackRoute::VERB_PAGE_ERROR, $verbs);
        self::assertNotContains(CallbackRoute::VERB_PAGE_LANGUAGE, $verbs);
    }

    public function test_providers_page_lists_all_presets_plus_custom(): void
    {
        $page = $this->menu->providers(1, SttSettings::fromArray(['provider_key' => 'groq-whisper-v3'], true));
        $labels = $this->labelsOf($page);

        self::assertCount(4 + 2, $labels); // 4 presets + custom + back
        self::assertStringContainsString('Groq Whisper v3', $labels[0]);
        self::assertStringEndsWith('●', trim($labels[0]));

        // every preset callback must fit the 64-byte cap with the longest chat id
        foreach ($page['keyboard']->inlineKeyboard as $row) {
            foreach ($row as $button) {
                self::assertLessThanOrEqual(64, strlen((string) $button->callbackData));
            }
        }
    }

    public function test_unknown_provider_key_is_surfaced_not_hidden(): void
    {
        $page = $this->menu->providers(1, SttSettings::fromArray(['provider_key' => 'ghost-key'], true));

        self::assertStringContainsString('ghost-key (?)', $page['text']);
        self::assertStringContainsString('ghost-key', implode("\n", $this->labelsOf($page)));
    }

    public function test_language_page_marks_current_choice(): void
    {
        $settings = SttSettings::fromArray(['language' => 'ru'], true);
        $page = $this->menu->language(1, $settings);

        self::assertStringContainsString('Ru ●', implode("\n", $this->labelsOf($page)));
    }

    public function test_trigger_page_lists_all_triggers(): void
    {
        $page = $this->menu->trigger(1, SttSettings::fromArray(['group_trigger' => 'mention'], false));
        $labels = implode("\n", $this->labelsOf($page));

        self::assertStringContainsString('все голосовые', $labels);
        self::assertStringContainsString('ответ боту', $labels);
        self::assertStringContainsString('упоминание бота ●', $labels);
    }

    public function test_error_mode_page_marks_current_mode(): void
    {
        $page = $this->menu->errorMode(1, SttSettings::fromArray(['on_error' => 'silent'], false));

        self::assertStringContainsString('🤫 silent ●', implode("\n", $this->labelsOf($page)));
    }

    /** @return list<string> */
    private function verbsOf(array $page): array
    {
        $verbs = [];

        foreach ($page['keyboard']->inlineKeyboard as $row) {
            foreach ($row as $button) {
                $route = CallbackRoute::decode($button->callbackData);
                $verbs[] = $route['verb'];
            }
        }

        return $verbs;
    }

    /** @return list<string> */
    private function labelsOf(array $page): array
    {
        $labels = [];

        foreach ($page['keyboard']->inlineKeyboard as $row) {
            foreach ($row as $button) {
                $labels[] = $button->text;
            }
        }

        return $labels;
    }
}
