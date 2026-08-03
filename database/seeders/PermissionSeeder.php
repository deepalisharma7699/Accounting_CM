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

                // Accounts are archived, never deleted: an account that has
                // been posted to must survive so its journal entries keep
                // their name. A DELETE grant would be unusable.
                if ($resource === PermissionResource::Accounts && $action === PermissionAction::Delete) {
                    continue;
                }

                // The ledger, the stock position, the audit trail and the job
                // list are read-only to everybody. Every one of them is written
                // by machinery rather than by a request — the posting engine,
                // the audit recorder's model events, the queue — and each is
                // refused an UPDATE and a DELETE on the model itself, so a
                // WRITE, UPDATE or DELETE grant here would be a grant over
                // something that cannot happen. Stock is *moved* by posting a
                // transaction; a job is *created* by the act that queues it.
                if (in_array($resource, [
                    PermissionResource::Ledger,
                    PermissionResource::Stock,
                    PermissionResource::Audit,
                    PermissionResource::Jobs,
                ], true) && $action !== PermissionAction::Read) {
                    continue;
                }

                // A stored file's bytes never change: correcting a bad
                // photograph of an invoice means taking another one. READ,
                // WRITE and DELETE are all meaningful; UPDATE is authority over
                // an operation that does not exist.
                if ($resource === PermissionResource::Attachments && $action === PermissionAction::Update) {
                    continue;
                }

                // A workshop has exactly one workspace and does not create or
                // destroy it — provisioning and deletion are TENANTS, a
                // platform concern. Only READ and UPDATE are meaningful.
                if ($resource === PermissionResource::Workspace
                    && ! in_array($action, [PermissionAction::Read, PermissionAction::Update], true)) {
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
