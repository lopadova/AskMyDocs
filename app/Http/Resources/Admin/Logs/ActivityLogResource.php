<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Logs;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase H1 — admin Log Viewer, activity tab.
 *
 * Shape matches Spatie\Activitylog\Models\Activity. When the
 * `spatie/laravel-activitylog` package is not installed / its migration
 * hasn't run, the controller short-circuits to an empty payload with
 * an `activitylog not installed` note and this resource is never
 * invoked — see LogViewerController::activity().
 *
 * @property-read \Spatie\Activitylog\Models\Activity|object $resource
 */
class ActivityLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var object $activity */
        $activity = $this->resource;

        return [
            'id' => (int) $activity->id,
            'log_name' => $activity->log_name ?? null,
            'description' => (string) ($activity->description ?? ''),
            'subject_type' => $activity->subject_type ?? null,
            'subject_id' => $activity->subject_id ?? null,
            'event' => $activity->event ?? null,
            'causer_type' => $activity->causer_type ?? null,
            'causer_id' => $activity->causer_id ?? null,
            'properties' => $this->jsonishValue($activity->properties ?? null),
            'attribute_changes' => $this->jsonishValue($activity->attribute_changes ?? null),
            'created_at' => $this->datetimeValue($activity->created_at ?? null),
            'updated_at' => $this->datetimeValue($activity->updated_at ?? null),
        ];
    }

    /**
     * The Activity model casts `properties` / `attribute_changes` to a
     * Collection — we return plain arrays so the SPA JSON payload is
     * predictable.
     */
    private function jsonishValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if (is_object($value) && method_exists($value, 'toArray')) {
            return $value->toArray();
        }

        return $value;
    }

    private function datetimeValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_object($value) && method_exists($value, 'toIso8601String')) {
            return $value->toIso8601String();
        }

        return (string) $value;
    }
}
