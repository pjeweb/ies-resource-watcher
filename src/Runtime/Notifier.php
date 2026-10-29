<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\Runtime;

/** sd_notify(3) over the datagram socket systemd passes in NOTIFY_SOCKET. */
final class Notifier
{
    private readonly ?string $socket;

    public function __construct(?string $socket = null)
    {
        $socket ??= getenv('NOTIFY_SOCKET') ?: null;
        $this->socket = $socket !== null && str_starts_with($socket, '@') ? "\0" . substr($socket, 1) : $socket;
    }

    public function notify(string $message): void
    {
        if ($this->socket === null) {
            return;
        }
        $client = @stream_socket_client('udg://' . $this->socket, $code, $error, 1.0);
        if ($client !== false) {
            @fwrite($client, $message);
            fclose($client);
        }
    }
}
