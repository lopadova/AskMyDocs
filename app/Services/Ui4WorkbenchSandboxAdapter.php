<?php

namespace App\Services;

use App\Models\Ui4WorkbenchSandboxObject;
use Ui4\Workbench\Contracts\HostArchivableRecordAdapter;

final class Ui4WorkbenchSandboxAdapter implements HostArchivableRecordAdapter
{
    public function __construct(
        private readonly Ui4WorkbenchScope $scopes,
    ) {}

    public function supports(string $type): bool
    {
        return true;
    }

    public function find(string $type, string $id, string $actor): ?array
    {
        $identity = $this->scopes->requireCurrent($actor, request());
        $object = Ui4WorkbenchSandboxObject::query()
            ->whereKey($id)
            ->where('tenant_id', $identity['tenant_id'])
            ->where('user_id', $identity['user_id'])
            ->where('type', $type)
            ->whereNull('archived_at')
            ->first();

        return $object === null ? null : $this->payload($object);
    }

    public function save(string $type, ?string $id, string $title, array $data, string $actor): array
    {
        $identity = $this->scopes->requireCurrent($actor, request());
        $object = $id === null
            ? new Ui4WorkbenchSandboxObject([
                'tenant_id' => $identity['tenant_id'],
                'user_id' => $identity['user_id'],
                'type' => $type,
            ])
            : Ui4WorkbenchSandboxObject::query()
                ->whereKey($id)
                ->where('tenant_id', $identity['tenant_id'])
                ->where('user_id', $identity['user_id'])
                ->where('type', $type)
                ->whereNull('archived_at')
                ->firstOrFail();

        $object->fill([
            'title' => $title,
            'data' => $data,
        ]);
        $object->save();

        return $this->payload($object);
    }

    public function archive(string $type, string $id, string $actor): array
    {
        $identity = $this->scopes->requireCurrent($actor, request());
        $object = Ui4WorkbenchSandboxObject::query()
            ->whereKey($id)
            ->where('tenant_id', $identity['tenant_id'])
            ->where('user_id', $identity['user_id'])
            ->where('type', $type)
            ->whereNull('archived_at')
            ->firstOrFail();

        $object->forceFill(['archived_at' => now()])->save();

        return [
            ...$this->payload($object),
            'archived_at' => $object->archived_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{id: string, title: string, data: array<string, mixed>}
     */
    private function payload(Ui4WorkbenchSandboxObject $object): array
    {
        return [
            'id' => (string) $object->getKey(),
            'title' => $object->title,
            'data' => $object->data,
        ];
    }
}
