<?php

namespace Tests\Feature\Async;

use App\Enums\AttachmentKind;
use App\Enums\AttachmentStatus;
use App\Enums\AuditAction;
use App\Enums\AuditResource;
use App\Enums\JobStatus;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\JobRun;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Object storage, end to end — M14.
 *
 * Uploads go to a faked disk rather than to a mock of the storage service: what
 * is under test is that the bytes land under the right key and can be read back
 * again, and a mock would assert only that the application called the method it
 * was written to call.
 */
class AttachmentTest extends TestCase
{
    use InteractsWithAuthModule, InteractsWithTenancy, RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');

        [$this->tenant, $this->owner] = $this->tenantWithUser([
            ['READ', 'ATTACHMENTS'], ['WRITE', 'ATTACHMENTS'], ['DELETE', 'ATTACHMENTS'],
            ['READ', 'JOBS'], ['READ', 'AUDIT'],
        ]);
    }

    /* ---------------------------------------------------------------------
     | Storing
     |-------------------------------------------------------------------- */

    #[Test]
    public function an_upload_is_stored_and_verified_in_the_background(): void
    {
        $response = $this->upload()->assertCreated();

        $id = $response->json('data.id');

        // The response comes back before anything has been read back out of the
        // store — the row says `pending`, and the handle to watch is in the meta.
        $this->assertNotNull($response->json('meta.job.id'));

        $attachment = $this->attachment($id);

        // QUEUE_CONNECTION is `sync` under test, so the job has already run by
        // the time the response returns; in production this is the poll.
        $this->assertSame(AttachmentStatus::Ready, $attachment->status);
        $this->assertTrue($attachment->isUsable());

        Storage::disk('documents')->assertExists($attachment->path);

        $run = $this->actingForTenant($this->tenant, fn () => JobRun::query()
            ->where('uuid', $response->json('meta.job.id'))
            ->firstOrFail());

        $this->assertSame(JobStatus::Succeeded, $run->status);
        $this->assertTrue($run->result['verified']);
    }

    #[Test]
    public function the_object_key_carries_the_tenant_and_never_the_uploaded_filename(): void
    {
        $id = $this->upload(UploadedFile::fake()->image('../../etc/passwd.jpg'))
            ->assertCreated()
            ->json('data.id');

        $attachment = $this->attachment($id);

        // Storage is the one place a bug is not caught by the tenant scope — an
        // object key is a string, and a string assembled wrongly reaches
        // whatever it names.
        $this->assertStringStartsWith("tenants/{$this->tenant->id}/invoice_image/", $attachment->path);

        // The name the client sent never touches the key, and the extension
        // comes from the verified media type rather than from the filename.
        $this->assertStringNotContainsString('passwd', $attachment->path);
        $this->assertStringNotContainsString('..', $attachment->path);
        $this->assertStringEndsWith('.jpg', $attachment->path);
        $this->assertSame('image/jpeg', $attachment->mime_type);
    }

    #[Test]
    public function a_type_outside_the_allow_list_is_refused_and_stores_nothing(): void
    {
        $response = $this->upload(UploadedFile::fake()->create('books.xlsx', 40, 'application/vnd.ms-excel'))
            ->assertStatus(422);

        $this->assertSame('FILE_TYPE_UNSUPPORTED', $response->json('error.code'));

        // Refused before anything is written, so a rejected file leaves neither
        // an object nor a row behind.
        $this->assertSame(0, $this->actingForTenant($this->tenant, fn () => Attachment::query()->count()));
        $this->assertSame([], Storage::disk('documents')->allFiles());
    }

    #[Test]
    public function a_file_over_its_kinds_ceiling_is_refused_with_the_limit_named(): void
    {
        $overSized = (int) ceil(AttachmentKind::Audio->maxBytes() / 1024) + 64;

        $response = $this->upload(
            UploadedFile::fake()->create('long-recording.mp3', $overSized, 'audio/mpeg'),
            AttachmentKind::Audio,
        )->assertStatus(422);

        $this->assertSame('FILE_TOO_LARGE', $response->json('error.code'));
        // The message names the limit, because "unsupported file" tells somebody
        // standing at a counter with a phone nothing they can act on.
        $this->assertStringContainsString('MB', $response->json('error.message'));
        $this->assertSame(AttachmentKind::Audio->maxBytes(), $response->json('error.details.max_bytes'));
    }

    #[Test]
    public function the_declared_kind_is_checked_against_the_bytes(): void
    {
        // A real image, declared as audio. The kind decides how M15 will try to
        // read the file, so a caller cannot simply assert one.
        $this->upload(UploadedFile::fake()->image('invoice.jpg'), AttachmentKind::Audio)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'FILE_TYPE_UNSUPPORTED');
    }

    #[Test]
    public function uploading_the_same_file_twice_is_reported_and_not_refused(): void
    {
        $file = UploadedFile::fake()->image('invoice.jpg');

        $first = $this->upload($file)->assertCreated();
        $second = $this->upload(clone $file)->assertCreated();

        // Both exist. Quietly handing back the first row would create a file two
        // things point at and either may delete — the same treatment a shared
        // GSTIN gets in M5.
        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame($first->json('data.id'), $second->json('meta.duplicates.0.id'));
        $this->assertSame([], $first->json('meta.duplicates'));
    }

    /* ---------------------------------------------------------------------
     | Reading and removing
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_file_downloads_as_an_attachment_under_its_original_name(): void
    {
        $id = $this->upload(UploadedFile::fake()->image('bharat-motors-14-mar.jpg'))
            ->assertCreated()
            ->json('data.id');

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->get("/api/v1/attachments/{$id}/download")
            ->assertOk();

        // `attachment`, never `inline`: nothing a workshop uploads may be
        // rendered by a browser inside this application's origin.
        $this->assertStringContainsString('attachment;', $response->headers->get('content-disposition'));
        $this->assertStringContainsString('bharat-motors-14-mar.jpg', $response->headers->get('content-disposition'));
        $this->assertSame('nosniff', $response->headers->get('x-content-type-options'));
    }

    #[Test]
    public function the_object_key_is_never_sent_to_a_client(): void
    {
        $id = $this->upload()->assertCreated()->json('data.id');

        $body = $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/attachments/{$id}")
            ->assertOk()
            ->json('data');

        // A key is how the application fetches an object. Handing one to a
        // browser turns a private bucket into one whose only protection is that
        // the caller happened to be logged in.
        $this->assertArrayNotHasKey('path', $body);
        $this->assertArrayNotHasKey('disk', $body);
        $this->assertArrayNotHasKey('checksum', $body);
        $this->assertNotEmpty($body['download_url']);
    }

    #[Test]
    public function deleting_removes_the_object_and_lands_on_the_audit_trail(): void
    {
        $id = $this->upload(UploadedFile::fake()->image('one-off.jpg'))
            ->assertCreated()
            ->json('data.id');

        $path = $this->attachment($id)->path;

        $this->withHeaders($this->authHeader($this->owner))
            ->deleteJson("/api/v1/attachments/{$id}")
            ->assertOk();

        $this->assertNull($this->actingForTenant($this->tenant, fn () => Attachment::find($id)));
        Storage::disk('documents')->assertMissing($path);

        // The one thing worth recording about a stored file: unlike an archived
        // party, it leaves nothing behind at all.
        $entry = $this->actingForTenant($this->tenant, fn () => AuditLog::query()
            ->forResource(AuditResource::Attachment, $id)
            ->newestFirst()
            ->firstOrFail());

        $this->assertSame(AuditAction::Deleted, $entry->action);
        $this->assertSame('one-off.jpg', $entry->label);
        $this->assertSame($this->owner->id, $entry->actor_id);
    }

    /* ---------------------------------------------------------------------
     | Authority and isolation
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_data_entry_user_may_upload_but_not_delete(): void
    {
        $clerk = $this->actingForTenant($this->tenant, fn () => User::factory()
            ->forTenant($this->tenant)
            ->withRole($this->roleWith(
                [['READ', 'ATTACHMENTS'], ['WRITE', 'ATTACHMENTS'], ['READ', 'JOBS']],
                'Clerk Role',
            ))
            ->create());

        // The person holding the paper invoice is the person who photographs it.
        $id = $this->withHeaders($this->authHeader($clerk))
            ->post('/api/v1/attachments', [
                'file' => UploadedFile::fake()->image('counter.jpg'),
                'kind' => AttachmentKind::InvoiceImage->value,
            ])
            ->assertCreated()
            ->json('data.id');

        // Removing evidence is not data entry.
        $this->withHeaders($this->authHeader($clerk))
            ->deleteJson("/api/v1/attachments/{$id}")
            ->assertForbidden();
    }

    #[Test]
    public function there_is_no_way_to_edit_a_stored_file(): void
    {
        $id = $this->upload()->assertCreated()->json('data.id');

        // A file's bytes do not change, so there is no route to edit one. A 405
        // rather than a 404: the URI exists for GET and DELETE, and the verb is
        // what is refused.
        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/attachments/{$id}", ['name' => 'Renamed'])
            ->assertStatus(405);

        $this->withHeaders($this->authHeader($this->owner))
            ->putJson("/api/v1/attachments/{$id}", ['name' => 'Renamed'])
            ->assertStatus(405);

        // And no grant that could ever authorise one. The catalogue is seeded
        // here so this asserts against a populated table rather than an empty
        // one, which would pass for the wrong reason.
        $this->seed(\Database\Seeders\PermissionSeeder::class);

        $grants = \App\Models\Permission::all()->map(fn ($p) => $p->action.':'.$p->resource)->all();

        $this->assertContains('WRITE:ATTACHMENTS', $grants);
        $this->assertNotContains('UPDATE:ATTACHMENTS', $grants);
    }

    #[Test]
    public function one_workshops_files_are_invisible_to_another(): void
    {
        $mine = $this->upload()->assertCreated()->json('data.id');

        [$other, $stranger] = $this->tenantWithUser(
            [['READ', 'ATTACHMENTS'], ['DELETE', 'ATTACHMENTS']],
            'Stranger Role',
        );

        foreach (['getJson', 'deleteJson'] as $method) {
            $this->withHeaders($this->authHeader($stranger))
                ->{$method}("/api/v1/attachments/{$mine}")
                ->assertNotFound();
        }

        $this->withHeaders($this->authHeader($stranger))
            ->getJson('/api/v1/attachments')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // And the object is still there — a refused read must not be a deletion.
        Storage::disk('documents')->assertExists($this->attachment($mine)->path);
        $this->assertNotNull($other);
    }

    /* ---------------------------------------------------------------------
     | Helpers
     |-------------------------------------------------------------------- */

    private function upload(?UploadedFile $file = null, ?AttachmentKind $kind = null): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->authHeader($this->owner))
            ->post('/api/v1/attachments', [
                'file' => $file ?? UploadedFile::fake()->image('invoice.jpg'),
                'kind' => ($kind ?? AttachmentKind::InvoiceImage)->value,
            ]);
    }

    private function attachment(int $id): Attachment
    {
        return $this->actingForTenant($this->tenant, fn () => Attachment::findOrFail($id));
    }
}
