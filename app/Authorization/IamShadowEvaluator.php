<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Models\User;

final class IamShadowEvaluator
{
    public function __construct(private readonly IamPdp $pdp) {}

    public function report(User $user, string $resource, string $action, mixed $subject = null): IamShadowReport
    {
        $definition = config("iam-shadow.resources.{$resource}.actions.{$action}");
        if (! is_array($definition)) {
            return new IamShadowReport(false, false, false, false, false, 'resource_not_mapped');
        }

        $authoritative = $this->authoritative($user, $resource, $action, $subject);

        try {
            $shadow = $this->pdp->decide($user, $resource, $action, $subject);
        } catch (\Throwable) {
            return new IamShadowReport(true, false, $authoritative, false, true, 'pdp_unavailable');
        }

        return new IamShadowReport(true, $shadow, $authoritative, $shadow, $authoritative !== $shadow, 'evaluated');
    }

    private function authoritative(User $user, string $resource, string $action, mixed $subject): bool
    {
        if ($resource === 'knowledge_document' && $subject !== null) {
            return app(SpatieIamPdp::class)->decide($user, $resource, $action, $subject);
        }

        return $user->can((string) config("iam-shadow.resources.{$resource}.actions.{$action}.permission", ''));
    }
}
