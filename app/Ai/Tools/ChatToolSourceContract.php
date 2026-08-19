<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Models\User;

interface ChatToolSourceContract
{
    public function key(): string;

    /**
     * @return list<array<string, mixed>>
     */
    public function catalog(User $user, ?string $projectKey = null): array;

    /**
     * @param  array<string, mixed>  $tool
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $context
     */
    public function invoke(array $tool, array $arguments, User $user, array $context = []): ChatToolInvocationResult;
}
