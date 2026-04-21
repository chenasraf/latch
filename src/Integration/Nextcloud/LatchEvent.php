<?php

declare(strict_types=1);

namespace Latch\Integration\Nextcloud;

use OCP\EventDispatcher\Event;

/**
 * Event used to bridge Latch hooks through Nextcloud's event dispatcher.
 *
 * Works across app autoloader boundaries because PHP resolves methods
 * on the actual object at runtime, not the declared type hint.
 */
class LatchEvent extends Event
{
    private object $payload;

    /** @var list<mixed> */
    private array $collected = [];

    /** @var list<string> */
    private array $tags;

    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        private readonly string $sourceId,
        private readonly string $point,
        private readonly string $hookType,
        object $payload,
        array $tags = [],
    ) {
        parent::__construct();
        $this->payload = $payload;
        $this->tags = $tags;
    }

    public function getSourceId(): string
    {
        return $this->sourceId;
    }

    public function getPoint(): string
    {
        return $this->point;
    }

    public function getHookType(): string
    {
        return $this->hookType;
    }

    public function getPayload(): object
    {
        return $this->payload;
    }

    /**
     * Replace the payload (used by filter handlers to chain transformations).
     */
    public function setPayload(object $payload): void
    {
        $this->payload = $payload;
    }

    /**
     * Add items to the collected results (used by collect handlers).
     *
     * @param  list<mixed>  $items
     */
    public function addCollected(array $items): void
    {
        $this->collected = [...$this->collected, ...$items];
    }

    /**
     * @return list<mixed>
     */
    public function getCollected(): array
    {
        return $this->collected;
    }

    /**
     * @return list<string>
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * Build the conventional event name for NC's event dispatcher.
     */
    public static function eventName(string $sourceId, string $point): string
    {
        return "latch:{$sourceId}:{$point}";
    }
}
