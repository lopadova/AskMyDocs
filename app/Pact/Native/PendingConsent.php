<?php

declare(strict_types=1);

namespace App\Pact\Native;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class PendingConsent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'pact_client_consents';

    protected $fillable = ['user_id', 'session_hash', 'parameters', 'expires_at'];

    protected $casts = ['parameters' => 'encrypted:array', 'passport' => 'encrypted:array', 'expires_at' => 'immutable_datetime'];

    public static function start(Request $request, User $user, array $parameters): self
    {
        self::query()->where('expires_at', '<=', now())->delete();

        return self::query()->create([
            'user_id' => $user->id,
            'session_hash' => self::sessionHash($request),
            'parameters' => $parameters,
            'expires_at' => now()->addMinutes(10),
        ]);
    }

    public static function fromRequest(Request $request, User $user): ?self
    {
        $id = $request->input('pact_consent');

        return is_string($id) && Str::isUuid($id)
            ? self::query()->useWritePdo()->where('user_id', $user->id)->where('session_hash', self::sessionHash($request))
                ->where('expires_at', '>', now())->find($id)
            : null;
    }

    public function pending(): Builder
    {
        return self::query()->useWritePdo()->whereKey($this->getKey())->where('expires_at', '>', now());
    }

    private static function sessionHash(Request $request): string
    {
        return hash('sha256', $request->session()->getId());
    }
}
