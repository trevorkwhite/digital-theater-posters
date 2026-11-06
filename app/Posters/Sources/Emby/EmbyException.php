<?php

namespace App\Posters\Sources\Emby;

use RuntimeException;
use Throwable;

/**
 * Thrown by EmbySource. Messages are written for the person configuring the
 * theater PC: they say what is wrong and which .env value to fix. They never
 * contain the API key.
 */
class EmbyException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null, ?Throwable $previous = null)
    {
        parent::__construct($message, $status ?? 0, $previous);
    }

    public static function missingApiKey(): self
    {
        return new self('No Emby API key configured. Set EMBY_API_KEY in .env (create one in the Emby dashboard under Advanced > API Keys).');
    }

    public static function missingBaseUrl(): self
    {
        return new self('No Emby URL configured. Set EMBY_URL in .env, e.g. http://192.168.1.10:8096');
    }

    public static function unauthorized(string $baseUrl): self
    {
        return new self(
            "Emby at {$baseUrl} rejected the API key (HTTP 401). Check EMBY_API_KEY: it must be a key from the Emby dashboard under Advanced > API Keys, copied without spaces.",
            401,
        );
    }

    public static function forbidden(string $baseUrl, ?string $userId): self
    {
        $hint = $userId
            ? ' Check that EMBY_USER_ID is a user that is allowed to access the configured libraries, or clear EMBY_USER_ID to read the whole server.'
            : ' Check that the API key has not been restricted.';

        return new self("Emby at {$baseUrl} refused access (HTTP 403).{$hint}", 403);
    }

    public static function userNotFound(string $baseUrl, string $userId): self
    {
        return new self(
            "Emby at {$baseUrl} has no user with ID \"{$userId}\". Check EMBY_USER_ID: it is the long hex ID shown in the address bar when you open the user in the Emby dashboard (not the user name), or leave it empty.",
            404,
        );
    }

    public static function notFound(string $baseUrl, string $path): self
    {
        return new self(
            "Emby at {$baseUrl} returned 404 for {$path}. Check EMBY_URL: it should be the server address such as http://host:8096 (add /emby only if your reverse proxy serves Emby under that path). If EMBY_LIBRARY_IDS is set, check those IDs exist.",
            404,
        );
    }

    public static function unreachable(string $baseUrl, Throwable $previous): self
    {
        return new self(
            "Could not connect to Emby at {$baseUrl}: {$previous->getMessage()}. Check EMBY_URL, that the Emby server is running, and that this PC can reach it (firewall, port 8096). EMBY_TIMEOUT controls how long to wait.",
            null,
            $previous,
        );
    }

    public static function badStatus(string $baseUrl, string $path, int $status): self
    {
        return new self("Emby at {$baseUrl} returned HTTP {$status} for {$path}.", $status);
    }

    public static function notJson(string $baseUrl, string $path): self
    {
        return new self(
            "Emby at {$baseUrl} returned something that is not Emby JSON for {$path}. Check EMBY_URL points at the Emby server itself (not a router or proxy login page).",
        );
    }

    public static function badImage(string $itemId, string $reason): self
    {
        return new self("Emby returned an unusable poster image for item {$itemId}: {$reason}.");
    }
}
