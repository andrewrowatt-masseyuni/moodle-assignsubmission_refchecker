<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/results.js

namespace EndlessCreativity\ElephantPhp;

final class Message
{
    public function __construct(
        public readonly MessageType $type,
        public readonly string $message,
    ) {
    }

    public static function warning(string $message): self
    {
        return new self(type: MessageType::Warning, message: $message);
    }

    public static function error(string $message): self
    {
        return new self(type: MessageType::Error, message: $message);
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->message === $other->message;
    }
}
