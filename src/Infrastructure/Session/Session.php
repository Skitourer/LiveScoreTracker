<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Session;

use LiveScoreTracker\Domain\Security\User;

/**
 * Server-side session of one browser, stored in the database.
 *
 * The browser only holds a random token; the database stores its hash.
 */
final class Session
{
    private bool $dirty = false;
    private bool $destroyed = false;
    private ?string $previousToken = null;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private string $token,
        private array $data,
        private readonly bool $new,
        public readonly string $deviceId,
    ) {}

    public static function start(string $deviceId): self
    {
        return new self(self::newToken(), [], true, $deviceId);
    }

    public function token(): string
    {
        return $this->token;
    }

    public function isNew(): bool
    {
        return $this->new;
    }

    public function isDirty(): bool
    {
        return $this->dirty;
    }

    public function isDestroyed(): bool
    {
        return $this->destroyed;
    }

    /**
     * Token replaced by regenerate(), whose stored session must be deleted.
     */
    public function previousToken(): ?string
    {
        return $this->previousToken;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
        $this->dirty = true;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
        $this->dirty = true;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    public function userId(): ?int
    {
        $id = $this->data['user_id'] ?? null;

        return \is_int($id) ? $id : null;
    }

    /**
     * Starts an authenticated session after a correct password.
     * A new token prevents session fixation.
     */
    public function logIn(User $user, bool $secondFactorDone): void
    {
        $csrf = $this->data['csrf'] ?? null;
        $this->regenerate();
        $this->data = [
            'user_id' => $user->id,
            'session_version' => $user->sessionVersion,
            'second_factor' => $secondFactorDone,
            'logged_in_at' => time(),
        ];
        if (\is_string($csrf)) {
            $this->data['csrf'] = $csrf;
        }
        $this->dirty = true;
    }

    public function completeSecondFactor(): void
    {
        $this->regenerate();
        $this->set('second_factor', true);
    }

    public function destroy(): void
    {
        $this->data = [];
        $this->destroyed = true;
    }

    public function regenerate(): void
    {
        if (!$this->new && $this->previousToken === null) {
            $this->previousToken = $this->token;
        }
        $this->token = self::newToken();
        $this->dirty = true;
    }

    /**
     * Message shown once on the next page.
     *
     * @param array<string, string|int> $params
     */
    public function flash(string $type, string $messageKey, array $params = []): void
    {
        $flashes = $this->data['flashes'] ?? [];
        $flashes = \is_array($flashes) ? $flashes : [];
        $flashes[] = ['type' => $type, 'key' => $messageKey, 'params' => $params];
        $this->set('flashes', $flashes);
    }

    /**
     * @return list<array{type: string, key: string, params: array<string, string|int>}>
     */
    public function takeFlashes(): array
    {
        $flashes = $this->data['flashes'] ?? [];
        if (!\is_array($flashes) || $flashes === []) {
            return [];
        }
        $this->remove('flashes');

        $valid = [];
        foreach ($flashes as $flash) {
            if (\is_array($flash) && \is_string($flash['type'] ?? null) && \is_string($flash['key'] ?? null)) {
                /** @var array<string, string|int> $params */
                $params = \is_array($flash['params'] ?? null) ? $flash['params'] : [];
                $valid[] = ['type' => $flash['type'], 'key' => $flash['key'], 'params' => $params];
            }
        }

        return $valid;
    }

    public function csrfToken(): string
    {
        $token = $this->data['csrf'] ?? null;
        if (!\is_string($token)) {
            $token = bin2hex(random_bytes(32));
            $this->set('csrf', $token);
        }

        return $token;
    }

    private static function newToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
