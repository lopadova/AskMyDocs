<?php

namespace App\Services\ChatLog;

interface ChatLogDriverInterface
{
    /**
     * Persist a single chat log entry.
     */
    public function store(ChatLogEntry $entry): void;
}
