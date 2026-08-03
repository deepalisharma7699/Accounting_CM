<?php

namespace App\Repositories\Contracts;

use App\Models\Attachment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Stored files, as rows.
 *
 * There is no `update` beyond the two the module actually performs — pointing a
 * row at its verification run, and recording the outcome of that run. A general
 * update would be a way to change what a row says about bytes that cannot
 * change, which is the one thing this module is built around.
 */
interface AttachmentRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Attachment;

    public function findById(int $id): ?Attachment;

    /**
     * @param  array{kind?: string|null, status?: string|null, search?: string|null}  $filters
     * @return LengthAwarePaginator<int, Attachment>
     */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator;

    /**
     * Other rows in this workshop holding the same bytes.
     *
     * Reported to the uploader and acted on by nobody — see
     * {@see Attachment::scopeMatching()} on why a duplicate is a notice rather
     * than a refusal or a silent merge.
     *
     * @return Collection<int, Attachment>
     */
    public function duplicatesOf(string $checksum, ?int $exceptId = null): Collection;

    /**
     * Attach the verification run. Its own method rather than a general update,
     * because it is one of exactly two things that may be written to a row after
     * it exists.
     */
    public function attachJobRun(Attachment $attachment, int $jobRunId): Attachment;

    /**
     * Record what reading the object back proved. The other of the two.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public function recordVerification(Attachment $attachment, string $status, ?array $meta = null): Attachment;

    public function delete(Attachment $attachment): void;
}
