<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Http\Requests\Auth\CompleteCompanyOnboardingRequest;
use App\Http\Resources\Auth\CompanyOnboardingResource;
use App\Services\Auth\CompanyOnboardingNotRequired;
use App\Services\Auth\CompanyOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

final class CompanyOnboardingController extends Controller
{
    public function __invoke(
        CompleteCompanyOnboardingRequest $request,
        CompanyOnboardingService $onboarding,
    ): CompanyOnboardingResource|JsonResponse {
        $data = $request->validated();

        try {
            $result = $onboarding->complete(
                $request->user(),
                (string) $data['company_name'],
                isset($data['tenant_slug']) ? (string) $data['tenant_slug'] : null,
                isset($data['project_key']) ? (string) $data['project_key'] : null,
            );
        } catch (CompanyOnboardingNotRequired $e) {
            return response()->json([
                'error' => 'onboarding_not_required',
                'message' => $e->getMessage(),
            ], Response::HTTP_CONFLICT);
        }

        return new CompanyOnboardingResource($result);
    }
}
