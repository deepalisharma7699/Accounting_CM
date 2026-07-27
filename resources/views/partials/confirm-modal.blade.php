{{-- Themed replacement for window.confirm(). Driven by confirmAction() in ui.js. --}}
<div id="confirm-modal" class="modal-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="confirm-title">
    <div class="modal-panel max-w-md p-6">
        <div class="flex items-start gap-3">
            <span class="grid size-10 shrink-0 place-items-center rounded-full bg-rose-50 text-rose-600">
                <x-icon name="alert-triangle" :size="20" />
            </span>

            <div class="min-w-0">
                <h2 id="confirm-title" class="text-base font-bold text-foreground" data-confirm-title></h2>
                <p class="mt-1.5 text-[0.875rem] leading-relaxed text-muted-foreground" data-confirm-body></p>
            </div>
        </div>

        <div class="mt-6 flex justify-end gap-2">
            <button type="button" class="btn btn-secondary" data-confirm-cancel data-modal-close>Cancel</button>
            <button type="button" class="btn btn-danger" data-confirm-accept>Delete</button>
        </div>
    </div>
</div>
