(function () {
    'use strict';

    /* ── Backup deletion: confirm before either destructive action fires ─── */
    document.querySelectorAll('.eth-dbreset-delete-one-form').forEach(function (delForm) {
        delForm.addEventListener('submit', function (e) {
            const filenameInput = delForm.querySelector('input[name="file"]');
            const filename = filenameInput ? filenameInput.value : 'this backup';
            if (!window.confirm('Permanently delete "' + filename + '"? This cannot be undone.')) {
                e.preventDefault();
            }
        });
    });
    const deleteAllForm = document.querySelector('.eth-dbreset-delete-all-form');
    if (deleteAllForm) {
        deleteAllForm.addEventListener('submit', function (e) {
            if (!window.confirm('Permanently delete ALL database backups, including the original snapshot? This cannot be undone.')) {
                e.preventDefault();
            }
        });
    }

    const form            = document.getElementById('eth-dbreset-form');
    if (!form) return;

    const srcRadios        = document.querySelectorAll('.eth-dbreset-src-radio');
    const customOptions     = document.getElementById('eth-dbreset-custom-options');
    const ackCheckbox        = document.getElementById('eth-dbreset-ack');
    const emptyUploadsCheckbox = document.getElementById('eth-dbreset-empty-uploads');
    const uploadsNote            = document.getElementById('eth-dbreset-ack-uploads-note');
    const submitBtn                = document.getElementById('eth-dbreset-submit');
    const generateBtn               = document.getElementById('eth-dbreset-generate');
    const actionField                 = document.getElementById('eth-dbreset-action-field');

    const ACTION_IMPORT   = actionField ? actionField.value : 'eth_db_reset_import';
    const ACTION_GENERATE  = 'eth_db_reset_generate';

    /* ── Source toggle: show upload + rewrite options only for "custom" ─── */
    function toggleCustomOptions() {
        const checked = document.querySelector('.eth-dbreset-src-radio:checked');
        customOptions.style.display = (checked && checked.value === 'custom') ? 'block' : 'none';
    }
    srcRadios.forEach(r => r.addEventListener('change', toggleCustomOptions));
    toggleCustomOptions();

    /* ── Styled file picker: reflect the chosen filename ─────────────────── */
    const fileInputReal = document.getElementById('eth-dbreset-custom-file');
    const fileNameLabel  = document.getElementById('eth-dbreset-file-picker-name');
    if (fileInputReal && fileNameLabel) {
        fileInputReal.addEventListener('change', function () {
            if (fileInputReal.files.length) {
                fileNameLabel.textContent = fileInputReal.files[0].name;
                fileNameLabel.classList.add('has-file');
            } else {
                fileNameLabel.textContent = 'No file selected';
                fileNameLabel.classList.remove('has-file');
            }
        });
    }

    /* ── Keep the acknowledgement wording accurate to what will actually happen ── */
    function refreshUploadsNote() {
        if (uploadsNote && emptyUploadsCheckbox) {
            uploadsNote.style.display = emptyUploadsCheckbox.checked ? 'inline' : 'none';
        }
    }
    if (emptyUploadsCheckbox) emptyUploadsCheckbox.addEventListener('change', refreshUploadsNote);
    refreshUploadsNote();

    /* ── Destructive-action gating: acknowledgement checkbox only ───────── */
    function refreshSubmitState() {
        submitBtn.disabled = !ackCheckbox.checked;
    }
    ackCheckbox.addEventListener('change', refreshSubmitState);
    refreshSubmitState();

    /* Route each button to the right admin-post action before submitting. */
    submitBtn.addEventListener('click', function () {
        actionField.value = ACTION_IMPORT;
    });
    if (generateBtn) {
        generateBtn.addEventListener('click', function () {
            actionField.value = ACTION_GENERATE;
        });
    }

    form.addEventListener('submit', function (e) {
        // Non-destructive "Generate & download" path — no ack needed.
        if (actionField.value === ACTION_GENERATE) {
            const fileInput = document.getElementById('eth-dbreset-custom-file');
            if (!fileInput || !fileInput.files.length) {
                e.preventDefault();
                window.alert('Choose a .sql file to upload first.');
            }
            return;
        }

        // Destructive "Reset & Import" path — last speed bump before it fires.
        if (!ackCheckbox.checked) {
            e.preventDefault();
            return;
        }
        const willEmptyUploads = emptyUploadsCheckbox && emptyUploadsCheckbox.checked;
        const proceed = window.confirm(
            'This will permanently delete all data in this database' +
            (willEmptyUploads ? ' and every file in wp-content/uploads' : '') +
            ', then replace it with the SQL dump. This cannot be undone. Continue?'
        );
        if (!proceed) e.preventDefault();
    });
})();