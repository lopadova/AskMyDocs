<?php

declare(strict_types=1);

namespace App\ApiTools\Flow;

use Padosoft\LaravelFlow\Contracts\AuditRepository;
use Padosoft\LaravelFlow\Contracts\FlowStore;
use Padosoft\LaravelFlow\Contracts\PayloadRedactor;
use Padosoft\LaravelFlow\Contracts\RedactorAwareFlowStore;
use Padosoft\LaravelFlow\Contracts\RunNodeRepository;
use Padosoft\LaravelFlow\Contracts\RunRepository;

/** Keeps domain completion independent from optional graph broadcasts. */
final readonly class RuntimeFlowStore implements RedactorAwareFlowStore
{
    public function __construct(private FlowStore $inner) {}

    public function runs(): RunRepository { return new RuntimeRunRepository($this->inner->runs()); }
    public function runNodes(): RunNodeRepository { return $this->inner->runNodes(); }
    public function audit(): AuditRepository { return $this->inner->audit(); }
    public function transaction(callable $callback): mixed { return $this->inner->transaction($callback); }

    public function withPayloadRedactor(PayloadRedactor $redactor): FlowStore
    {
        return new self($this->inner instanceof RedactorAwareFlowStore ? $this->inner->withPayloadRedactor($redactor) : $this->inner);
    }
}
