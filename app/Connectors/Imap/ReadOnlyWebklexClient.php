<?php

declare(strict_types=1);

namespace App\Connectors\Imap;

use ErrorException;
use RuntimeException;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;

/** Install the read-only protocol on every connection and reconnect. */
final class ReadOnlyWebklexClient extends Client
{
    public function __construct(Config $config, private readonly ?ImapSyncBudget $budget = null)
    {
        parent::__construct($config);
    }

    public function connect(): Client
    {
        $this->budget?->throwIfExpired();
        $this->disconnect();
        $this->connection = new ReadOnlyImapProtocol($this->config, $this->validate_cert, $this->encryption, $this->budget);
        $remaining = $this->budget?->remainingSeconds();
        $this->connection->setConnectionTimeout(max(1, (int) ceil(min($this->timeout, $remaining ?? $this->timeout))));
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
