<dialog id="action-confirmation" class="app-dialog app-confirmation" aria-labelledby="action-confirmation-title" aria-describedby="action-confirmation-description">
    <header class="app-dialog-header"><span class="app-confirmation-icon" aria-hidden="true">!</span><button class="app-dialog-close" type="button" data-confirm-cancel aria-label="Cancel action">×</button></header>
    <div class="app-dialog-body"><h2 id="action-confirmation-title">Confirm action</h2><p id="action-confirmation-description">Please confirm before continuing.</p><p class="app-confirmation-context" data-confirm-context hidden></p></div>
    <footer class="app-dialog-footer"><button class="btn btn-ghost" type="button" data-confirm-cancel>Cancel</button><button class="btn btn-primary" type="button" data-confirm-accept>Confirm</button></footer>
</dialog>
@vite('resources/js/confirm-actions.ts')
