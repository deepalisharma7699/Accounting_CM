<?php

namespace App\Http\Requests\Attachment;

use App\Enums\AttachmentKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * An upload — M14.
 *
 * Deliberately thin. It checks that a file arrived and that the caller named a
 * kind this application knows; everything about the *bytes* — the media type,
 * the size ceiling — is checked in {@see \App\Services\Storage\FileStorageService},
 * for the reason M7 gave about item attributes: the rule has to bind every path
 * into the store, not just this form. M15's capture agent will hand files
 * straight to the service, and it must be held to exactly the same list.
 *
 * The ceilings are also per kind and configurable, and a `max:` rule here would
 * be a second copy of numbers that live in `config/attachments.php` — one of
 * which would eventually be raised without the other.
 */
class StoreAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file'],
            'kind' => ['required', Rule::in(AttachmentKind::values())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose a file to upload.',
            'kind.required' => 'Say what this file is — an invoice image, audio, or a document.',
            'kind.in' => 'That is not a kind of file this workshop can store.',
        ];
    }

    public function kind(): AttachmentKind
    {
        return AttachmentKind::from((string) $this->input('kind'));
    }

    public function uploadedFile(): UploadedFile
    {
        /** @var UploadedFile $file */
        $file = $this->file('file');

        return $file;
    }
}
