import $ from 'jquery';

window.$ = window.jQuery = $;

import 'bootstrap/dist/js/bootstrap.bundle.min.js';

$(function () {
    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'),
        },
    });

    initUploadForm();
    initDeleteModal();
});

function initUploadForm() {
    const $form = $('#upload-form');

    if (!$form.length) {
        return;
    }

    const uploadUrl = $form.data('upload-url');
    const maxSizeBytes = Number($form.data('max-size-bytes'));
    const allowedExtensions = String($form.data('allowed-extensions')).split(',');

    const $fileInput = $('#file-input');
    const $feedback = $('#file-feedback');
    const $progressWrap = $('#upload-progress');
    const $progressBar = $progressWrap.find('.progress-bar');
    const $submit = $('#upload-submit');

    $fileInput.on('change', function () {
        validateFile(this.files[0]);
    });

    $form.on('submit', function (event) {
        event.preventDefault();

        const file = $fileInput[0].files[0];

        if (!validateFile(file)) {
            return;
        }

        const formData = new FormData();
        formData.append('file', file);

        $submit.prop('disabled', true);
        $progressWrap.removeClass('d-none');
        $progressBar.css('width', '0%');

        $.ajax({
            url: uploadUrl,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            xhr: function () {
                const xhr = $.ajaxSettings.xhr();

                if (xhr.upload) {
                    xhr.upload.addEventListener('progress', function (event) {
                        if (event.lengthComputable) {
                            const percent = Math.round((event.loaded / event.total) * 100);
                            $progressBar.css('width', percent + '%');
                        }
                    });
                }

                return xhr;
            },
        })
            .done(function () {
                showAlert('success', 'File uploaded successfully.');
                $form.trigger('reset');
                clearFieldError();
            })
            .fail(function (xhr) {
                if (xhr.status === 422 && xhr.responseJSON?.errors?.file) {
                    showFieldError(xhr.responseJSON.errors.file.join(' '));
                } else {
                    showAlert('danger', 'Something went wrong. Please try again.');
                }
            })
            .always(function () {
                $submit.prop('disabled', false);
                $progressWrap.addClass('d-none');
            });
    });

    function validateFile(file) {
        clearFieldError();

        if (!file) {
            showFieldError('Please choose a file to upload.');
            return false;
        }

        const extension = file.name.split('.').pop().toLowerCase();

        if (!allowedExtensions.includes(extension)) {
            showFieldError('Only PDF and DOCX files are allowed.');
            return false;
        }

        if (file.size > maxSizeBytes) {
            showFieldError(`File size must not exceed ${Math.floor(maxSizeBytes / 1024 / 1024)} MB.`);
            return false;
        }

        return true;
    }

    function showFieldError(message) {
        $fileInput.addClass('is-invalid');
        $feedback.text(message);
    }

    function clearFieldError() {
        $fileInput.removeClass('is-invalid');
        $feedback.text('');
    }
}

function initDeleteModal() {
    const $modal = $('#delete-modal');

    if (!$modal.length) {
        return;
    }

    const $filename = $('#delete-modal-filename');
    const $confirm = $('#delete-modal-confirm');

    let deleteUrl = null;
    let $row = null;

    $(document).on('click', '[data-bs-target="#delete-modal"]', function () {
        deleteUrl = $(this).data('delete-url');
        $row = $(this).closest('tr');
        $filename.text($(this).data('file-name'));
    });

    $confirm.on('click', function () {
        if (!deleteUrl) {
            return;
        }

        $confirm.prop('disabled', true);

        $.ajax({
            url: deleteUrl,
            method: 'DELETE',
        })
            .done(function () {
                $row?.remove();
                bootstrap.Modal.getInstance($modal[0])?.hide();
                showAlert('success', 'File deleted.');
            })
            .fail(function () {
                bootstrap.Modal.getInstance($modal[0])?.hide();
                showAlert('danger', 'Could not delete the file. Please try again.');
            })
            .always(function () {
                $confirm.prop('disabled', false);
            });
    });
}

function showAlert(variant, message) {
    const $region = $('#ajax-alert-region');

    if (!$region.length) {
        return;
    }

    const $alert = $(
        `<div class="alert alert-${variant} alert-dismissible fade show" role="alert">
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>`
    );

    $region.empty().append($alert);
}
