<?php

declare(strict_types=1);

namespace Guardian\Domain;

final readonly class Finding
{
    /**
     * @param list<string> $evidence
     * @param list<string> $trace
     * @param list<string> $remediations
     * @param list<string> $tags
     */
    public function __construct(
        public RuleId $rule,
        public Severity $severity,
        public Confidence $confidence,
        public Location $location,
        public string $title,
        public string $message,
        public string $risk,
        public array $evidence,
        public array $trace,
        public array $remediations,
        public Fingerprint $fingerprint,
        public array $tags = [],
        public bool $suppressed = false,
        public ?string $suppressionReason = null,
    ) {}

    public function withSuppression(string $reason): self
    {
        return new self(
            $this->rule,
            $this->severity,
            $this->confidence,
            $this->location,
            $this->title,
            $this->message,
            $this->risk,
            $this->evidence,
            $this->trace,
            $this->remediations,
            $this->fingerprint,
            $this->tags,
            true,
            $reason,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(string $basePath = ''): array
    {
        return [
            'rule' => (string) $this->rule,
            'severity' => $this->severity->value,
            'confidence' => $this->confidence->value,
            'title' => $this->title,
            'message' => $this->message,
            'risk' => $this->risk,
            'location' => [
                'file' => $basePath !== '' ? $this->location->relativeTo($basePath) : $this->location->file,
                'line' => $this->location->line,
                'symbol' => $this->location->symbol,
            ],
            'evidence' => $this->evidence,
            'trace' => $this->trace,
            'remediations' => $this->remediations,
            'fingerprint' => (string) $this->fingerprint,
            'tags' => $this->tags,
            'suppressed' => $this->suppressed,
            'suppression_reason' => $this->suppressionReason,
        ];
    }
}
