<?php

namespace App\Services\Notify;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Sends messages and files through a Telegram bot (Bot API, HTTPS).
 *
 *   1. In Telegram, open @BotFather, send /newbot, and copy the token it gives.
 *   2. Send /start to the new bot (or add it to a group and send a message there).
 *   3. In Settings, paste the token and press "Find chats" to get the chat ID.
 */
class Telegram
{
    private const API = 'https://api.telegram.org/bot';

    private const UNREACHABLE = "Can't reach api.telegram.org from this server: check its internet connection, DNS and firewall (HTTPS out).";

    /** Telegram's limit for one message. */
    public const MAX_TEXT = 4096;

    public function __construct(private NotifySettings $settings)
    {
    }

    /**
     * Sends HTML-formatted text to every chat (or the ones given).
     *
     * @return int how many chats received it
     *
     * @throws RuntimeException when none did
     */
    public function send(string $html, ?array $chats = null, ?string $token = null): int
    {
        $html = mb_strlen($html) > self::MAX_TEXT ? mb_substr($html, 0, self::MAX_TEXT - 20)."\n…" : $html;

        return $this->toChats($chats, $token, fn ($t, $chat) => Http::timeout(15)->asForm()->post(self::API.$t.'/sendMessage', [
            'chat_id' => $chat,
            'text' => $html,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => 'true',
        ]));
    }

    /** Sends a file (e.g. the PDF report) with a short caption. */
    public function sendDocument(string $bytes, string $filename, string $caption, ?array $chats = null): int
    {
        return $this->toChats($chats, null, fn ($t, $chat) => Http::timeout(60)
            ->attach('document', $bytes, $filename)
            ->post(self::API.$t.'/sendDocument', [
                'chat_id' => $chat,
                'caption' => mb_substr($caption, 0, 1000),
                'parse_mode' => 'HTML',
            ]));
    }

    /**
     * Sends a picture shown in the chat. A tall report is sent as a file instead when
     * Telegram refuses it as a photo (its photo limits: 10 MB, sides adding up to 10,000 px).
     */
    public function sendPhoto(string $png, string $filename, string $caption, ?array $chats = null): int
    {
        try {
            return $this->toChats($chats, null, fn ($t, $chat) => Http::timeout(60)
                ->attach('photo', $png, $filename)
                ->post(self::API.$t.'/sendPhoto', [
                    'chat_id' => $chat,
                    'caption' => mb_substr($caption, 0, 1000),
                    'parse_mode' => 'HTML',
                ]));
        } catch (RuntimeException $e) {
            if (! str_contains($e->getMessage(), 'PHOTO_INVALID_DIMENSIONS') && ! str_contains($e->getMessage(), 'too big')) {
                throw $e;
            }

            return $this->sendDocument($png, $filename, $caption, $chats);
        }
    }

    /**
     * Chats that have written to the bot lately, to find their chat ID.
     *
     * @return array<int, array{id:string, name:string, type:string}>
     */
    public function recentChats(string $token): array
    {
        try {
            $res = Http::timeout(15)->get(self::API.$token.'/getUpdates', ['limit' => 100]);
        } catch (ConnectionException) {
            throw new RuntimeException(self::UNREACHABLE);
        }
        $this->check($res, null);

        $chats = [];
        foreach ((array) $res->json('result') as $update) {
            $chat = $update['message']['chat'] ?? $update['my_chat_member']['chat'] ?? $update['channel_post']['chat'] ?? null;
            if ($chat) {
                $chats[(string) $chat['id']] = [
                    'id' => (string) $chat['id'],
                    'name' => $chat['title'] ?? trim(($chat['first_name'] ?? '').' '.($chat['last_name'] ?? '')) ?: ($chat['username'] ?? 'Chat'),
                    'type' => $chat['type'] ?? 'private',
                ];
            }
        }

        return array_values($chats);
    }

    private function toChats(?array $chats, ?string $token, \Closure $call): int
    {
        $cfg = $this->settings->telegram();
        $token = $token ?: $cfg['token'];
        $chats = $chats ?? $cfg['chats'];
        if (! $token || ! $chats) {
            throw new RuntimeException('Telegram is not set up: enter the bot token and at least one chat ID in Settings.');
        }

        $sent = 0;
        $errors = [];
        foreach ($chats as $chat) {
            try {
                $this->check($call($token, $chat), $chat);
                $sent++;
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            } catch (ConnectionException) {
                $errors[] = self::UNREACHABLE;
            }
        }
        if ($sent === 0) {
            throw new RuntimeException(implode(' ', array_unique($errors)));
        }

        return $sent;
    }

    /** Turns Telegram's error into something an admin can act on. */
    private function check(Response $res, ?string $chat): void
    {
        if ($res->successful() && $res->json('ok')) {
            return;
        }
        $code = $res->json('error_code') ?? $res->status();
        $desc = (string) ($res->json('description') ?? $res->body());

        throw new RuntimeException(match (true) {
            $code === 401 || $code === 404 => 'Telegram rejected the bot token. Copy it again from @BotFather.',
            str_contains($desc, 'chat not found') => "Chat {$chat} not found. Send /start to the bot from that chat (or add the bot to the group) first, then use Find chats.",
            $code === 403 => "The bot can't write to chat {$chat}: it was blocked or removed from the group.",
            $code === 429 => 'Telegram asks to slow down; the message will be retried.',
            default => 'Telegram: '.$desc,
        });
    }
}
