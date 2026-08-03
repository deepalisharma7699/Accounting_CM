<?php

namespace App\Http\Resources;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A stored file, as a client sees it — M14.
 *
 * Note what is absent: `disk`, `path` and `checksum`. The key is how the
 * application fetches an object, and handing it to a browser turns a private
 * bucket into one where the only remaining protection is that the caller has to
 * be logged in. A client needs a URL it may use and nothing about where the
 * bytes live.
 *
 * @mixin Attachment
 */
class AttachmentResource extends JsonResource
{
    public function __construct(Attachment $attachment, private readonly ?string $url = null)
    {
        parent::__construct($attachment);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),

            'name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'size_label' => $this->humanSize(),
            'is_image' => $this->isImage(),

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            // The one a caller should branch on. Nothing may be *used* until the
            // object has been read back — M15 checks this before handing
            // anything to a model.
            'is_usable' => $this->isUsable(),
            // Why it did not store, kept on the row rather than only on the run,
            // because the run is pruned after ninety days and this is not.
            'error' => $this->meta['verification_error'] ?? null,

            // Always present, always through the application. Where the disk can
            // make a signed URL, `url` carries it too, and a client that has one
            // should prefer it — it keeps a workshop's photographs off this
            // server's bandwidth.
            'download_url' => route('api.attachments.download', ['attachment' => $this->id]),
            'url' => $this->url,

            // The verification run, so a screen polls one place for "did my
            // upload work" rather than diffing the attachment on a timer.
            'job' => $this->whenLoaded(
                'jobRun',
                fn () => $this->jobRun === null ? null : (new JobRunResource($this->jobRun))->resolve(request()),
            ),

            'uploaded_by' => $this->whenLoaded('uploader', fn () => $this->uploader?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
