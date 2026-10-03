<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class OAuthAuthorizationCode extends Model
{
    use BelongsToTenant;

    protected $table = 'oauth_authorization_codes';
    protected $guarded = ['id'];
    protected $hidden = ['code_hash', 'code_challenge'];
    protected $casts = ['scopes' => 'array', 'expires_at' => 'datetime', 'consumed_at' => 'datetime'];
}
