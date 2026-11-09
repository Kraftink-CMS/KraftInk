<?php

namespace App\Kraft\Games\Transports;

use App\Exceptions\TransportFailure;
use xPaw\SourceQuery\BaseSocket;
use xPaw\SourceQuery\Buffer;
use xPaw\SourceQuery\SourceQuery;

class SourceQuerySocket extends BaseSocket
{
    private const string SINGLE_PACKET = "\xff\xff\xff\xff";

    private const string SPLIT_PACKET = "\xfe\xff\xff\xff";

    private DatagramConnection $connection;

    public function __construct(private readonly SocketTarget $target)
    {
    }

    public function Open(string $Address, int $Port, int $Timeout, int $Engine): void
    {
        $this->Address = $Address;
        $this->Port = $Port;
        $this->Timeout = $Timeout;
        $this->Engine = $Engine;
        $this->connection = $this->target->datagram($Address, $Port);
    }

    public function Close(): void
    {
        if (isset($this->connection)) {
            $this->connection->close();
            unset($this->connection);
        }
    }

    public function Write(int $Header, string $String = ''): bool
    {
        $this->connection->send(self::SINGLE_PACKET.chr($Header).$String);

        return true;
    }

    public function Read(): Buffer
    {
        $packet = $this->connection->receive();
        $pending = [];
        if (str_starts_with($packet, self::SPLIT_PACKET)) {
            $pending = $this->orderedPackets($packet);
            $packet = array_shift($pending);
        }
        $buffer = new class extends Buffer
        {
            public function ReadNullTermString(): string
            {
                $remaining = $this->Remaining();
                $value = parent::ReadNullTermString();
                if ($this->Remaining() === $remaining || strlen($value) > 8192) {
                    throw new TransportFailure('transports.invalid_response');
                }

                return $value;
            }
        };
        $buffer->Set($packet);
        $this->ReadInternal($buffer, static function (Buffer $buffer) use (&$pending): bool {
            $packet = array_shift($pending);
            if ($packet === null) {
                return false;
            }
            $buffer->Set($packet);
            $buffer->ReadInt32();

            return true;
        });
        $payload = $buffer->Read();
        if (str_starts_with($payload, chr(SourceQuery::S2C_CHALLENGE)) && strlen($payload) !== 5) {
            throw new TransportFailure('transports.invalid_response');
        }
        $buffer->Set($payload);

        return $buffer;
    }

    private function orderedPackets(string $packet): array
    {
        if (strlen($packet) < 12 || (ord($packet[7]) & 0x80) !== 0) {
            throw new TransportFailure('transports.invalid_response');
        }
        $header = substr($packet, 0, 9);
        $total = ord($packet[8]);
        if ($total < 1 || $total > 32) {
            throw new TransportFailure('transports.invalid_response');
        }
        $packets = [];
        $payloadBytes = 0;
        for ($attempt = 0; $attempt < 64; $attempt++) {
            if ($attempt > 0) {
                $packet = $this->connection->receive();
            }
            if (strlen($packet) < 12 || !str_starts_with($packet, $header)) {
                throw new TransportFailure('transports.invalid_response');
            }
            $index = ord($packet[9]);
            $payloadBytes += strlen($packet) - (isset($packets[$index]) ? strlen($packets[$index]) : 12);
            if ($index >= $total || $payloadBytes > 131072) {
                throw new TransportFailure('transports.invalid_response');
            }
            $packets[$index] = $packet;
            if (count($packets) === $total) {
                if (!str_starts_with(substr($packets[0], 12), self::SINGLE_PACKET)) {
                    throw new TransportFailure('transports.invalid_response');
                }
                ksort($packets);

                return $packets;
            }
        }

        throw new TransportFailure('transports.invalid_response');
    }
}
