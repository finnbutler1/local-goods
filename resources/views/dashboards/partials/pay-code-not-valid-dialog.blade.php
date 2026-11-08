{{-- Shown when a pay code is not accepted. No variables on purpose: the text is the same for every
     reason, so it can never say which one applies or whether the code exists for another shop.
     Without JavaScript the dialog stays closed and the inline message under the field is the fallback. --}}
<dialog id="pay-code-dialog" aria-labelledby="pay-code-dialog-heading" data-open-on-load data-return-to="pay_code"
        class="m-auto w-[min(100%-2rem,32rem)] rounded-2xl border-2 border-frame bg-surface p-0 text-ink backdrop:bg-night/60">
    <div class="space-y-4 p-6">
        <h2 id="pay-code-dialog-heading" class="text-2xl font-extrabold tracking-tight text-ink">That code didn't work</h2>
        <div>
            <p>A pay code can be refused for a few ordinary reasons:</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                <li>A character was typed wrong.</li>
                <li>It expired. Codes last 10 minutes.</li>
                <li>It was already used.</li>
                <li>It was made for a different shop.</li>
                <li>It was cancelled.</li>
            </ul>
        </div>
        <p><span class="font-semibold">What to do:</span> ask the customer to check the code on their screen, or to get a new one in their wallet.</p>
        <div class="flex justify-end">
            <x-button type="button" data-dialog-close>Try again</x-button>
        </div>
    </div>
</dialog>
