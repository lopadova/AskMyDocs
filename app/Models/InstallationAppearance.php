<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Installation-wide presentation only: deliberately independent of tenant data. */
final class InstallationAppearance extends Model
{
    protected $table = 'installation_appearance';

    public $incrementing = false;

    protected $fillable = ['id', 'theme', 'updated_by'];
}
