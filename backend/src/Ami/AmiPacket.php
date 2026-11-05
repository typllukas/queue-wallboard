<?php

declare(strict_types=1);

namespace QueueWallboard\Ami;

final readonly class AmiPacket
{
    /**
     * @param array<string, string> $fields which fields exist depends on the event name, so they are typed where each event is handled
     */
    public function __construct(private array $fields) {}

    public function getField(string $name): string
    {
        return $this->findField($name) ?? throw new MissingAmiField(sprintf('AMI packet has no field "%s".', $name));
    }

    public function getIntegerField(string $name): int
    {
        $value = $this->getField($name);
        if (!ctype_digit($value)) {
            throw new MalformedAmiPacket(sprintf('AMI field "%s" is not a whole number: "%s".', $name, $value));
        }

        return intval($value);
    }

    public function findField(string $name): ?string
    {
        return $this->fields[$name] ?? null;
    }

    public function findEventName(): ?string
    {
        return $this->findField('Event');
    }

    public function findActionId(): ?string
    {
        return $this->findField('ActionID');
    }

    public function isSuccessResponse(): bool
    {
        return $this->findField('Response') === 'Success';
    }

    public function toWire(): string
    {
        $wire = '';
        foreach ($this->fields as $name => $value) {
            if (strpbrk($name . $value, "\r\n") !== false) {
                throw new MalformedAmiPacket(sprintf('AMI field "%s" contains a line break.', addcslashes($name, "\r\n")));
            }
            $wire .= $name . ': ' . $value . "\r\n";
        }

        return $wire . "\r\n";
    }
}
