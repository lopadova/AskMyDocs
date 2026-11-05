<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class OAuthRefreshToken extends Model
{
    protected $table = 'oauth_refresh_tokens';
    protected $guarded = ['id'];
    protected $hidden = ['token_hash'];
    protected $casts = ['expires_at' => 'datetime', 'consumed_at' => 'datetime'];
}
