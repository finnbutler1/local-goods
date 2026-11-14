<?php

namespace App\Services\Sandbox;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The sandbox folder (ADR 0045): today's template and one SQLite file per
 * sandbox, plus a small JSON note with when it was made. The folder itself is
 * the list of sandboxes, so the main database holds nothing about them.
 *
 * A sandbox is named by the SHA-256 of its token, so a file name in a log
 * never reveals the token that opens it. Nothing here opens a database: it
 * only copies, lists and deletes files.
 */
class SandboxFiles
{
    public const TOKEN_PATTERN = '/^[A-Za-z0-9]{40}$/';

    private const TIMEZONE = 'America/New_York';

    public function dir(): string
    {
        $dir = rtrim((string) config('sandbox.path'), '/');

        if (! is_dir($dir) && ! @mkdir($dir, 0770, true) && ! is_dir($dir)) {
            throw new RuntimeException('The sandbox folder could not be made.');
        }

        return $dir;
    }

    /** The template for a DC date (default: today in DC). */
    public function templatePath(?Carbon $day = null): string
    {
        $day ??= Carbon::now(self::TIMEZONE);

        return $this->dir().'/template-'.$day->copy()->setTimezone(self::TIMEZONE)->toDateString().'.sqlite';
    }

    public function hasTodaysTemplate(): bool
    {
        return is_file($this->templatePath());
    }

    public static function newToken(): string
    {
        return Str::random(40);
    }

    public static function idFor(string $token): string
    {
        return hash('sha256', $token);
    }

    public function databasePath(string $id): string
    {
        return $this->dir()."/sb-{$id}.sqlite";
    }

    private function notePath(string $id): string
    {
        return $this->dir()."/sb-{$id}.json";
    }

    public function exists(string $id): bool
    {
        return is_file($this->databasePath($id)) && $this->createdAt($id) !== null;
    }

    public function createdAt(string $id): ?Carbon
    {
        $note = @file_get_contents($this->notePath($id));
        $created = $note === false ? null : (json_decode($note, true)['created_at'] ?? null);

        return is_int($created) ? Carbon::createFromTimestamp($created) : null;
    }

    public function expiresAt(string $id): ?Carbon
    {
        return $this->createdAt($id)?->addHours((int) config('sandbox.lifetime_hours'));
    }

    public function isLive(string $id): bool
    {
        $expires = $this->expiresAt($id);

        return $expires !== null && is_file($this->databasePath($id)) && $expires->isFuture();
    }

    /**
     * Copies today's template into a new sandbox. The copy is written under a
     * temporary name and renamed, so a half-copied file is never opened.
     */
    public function createFromTemplate(string $id): void
    {
        $template = $this->templatePath();
        if (! is_file($template)) {
            throw new RuntimeException('Today\'s sandbox template is not ready.');
        }

        $target = $this->databasePath($id);
        $tmp = $target.'.tmp-'.Str::random(8);
        if (! @copy($template, $tmp)) {
            @unlink($tmp);
            throw new RuntimeException('The sandbox could not be copied.');
        }
        @chmod($tmp, 0660);

        file_put_contents($this->notePath($id), json_encode(['created_at' => Carbon::now()->getTimestamp()]), LOCK_EX);
        if (! @rename($tmp, $target)) {
            @unlink($tmp);
            @unlink($this->notePath($id));
            throw new RuntimeException('The sandbox could not be copied.');
        }
    }

    public function destroy(string $id): void
    {
        $db = $this->databasePath($id);
        foreach ([$db, $db.'-journal', $db.'-wal', $db.'-shm', $this->notePath($id)] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    /** @return list<string> ids of every sandbox file in the folder, live or not */
    public function all(): array
    {
        $ids = [];
        foreach (glob($this->dir().'/sb-*.sqlite') ?: [] as $file) {
            if (preg_match('/sb-([0-9a-f]{64})\.sqlite$/', $file, $m)) {
                $ids[] = $m[1];
            }
        }

        return $ids;
    }

    public function liveCount(): int
    {
        return count(array_filter($this->all(), fn (string $id) => $this->isLive($id)));
    }

    /**
     * Deletes expired sandboxes, sandboxes with no note, half-copied files and
     * every template except today's. Never opens a database.
     *
     * @return array{sandboxes: int, templates: int}
     */
    public function prune(): array
    {
        $sandboxes = 0;
        foreach ($this->all() as $id) {
            if (! $this->isLive($id)) {
                $this->destroy($id);
                $sandboxes++;
            }
        }

        // Notes left without a database, and copies older than an hour that never got renamed.
        foreach (glob($this->dir().'/sb-*.json') ?: [] as $note) {
            if (! is_file(substr($note, 0, -5).'.sqlite')) {
                @unlink($note);
            }
        }
        foreach (glob($this->dir().'/*.tmp-*') ?: [] as $tmp) {
            if (filemtime($tmp) < time() - 3600) {
                @unlink($tmp);
            }
        }

        $templates = 0;
        $today = $this->templatePath();
        foreach (glob($this->dir().'/template-*.sqlite') ?: [] as $template) {
            if ($template !== $today) {
                @unlink($template);
                $templates++;
            }
        }

        return ['sandboxes' => $sandboxes, 'templates' => $templates];
    }
}
