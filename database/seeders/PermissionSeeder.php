<?php

namespace Database\Seeders;

use App\Enums\PermissionAction;
use App\Enums\PermissionResource;
use App\Models\Permission;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * The permission catalogue. Idempotent — safe to re-run after adding a new
 * resource, and it never deletes grants that other modules may have seeded.
 */
class PermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Actions seeded for every resource below.
     *
     * @var array<int, PermissionAction>
     */
    private const ACTIONS = [
        PermissionAction::Read,
        PermissionAction::Write,
        PermissionAction::Update,
        PermissionAction::Delete,
    ];

    public function run(): void
    {
        $wildcard = (string) config('rbac.wildcard', '*');

        // Full-access grant. Held only by the ADMIN system role.
        Permission::updateOrCreate(
            ['action' => $wildcard, 'resource' => $wildcard],
            ['description' => 'Full access to every action on every resource.']
        );

        foreach (PermissionResource::cases() as $resource) {
            foreach (self::ACTIONS as $action) {
                // The permission catalogue is read-only through the API, so
                // DELETE:PERMISSIONS etc. would be unusable — skip them.
                if ($resource === PermissionResource::Permissions && $action !== PermissionAction::Read) {
                    continue;
                }

                Permission::updateOrCreate(
                    ['action' => $action->value, 'resource' => $resource->value],
                    ['description' => $this->describe($action, $resource)]
                );
            }
        }
    }

    private function describe(PermissionAction $action, PermissionResource $resource): string
    {
        $subject = strtolower($resource->value);

        return match ($action) {
            PermissionAction::Read => "View {$subject}.",
            PermissionAction::Write => "Create {$subject}.",
            PermissionAction::Update => "Modify existing {$subject}.",
            PermissionAction::Delete => "Delete {$subject}.",
            PermissionAction::Manage => "Full control over {$subject}.",
        };
    }
}
