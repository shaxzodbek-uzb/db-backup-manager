<?php

namespace App\Services\Destination;

use App\Models\Destination;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Sends a plain message to a Telegram destination's chat.
 *
 * The uploader next door sends documents; this sends words, so that the one
 * channel already trusted to carry the backups can also say when they stopped
 * arriving. No new bot, no second chat to keep in sync.
 */
class TelegramMessenger
{
    /**
     * Telegram's own limit on a message, which an alert listing every plan on
     * a badly broken night could reach.
     */
    public const MAX_LENGTH = 4096;

    public function send(Destination $destination, string $text): void
    {
        if ($destination->type !== 'telegram') {
            throw new RuntimeException("Destination [{$destination->name}] is not a Telegram chat.");
        }

        $config = $destination->config ?? [];

        $response = Http::timeout(30)->asForm()->post(
            'https://api.telegram.org/bot'.((string) ($config['bot_token'] ?? '')).'/sendMessage',
            [
                'chat_id' => (string) ($config['chat_id'] ?? ''),
                'text' => mb_substr($text, 0, self::MAX_LENGTH),
                'disable_web_page_preview' => 'true',
            ],
        );

        if (! $response->ok() || $response->json('ok') !== true) {
            throw new RuntimeException(
                'Telegram rejected the alert: '.($response->json('description') ?? 'unknown error')
            );
        }
    }

    /**
     * The chat alerts are sent to: the one named in config, or the only one
     * there is.
     */
    public function alertDestination(): ?Destination
    {
        $configured = config('backup.health.telegram_destination_id');

        if ($configured !== null && $configured !== '') {
            return Destination::query()
                ->where('id', $configured)
                ->where('type', 'telegram')
                ->first();
        }

        return Destination::query()->where('type', 'telegram')->orderBy('id')->first();
    }
}
