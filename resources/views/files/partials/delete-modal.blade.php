<div class="modal fade" id="delete-modal" tabindex="-1" aria-labelledby="delete-modal-label" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="delete-modal-label">Delete file</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                Are you sure you want to delete <strong id="delete-modal-filename"></strong>? This cannot be undone.
            </div>
            <div class="modal-footer">
                <x-button variant="secondary" data-bs-dismiss="modal">Cancel</x-button>
                <x-button variant="danger" id="delete-modal-confirm">Delete</x-button>
            </div>
        </div>
    </div>
</div>
