<?php

declare(strict_types=1);

namespace App\Pact\Native;

use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Auth\OAuthAccessPolicy;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as Identity;
use Illuminate\Database\Eloquent\Model;
use Padosoft\AiActCompliance\Consent\Models\ConsentRecord;

/** A Passport-compatible subject, stable for a consenting user and project. */
final class Subject extends Model implements Identity
{
    use Authenticatable;

    public const PROJECT_ROLES = ['owner', 'admin', 'member'];

    public $timestamps = false;

    protected $table = 'pact_client_subjects';

    protected $fillable = ['user_id', 'tenant_id', 'project_key'];

    public function authorizedUser(): ?User
    {
        $user = User::query()->find($this->user_id);
        if ($user === null || $user->email_verified_at === null || in_array($this->project_key, ['', '*'], true)
            || ! app(OAuthAccessPolicy::class)->canUseTenant($user, $this->tenant_id)
            || ! ProjectMembership::query()->forTenant($this->tenant_id)->where('user_id', $this->user_id)
                ->where('project_key', $this->project_key)->whereIn('role', self::PROJECT_ROLES)->exists()) {
            return null;
        }
        $feature = (string) config('ai-act-compliance.consent.gate_chat_feature', '');
        if ($feature !== '' && ! ConsentRecord::query()->where('user_id', $user->id)
            ->where('feature', $feature)->where('granted', true)->whereNull('revoked_at')->exists()) {
            return null;
        }

        return $user;
    }
}
