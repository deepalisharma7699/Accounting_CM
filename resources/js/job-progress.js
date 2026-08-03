import auth from './auth-client';

/**
 * Watching a queued job from the browser — M14.
 *
 * Shared rather than written into the uploads page, because M15 needs exactly
 * this: a capture is dispatched, a handle comes back, and something has to
 * follow it without freezing the screen.
 *
 * ## Why polling and not a socket
 *
 * A websocket would mean a broadcast driver, a connection to hold open and a
 * second thing to run and supervise in production, all to shorten a wait that is
 * usually under five seconds. The work here is short and the audience is one
 * person who just pressed a button. Polling costs one indexed lookup by uuid
 * per interval and needs no infrastructure at all — and if M15's model calls
 * turn out to take a minute rather than five seconds, the backoff below is
 * already the shape that keeps that cheap.
 */

/** Start fast, because most jobs finish almost immediately. */
const FIRST_INTERVAL = 700;

/** Then ease off, so a slow job does not cost a request a second for a minute. */
const MAX_INTERVAL = 5000;

const GROWTH = 1.4;

/**
 * Stop eventually. A worker that has been killed leaves a run row sitting at
 * `running` for ever, and a page that polled it for ever would keep a dead job
 * alive on somebody's screen. Two minutes, then say so.
 */
const GIVE_UP_AFTER_MS = 120_000;

/**
 * Follow a job until it settles.
 *
 * @param {string} id                 The job's uuid, from the response that queued it.
 * @param {object} handlers
 * @param {Function} [handlers.onUpdate]  Called with each poll's job payload.
 * @param {Function} [handlers.onDone]    Called once, with the settled job.
 * @param {Function} [handlers.onError]   Called once if it stops being watchable.
 * @returns {Function} Cancels the watch — call it when the row leaves the page.
 */
export function watchJob(id, { onUpdate, onDone, onError } = {}) {
    let cancelled = false;
    let timer = null;
    let interval = FIRST_INTERVAL;

    const startedAt = Date.now();

    const stop = () => {
        cancelled = true;
        if (timer) clearTimeout(timer);
    };

    const poll = async () => {
        if (cancelled) return;

        try {
            const { data } = await auth.call(`/jobs/${id}`);

            if (cancelled) return;

            onUpdate?.(data);

            // `is_settled` comes from the server rather than being decided here
            // from a list of statuses. A client holding its own copy of that list
            // would poll for ever the first time a state was added.
            if (data.is_settled) {
                onDone?.(data);

                return;
            }

            if (Date.now() - startedAt > GIVE_UP_AFTER_MS) {
                onError?.(new Error('This is taking longer than expected. Reload the page to check again.'));

                return;
            }

            interval = Math.min(Math.round(interval * GROWTH), MAX_INTERVAL);
            timer = setTimeout(poll, interval);
        } catch (error) {
            if (cancelled) return;

            // A 404 means the run has been pruned, which only happens well after
            // it finished — so it is "long since done" rather than an error.
            onError?.(error);
        }
    };

    timer = setTimeout(poll, FIRST_INTERVAL);

    return stop;
}

/**
 * The bar itself. Indeterminate when the job cannot count its own work, because
 * a bar sitting at zero reads as broken where "Reading the invoice…" reads as
 * busy.
 */
export function progressMarkup(job) {
    if (!job) return '';

    if (job.status === 'failed') {
        return `<span class="text-[0.8125rem] text-rose-600">${escapeText(job.error ?? 'Failed.')}</span>`;
    }

    if (job.is_settled) return '';

    const known = job.total > 0;
    const percent = known ? Math.min(99, job.progress ?? 0) : 100;

    return `
        <span class="block text-[0.8125rem] text-muted-foreground">${escapeText(job.message ?? 'Working…')}</span>
        <span class="mt-1 block h-1 w-full overflow-hidden rounded-full bg-secondary">
            <span class="block h-full rounded-full bg-primary transition-[width] duration-300 ${known ? '' : 'animate-pulse'}"
                  style="width: ${percent}%"></span>
        </span>`;
}

function escapeText(value) {
    return String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[character]);
}
