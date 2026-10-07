<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class OAuthClient extends Model
{
    use HasUuids;

    protected $table = 'oauth_clients';
    protected $fillable = ['name', 'redirect_uris', 'scopes', 'enabled'];
    protected $attributes = ['enabled' => true];
    protected $casts = ['redirect_uris' => 'array', 'scopes' => 'array', 'enabled' => 'boolean'];
}
