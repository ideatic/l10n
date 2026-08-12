<?php

declare(strict_types=1);

namespace ideatic\l10n\Tools\Analysis;

/**
 * A specific problem found by a check
 */
class Issue
{
    /**
     * @param list<string> $locations Places ("file:line") where the affected string appears
     * @param list<string> $details   Extra detail lines shown below the message
     * @param float        $priority  Relative priority within the same check (higher = shown first)
     */
    public function __construct(
        public readonly string $check,
        public readonly Severity $severity,
        public readonly string $message,
        public readonly ?string $domain = null,
        public readonly ?string $stringID = null,
        public readonly ?string $locale = null,
        public readonly array $locations = [],
        public readonly array $details = [],
        public readonly float $priority = 0,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter(
            [
                'check' => $this->check,
                'severity' => $this->severity->value,
                'message' => $this->message,
                'domain' => $this->domain,
                'string' => $this->stringID,
                'locale' => $this->locale,
                'locations' => $this->locations,
                'details' => $this->details,
                'priority' => $this->priority,
            ],
            static fn(mixed $value): bool => $value !== null && $value !== [] && $value !== 0.0
        );
    }
}
