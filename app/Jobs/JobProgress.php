<?php

namespace App\Jobs;

use App\Models\JobRun;
use App\Services\Jobs\JobRunService;

/**
 * The handle a running job reports through — M14.
 *
 * Handed to {@see TrackedJob::run()} so a job never touches {@see JobRun}
 * itself, and never has to know the lifecycle: it says how much work there is
 * and how much is done, and everything else — the percentage, the status
 * transitions, the failure record — is somebody else's business.
 *
 * ## Why the writes are throttled
 *
 * Because the obvious implementation makes the job slower than the work. A row
 * update per item turns a 400-line import into 400 extra round trips to the
 * database, so a job that reports diligently is punished for it — and the
 * display gains nothing, since nobody can perceive 400 steps in four seconds.
 *
 * So a step is only persisted when the whole percentage moves, which is at most
 * a hundred writes over any job of any size. A message is always persisted,
 * because a job that says something is saying it deliberately.
 */
final class JobProgress
{
    private int $processed = 0;

    private ?int $total = null;

    /** The percentage last written, so a step that does not move it costs nothing. */
    private int $lastWritten = -1;

    public function __construct(
        private readonly JobRunService $runs,
        private JobRun $run,
    ) {}

    /**
     * Declare how much work there is, once it is known.
     *
     * Optional. A job that cannot count what it is about to do — reading one
     * image, calling one model — reports messages instead, and the screen shows
     * an indeterminate bar rather than a lie about progress.
     */
    public function total(int $total): self
    {
        $this->total = max(0, $total);
        $this->run = $this->runs->report($this->run, $this->processed, $this->total);
        $this->lastWritten = $this->percentage();

        return $this;
    }

    /**
     * One more item done.
     */
    public function step(int $by = 1, ?string $message = null): self
    {
        return $this->to($this->processed + $by, $message);
    }

    /**
     * This many done.
     */
    public function to(int $processed, ?string $message = null): self
    {
        $this->processed = max(0, $processed);

        $percentage = $this->percentage();

        // A message always lands; a step only when the bar would actually move.
        if ($message === null && $percentage === $this->lastWritten) {
            return $this;
        }

        $this->run = $this->runs->report($this->run, $this->processed, $this->total, $message);
        $this->lastWritten = $percentage;

        return $this;
    }

    /**
     * Say what is happening, without claiming a position.
     *
     * The right report for work that has one long step: "reading the invoice"
     * beats a bar that sits at zero and then jumps to done.
     */
    public function message(string $message): self
    {
        $this->run = $this->runs->report($this->run, null, null, $message);

        return $this;
    }

    public function run(): JobRun
    {
        return $this->run;
    }

    private function percentage(): int
    {
        if ($this->total === null || $this->total <= 0) {
            return -1;
        }

        return (int) floor($this->processed / $this->total * 100);
    }
}
