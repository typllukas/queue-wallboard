<?php

declare(strict_types=1);

namespace QueueWallboard\Ami;

/**
 * Follows the clue/ami-react v1.3.0 parser, also in reading a missing space after the colon, an empty value and a
 * stray blank line (refusing drops the connection). No banner here: AmiClient strips it, actions come without one.
 */
final class AmiPacketParser
{
    /** far above any real packet; a PBX that never ends one would fill the memory */
    private const int MAX_UNFINISHED_PACKET_BYTES = 1_048_576;

    private string $unfinishedPacket = '';

    /**
     * @return list<AmiPacket>
     * @throws MalformedAmiPacket
     */
    public function pushChunk(string $chunk): array
    {
        $packetTexts = explode("\r\n\r\n", $this->unfinishedPacket . $chunk);
        $this->unfinishedPacket = array_pop($packetTexts);
        if (strlen($this->unfinishedPacket) > self::MAX_UNFINISHED_PACKET_BYTES) {
            throw new MalformedAmiPacket(sprintf('AMI packet longer than %d bytes.', self::MAX_UNFINISHED_PACKET_BYTES));
        }

        $packets = [];
        foreach ($packetTexts as $packetText) {
            if (trim($packetText) === '') {
                continue;
            }
            $packets[] = $this->parsePacket($packetText);
        }

        return $packets;
    }

    private function parsePacket(string $packetText): AmiPacket
    {
        $fields = [];
        foreach (explode("\r\n", $packetText) as $line) {
            if ($line === '') {
                continue;
            }
            $separatorPosition = strpos($line, ':');
            if ($separatorPosition === false) {
                throw new MalformedAmiPacket(sprintf('AMI line without ":": "%s".', $line));
            }
            $value = substr($line, $separatorPosition + 1);
            /*
             * Unlike clue/ami-react, a repeated key keeps its last value, so every field stays one string. Asterisk 22
             * repeats only ChanVariable and Output, neither is read; reading channel variables would need every value.
             */
            $fields[substr($line, 0, $separatorPosition)] = str_starts_with($value, ' ') ? substr($value, 1) : $value;
        }

        return new AmiPacket($fields);
    }
}
