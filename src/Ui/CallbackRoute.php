<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotStt\Ui;

/**
 * Encode/decode inline-keyboard callback data ("tc:<chatId>:<verb>[:arg]").
 * Telegram caps callback_data at 64 bytes; chatId is embedded because the
 * parsed CallbackQuery DTO carries no usable originating-message payload.
 * The prefix is exclusive to this module (tts sibling uses "tv:", §18).
 */
final class CallbackRoute
{
    private const PREFIX = 'tc';

    public const VERB_MENU = 'm';

    public const VERB_AUTO_ON = 'aon';

    public const VERB_AUTO_OFF = 'aoff';

    public const VERB_PAGE_PROVIDERS = 'pstt';

    public const VERB_SELECT_PROVIDER = 'sst';

    public const VERB_CUSTOM_PROVIDER = 'pjc';

    public const VERB_ADD_TOKEN = 'tka';

    public const VERB_PAGE_LANGUAGE = 'plang';

    public const VERB_SET_LANGUAGE = 'lang';

    public const VERB_PAGE_TRIGGER = 'pgrp';

    public const VERB_SET_TRIGGER = 'grp';

    public const VERB_EDIT_TEMPLATE = 'tpl';

    public const VERB_PAGE_ERROR = 'perr';

    public const VERB_SET_ERROR = 'err';

    public const VERB_CLOSE = 'x';

    public static function encode(int $chatId, string $verb, ?string $arg = null): string
    {
        $data = self::PREFIX.':'.$chatId.':'.$verb;

        return $arg === null ? $data : $data.':'.$arg;
    }

    public static function fits(int $chatId, string $verb, ?string $arg = null): bool
    {
        return strlen(self::encode($chatId, $verb, $arg)) <= 64;
    }

    /**
     * @return array{chatId: int, verb: string, arg: ?string}|null
     */
    public static function decode(?string $data): ?array
    {
        if ($data === null || ! str_starts_with($data, self::PREFIX.':')) {
            return null;
        }

        $parts = explode(':', $data);

        if (count($parts) < 3 || count($parts) > 4) {
            return null;
        }

        [, $chatIdRaw, $verb] = $parts;
        $chatId = filter_var($chatIdRaw, FILTER_VALIDATE_INT);

        if ($chatId === false || $chatId === 0 || preg_match('/^[a-z]{1,6}$/', $verb) !== 1) {
            return null;
        }

        return [
            'chatId' => $chatId,
            'verb' => $verb,
            'arg' => $parts[3] ?? null,
        ];
    }
}
