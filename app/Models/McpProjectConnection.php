<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class McpProjectConnection extends Model
{
    use HasUuids;

    protected $guarded = [];

    public function resource(): string
    {
        return rtrim((string) config('app.url'), '/').'/mcp/knowledge/'.$this->id;
    }
}
