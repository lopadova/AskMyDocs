<?php

declare(strict_types=1);

namespace App\Support;

final class KbDiskWriteSafety
{
    public static function enforce(): void
    {
        $projectDisks = array_values((array) config('kb.project_disks', []));
        $disks = array_merge([
            config('kb.sources.disk'),
            config('kb.canonical_disk'),
        ], $projectDisks);
        $seen = [];

        foreach ($disks as $disk) {
            if (! is_string($disk) || $disk === '' || isset($seen[$disk])) {
                continue;
            }
            $seen[$disk] = true;
            if (! is_array(config("filesystems.disks.{$disk}"))) {
                continue;
            }

            config(["filesystems.disks.{$disk}.throw" => true]);
        }
    }
}
