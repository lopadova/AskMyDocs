<?php

declare(strict_types=1);

namespace App\Connectors\Imap;

use ErrorException;
use RuntimeException;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;

/** Install the read-only protocol on every connection and reconnect. */
final class ReadOnlyWebklexClient extends Client
{
    public function connect(): Client
    {
        $this->disconnect();
        $this->connection = new ReadOnlyImapProtocol($this->config, $this->validate_cert, $this->encryption);
        $this->connection->setConnectionTimeout($this->timeout);
        $this->connection->setProxy($this->proxy);
        $this->connection->setSslOptions($this->ssl_options);
        if ($this->config->get('options.debug')) {
            $this->connection->enableDebug();
        }
        if (! $this->config->get('options.uid_cache')) {
            $this->connection->disableUidCache();
        }
        try {
            $this->connection->connect($this->host, $this->port);
        } catch (ErrorException|RuntimeException $exception) {
            throw new ConnectionFailedException('connection setup failed', 0, $exception);
        }
        $this->authenticate();

        return $this;
    }
}
