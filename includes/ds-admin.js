(function ($) {
    'use strict';

    /**
     * Normalize a header into a safe key.
     *
     * @param {string} value Header value.
     * @return {string}
     */
    function normalizeKey(value) {
        return value
            .toString()
            .trim()
            .toLowerCase()
            .replace(/[ıİ]/g, 'i')
            .replace(/[^a-z0-9_]+/g, '_')
            .replace(/^_+|_+$/g, '') || 'field_' + Math.floor(Math.random() * 9000 + 1000);
    }

    /**
     * Escape a value for safe HTML attribute output.
     *
     * @param {string} value Raw value.
     * @return {string}
     */
    function escAttr(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    /**
     * Synchronize the hidden mapping JSON field.
     */
    function syncHiddenField() {
        var mapping = [];

        $('#gs-sheet-manager-schema-container tbody tr').each(function () {
            var header = $(this).find('.gs-sheet-manager-header').val();
            var key = $(this).find('.gs-sheet-manager-key').val();
            var active = $(this).find('.gs-sheet-manager-active').is(':checked') ? 1 : 0;

            mapping.push({
                header: header,
                key: key,
                active: active,
            });
        });

        $('#gs_sheet_manager_mapping_rules').val(JSON.stringify(mapping));
    }

    /**
     * Build mapping rows in the schema container.
     *
     * @param {Array} headers Sheet headers.
     */
    function buildSchemaRows(headers) {
        var html = '<table class="gs-sheet-manager-table"><thead><tr><th>Aktif</th><th>Sütun Başlığı</th><th>Değişken Adı</th></tr></thead><tbody>';

        headers.forEach(function (header) {
            var key = normalizeKey(header);
            html += '<tr>' +
                '<td style="text-align:center;"><input type="checkbox" class="gs-sheet-manager-active" value="1" checked /></td>' +
                '<td><input type="text" class="widefat gs-sheet-manager-header" value="' + escAttr(header) + '" readonly /></td>' +
                '<td><input type="text" class="widefat gs-sheet-manager-key" value="' + escAttr(key) + '" /></td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        $('#gs-sheet-manager-schema-container').html(html);
        syncHiddenField();
    }

    /**
     * Submit the sync form with current data.
     *
     * @param {number} postId Post ID.
     */
    function submitSyncForm(postId) {
        syncHiddenField();

        var sourceType = $('#gs_source_type').val() || 'google_sheet';
        var sheetUrl = $('#gs_sheet_manager_sheet_url').val();
        var uploadedUrl = $('#gs_uploaded_file_url').val();
        var mappingRules = $('#gs_sheet_manager_mapping_rules').val();

        var $form = $('<form>', {
            method: 'post',
            action: gsSheetManagerData.ajaxUrl.replace(/admin-ajax\.php$/, 'admin-post.php'),
            style: 'display:none;',
        });

        $form.append('<input type="hidden" name="action" value="gs_sheet_manager_sync" />');
        $form.append('<input type="hidden" name="post_id" value="' + postId + '" />');
        $form.append('<input type="hidden" name="gs_source_type" value="' + escAttr(sourceType) + '" />');
        $form.append('<input type="hidden" name="gs_sheet_manager_sheet_url" value="' + escAttr(sheetUrl) + '" />');
        $form.append('<input type="hidden" name="gs_uploaded_file_url" value="' + escAttr(uploadedUrl) + '" />');
        $form.append('<input type="hidden" name="gs_sheet_manager_mapping_rules" value="' + escAttr(mappingRules) + '" />');
        $form.append('<input type="hidden" name="gs_sheet_manager_sync_nonce" value="' + escAttr(gsSheetManagerData.syncNonce) + '" />');

        $('body').append($form);
        $form.submit();
    }

    /**
     * Submit the delete version form with current data.
     *
     * @param {number} postId   Post ID.
     * @param {string} fileName Version file name.
     */
    function submitDeleteVersionForm(postId, fileName) {
        var $form = $('<form>', {
            method: 'post',
            action: gsSheetManagerData.ajaxUrl.replace(/admin-ajax\.php$/, 'admin-post.php'),
            style: 'display:none;',
        });

        $form.append('<input type="hidden" name="action" value="gs_sheet_manager_delete_version" />');
        $form.append('<input type="hidden" name="post_id" value="' + escAttr(postId) + '" />');
        $form.append('<input type="hidden" name="file_name" value="' + escAttr(fileName) + '" />');
        $form.append('<input type="hidden" name="gs_sheet_manager_delete_nonce" value="' + escAttr(gsSheetManagerData.deleteNonce) + '" />');

        $('body').append($form);
        $form.submit();
    }

    /**
     * Submit the activate (rollback) version form.
     *
     * @param {number} postId   Post ID.
     * @param {string} fileName Version file name.
     */
    function submitActivateVersionForm(postId, fileName) {
        var $form = $('<form>', {
            method: 'post',
            action: gsSheetManagerData.ajaxUrl.replace(/admin-ajax\.php$/, 'admin-post.php'),
            style: 'display:none;',
        });

        $form.append('<input type="hidden" name="action" value="gs_sheet_manager_activate_version" />');
        $form.append('<input type="hidden" name="post_id" value="' + escAttr(postId) + '" />');
        $form.append('<input type="hidden" name="file_name" value="' + escAttr(fileName) + '" />');
        $form.append('<input type="hidden" name="gs_sheet_manager_activate_nonce" value="' + escAttr(gsSheetManagerData.activateNonce) + '" />');

        $('body').append($form);
        $form.submit();
    }

    /**
     * Update bulk delete button state and label.
     */
    function updateBulkDeleteState() {
        var count = $('.gs-sheet-manager-version-cb:checked').length;
        var $btn = $('#gs-sheet-manager-bulk-delete-btn');
        if (count > 0) {
            $btn.prop('disabled', false).text('Seçilenleri Sil (' + count + ')');
        } else {
            $btn.prop('disabled', true).text('Seçilenleri Sil');
        }
    }

    /**
     * Submit the bulk delete versions form.
     *
     * @param {number} postId    Post ID.
     * @param {Array}  fileNames Selected version file names.
     */
    function submitBulkDeleteForm(postId, fileNames) {
        var $form = $('<form>', {
            method: 'post',
            action: gsSheetManagerData.ajaxUrl.replace(/admin-ajax\.php$/, 'admin-post.php'),
            style: 'display:none;',
        });

        $form.append('<input type="hidden" name="action" value="gs_sheet_manager_bulk_delete_versions" />');
        $form.append('<input type="hidden" name="post_id" value="' + escAttr(postId) + '" />');
        $form.append('<input type="hidden" name="gs_sheet_manager_bulk_delete_nonce" value="' + escAttr(gsSheetManagerData.bulkDeleteNonce) + '" />');

        fileNames.forEach(function (name) {
            $form.append('<input type="hidden" name="file_names[]" value="' + escAttr(name) + '" />');
        });

        $('body').append($form);
        $form.submit();
    }

    $(document).ready(function () {
        function toggleSourceInputs() {
            var type = $('#gs_source_type').val() || 'google_sheet';
            if (type === 'upload') {
                $('#gs-url-input-wrap').hide();
                $('#gs-upload-input-wrap').show();
            } else {
                $('#gs-url-input-wrap').show();
                $('#gs-upload-input-wrap').hide();
            }
        }
        toggleSourceInputs();
        $(document).on('change', '#gs_source_type', toggleSourceInputs);

        $(document).on('click', '#gs-upload-media-btn', function (e) {
            e.preventDefault();
            if (!window.wp || !wp.media) {
                alert('WordPress Medya Yükleyici yüklenemedi.');
                return;
            }
            var frame = wp.media({
                title: 'Veri Dosyası Seç veya Yükle (JSON / CSV / XML)',
                button: { text: 'Dosyayı Kullan' },
                multiple: false
            });
            frame.on('select', function () {
                var att = frame.state().get('selection').first().toJSON();
                $('#gs_uploaded_file_url').val(att.url);
                $('#gs_uploaded_file_id').val(att.id);
            });
            frame.open();
        });

        $(document).on('click', '#gs-sheet-manager-analyze-button', function () {
            var sourceType = $('#gs_source_type').val() || 'google_sheet';
            var sheetUrl = $('#gs_sheet_manager_sheet_url').val();
            var uploadedUrl = $('#gs_uploaded_file_url').val();

            if (sourceType === 'upload' && !uploadedUrl) {
                alert('Lütfen bir dosya yükleyin veya seçin.');
                return;
            }
            if (sourceType !== 'upload' && !sheetUrl) {
                alert('Lütfen veri kaynağı URL\'sini girin.');
                return;
            }

            $.ajax({
                url: gsSheetManagerData.ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'gs_sheet_manager_fetch_schema',
                    security: gsSheetManagerData.nonce,
                    source_type: sourceType,
                    sheet_url: sheetUrl,
                    uploaded_file_url: uploadedUrl,
                },
            }).done(function (response) {
                if (response.success && response.data) {
                    buildSchemaRows(response.data);
                } else {
                    alert(response.data || 'Şema alınamadı.');
                }
            }).fail(function () {
                alert('Şema isteği başarısız oldu.');
            });
        });

        $(document).on('change', '.gs-sheet-manager-key, .gs-sheet-manager-active', function () {
            syncHiddenField();
        });

        $(document).on('click', '#gs-sheet-manager-sync-button', function () {
            var postId = $(this).data('post-id');
            submitSyncForm(postId);
        });

        $(document).on('click', '.gs-sheet-manager-delete-version', function () {
            if (!confirm('Bu dosyayı silmek istediğinize emin misiniz?')) {
                return;
            }
            var postId = $(this).data('post-id');
            var fileName = $(this).data('file-name');
            submitDeleteVersionForm(postId, fileName);
        });
        $(document).on('click', '.gs-sheet-manager-activate-version', function () {
            var version = $(this).data('version');
            if (!confirm('v' + version + ' versiyonunu tekrar yayına almak istediğinize emin misiniz? REST API ve kısa kod bu veriyi sunmaya başlayacaktır.')) {
                return;
            }
            var postId = $(this).data('post-id');
            var fileName = $(this).data('file-name');
            submitActivateVersionForm(postId, fileName);
        });
        $(document).on('change', '#gs-sheet-manager-select-all', function () {
            var checked = $(this).is(':checked');
            $('.gs-sheet-manager-version-cb:not(:disabled)').prop('checked', checked);
            updateBulkDeleteState();
        });

        $(document).on('change', '.gs-sheet-manager-version-cb', function () {
            var total = $('.gs-sheet-manager-version-cb:not(:disabled)').length;
            var checked = $('.gs-sheet-manager-version-cb:checked').length;
            $('#gs-sheet-manager-select-all').prop('checked', total > 0 && total === checked);
            updateBulkDeleteState();
        });

        $(document).on('click', '#gs-sheet-manager-bulk-delete-btn', function () {
            var fileNames = [];
            $('.gs-sheet-manager-version-cb:checked').each(function () {
                fileNames.push($(this).val());
            });
            if (!fileNames.length) {
                return;
            }
            if (!confirm('Seçili ' + fileNames.length + ' adet versiyon dosyasını silmek istediğinize emin misiniz? Bu işlem geri alınamaz.')) {
                return;
            }
            var postId = $(this).data('post-id');
            submitBulkDeleteForm(postId, fileNames);
        });
    });
})(jQuery);