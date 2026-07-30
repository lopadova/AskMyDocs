<?php

declare(strict_types=1);

namespace App\Http\Resources\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Symfony\Component\HttpFoundation\Response;

final class CompanyOnboardingResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'tenant' => $this->resource['tenant'],
            'project' => $this->resource['project'],
            'onboarding_required' => false,
        ];
    }

    public function withResponse(Request $request, $response): void
    {
        $response->setStatusCode(Response::HTTP_CREATED);
    }
}
