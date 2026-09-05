<?php

declare(strict_types=1);

use BAGArt\TelegramBotMenu\Testing\TgWebUiContractTest;
use BAGArt\TelegramBotStt\Settings\SttSettings;
use BAGArt\TelegramBotStt\Web\SttWebUi;

/**
 * menu_integration.md M-3a: STT schema manifest + settings round-trip
 * (§8.3) — validate() output feeds SttSettings::fromArray unmodified.
 */
it('satisfies the TgWebUiContract shape for the stt module', function () {
    TgWebUiContractTest::assertContractShape(SttWebUi::class, 'stt');
});

it('declares a schema entry clamped to the DTO field vocabulary', function () {
    $entry = SttWebUi::manifest()->entry;

    expect($entry->type)->toBe('schema');

    $keys = [];
    foreach ($entry->groups as $group) {
        foreach ($group->fields as $field) {
            $keys[] = $field->key;
        }
    }

    expect($keys)->toBe([
        'auto_enabled', 'group_trigger', 'language',
        'reply_mode', 'template', 'on_error',
        'provider_key', 'max_duration_sec', 'max_file_mb', 'daily_quota',
    ]);
});

it('maps schema keys onto SttSettings raw keys via validate', function () {
    $patch = (new SttWebUi)->validate([
        'auto_enabled' => false,
        'group_trigger' => 'mention',
        'language' => ' RU ',
        'reply_mode' => false,
        'template' => '🎙 {text}',
        'on_error' => 'message',
        'provider_key' => 'local-whisper',
        'max_duration_sec' => '99999',
        'max_file_mb' => 0,
        'daily_quota' => '100',
    ]);

    expect($patch['auto_enabled'])->toBeFalse()
        ->and($patch['group_trigger'])->toBe('mention')
        ->and($patch['language'])->toBe('ru')
        ->and($patch['reply_mode'])->toBe('direct')
        ->and($patch['template'])->toBe('🎙 {text}')
        ->and($patch['on_error'])->toBe('message')
        ->and($patch['provider_key'])->toBe('local-whisper')
        ->and($patch['max_duration_sec'])->toBe(1200)
        ->and($patch['max_file_mb'])->toBe(1)
        ->and($patch['daily_quota'])->toBe(100);
});

it('feeds the validated patch straight into SttSettings::fromArray', function () {
    $patch = (new SttWebUi)->validate([
        'auto_enabled' => true,
        'group_trigger' => 'reply_bot',
        'on_error' => 'silent',
        'provider_key' => 'openai-whisper',
    ]);

    $settings = SttSettings::fromArray($patch, isPrivateChat: true);

    expect($settings->autoEnabled)->toBeTrue()
        ->and($settings->groupTrigger)->toBe('reply_bot')
        ->and($settings->onError)->toBe('silent')
        ->and($settings->providerKey)->toBe('openai-whisper');
});

it('rejects unknown enum and provider values', function () {
    $form = new SttWebUi;

    expect(fn () => $form->validate(['group_trigger' => 'everything']))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $form->validate(['on_error' => 'explode']))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $form->validate(['provider_key' => 'skynet']))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $form->validate(['template' => str_repeat('x', SttSettings::TEMPLATE_MAX_CHARS + 1)]))
        ->toThrow(InvalidArgumentException::class);
});

it('drops unrelated keys and keeps the module configured', function () {
    $form = new SttWebUi;

    expect($form->validate(['evil_key' => 'x', 'custom_provider' => ['token' => 'steal']]))->toBe([])
        ->and($form->isConfigured([]))->toBeTrue();
});
