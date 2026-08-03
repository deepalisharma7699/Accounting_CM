<?php

namespace App\Jobs;

use App\Enums\AttachmentStatus;
use App\Exceptions\ResourceNotFoundException;
use App\Repositories\Contracts\AttachmentRepositoryInterface;
use App\Services\Storage\FileStorageService;

/**
 * Read a freshly uploaded file back out of object storage and confirm it is the
 * file that was sent — M14.
 *
 * The first real job in the product, and deliberately a small one. It exists
 * because a write to object storage can succeed and leave nothing readable: a
 * wrong bucket, a policy that covers writes and not reads, a region that has not
 * caught up. Every one of those is silent at upload time and fatal three weeks
 * later, when somebody opens the purchase to check what they were charged.
 *
 * It is also the shape every M15 job will take, which is why it is worth having
 * now rather than later. The upload request does the minimum it cannot avoid —
 * move the bytes, write the row — and hands back a handle; everything after that
 * happens on a worker and is watched through {@see \App\Models\JobRun}. Reading
 * the invoice with a model is the same arrangement with a slower step in the
 * middle.
 */
class ProcessAttachment extends TrackedJob
{
    public function __construct(public readonly int $attachmentId)
    {
        // Parent last: it opens the run row, and the payload it records is built
        // from this object's own state.
        parent::__construct(['attachment_id' => $attachmentId]);
    }

    public static function jobType(): string
    {
        return 'attachment.process';
    }

    /**
     * @return array<string, mixed>
     */
    protected function run(JobProgress $progress): array
    {
        $attachments = app(AttachmentRepositoryInterface::class);
        $storage = app(FileStorageService::class);

        // Found through the tenant scope, which TrackedJob has re-established.
        // A job somehow running as the wrong workshop finds nothing and fails
        // here rather than reading another workshop's file.
        $attachment = $attachments->findById($this->attachmentId)
            ?? throw new ResourceNotFoundException('Attachment', $this->attachmentId);

        $progress->message('Checking the stored file…');

        $outcome = $storage->verify($attachment);

        $attachments->recordVerification(
            $attachment,
            $outcome['ok'] ? AttachmentStatus::Ready->value : AttachmentStatus::Failed->value,
            $outcome['ok']
                ? $outcome['meta']
                // Kept on the row as well as on the run, because the run is
                // pruned after ninety days and the attachment is not. A file
                // that says "not stored" has to be able to say why for as long
                // as it exists.
                : ['verification_error' => $outcome['reason']],
        );

        if (! $outcome['ok']) {
            // Not thrown. The job did its work correctly and found a bad file —
            // that is a successful check with an unwelcome answer, and throwing
            // would send it round the retry loop twice to reach the same
            // conclusion. The failure belongs on the attachment, where the
            // person who uploaded it will look, not in `failed_jobs`.
            $progress->message($outcome['reason'] ?? 'The stored file could not be confirmed.');
        }

        return [
            'attachment_id' => $attachment->id,
            'verified' => $outcome['ok'],
            'reason' => $outcome['reason'],
        ];
    }
}
