/* global jQuery, wp, sightlineAdmin */
(function ($) {
    'use strict';

    /**
     * Character counters.
     *
     * A count, not a limit. Search engines truncate rather than reject, so
     * a hard maximum would stop someone writing a description that is
     * deliberately long -- and 7 of the 34 product descriptions on the site
     * this was built for are over 160 characters on purpose.
     */
    function counters() {
        $('.sightline-count').each(function () {
            var $field = $(this),
                limit = parseInt($field.data('limit'), 10) || 0,
                $out = $('<span class="sightline-counter"></span>');

            if (!limit) {
                return;
            }

            $field.before($out);

            function update() {
                var length = ($field.val() || '').length;
                $out.text(length + ' / ' + limit);
                $out.toggleClass('is-over', length > limit);
            }

            $field.on('input change', update);
            update();
        });
    }

    /** The media picker used by every image field. */
    function imagePickers() {
        var frame = null;

        $(document).on('click', '.sightline-image-choose', function (e) {
            e.preventDefault();

            var $wrap = $(this).closest('.sightline-image-picker');

            frame = wp.media({
                title: sightlineAdmin.chooseImage,
                button: { text: sightlineAdmin.useImage },
                multiple: false
            });

            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON(),
                    src = attachment.sizes && attachment.sizes.medium
                        ? attachment.sizes.medium.url
                        : attachment.url;

                $wrap.find('.sightline-image-value').val(attachment.id);
                $wrap.find('.sightline-image-preview').html($('<img>').attr('src', src));
                $wrap.find('.sightline-image-clear').prop('hidden', false);
            });

            frame.open();
        });

        $(document).on('click', '.sightline-image-clear', function (e) {
            e.preventDefault();
            var $wrap = $(this).closest('.sightline-image-picker');
            $wrap.find('.sightline-image-value').val('');
            $wrap.find('.sightline-image-preview').empty();
            $(this).prop('hidden', true);
        });
    }

    /** Redirect rules: several patterns per rule, and 410/451 take no target. */
    function redirectForm() {
        $('#sightline-add-source').on('click', function () {
            var $list = $('#sightline-sources'),
                $row = $list.find('.sightline-source').first().clone();

            $row.find('input').val('');
            $row.find('select, input').each(function () {
                var name = $(this).attr('name');
                if (name) {
                    $(this).attr('name', name.replace(/\[\d+\]/, '[' + $list.find('.sightline-source').length + ']'));
                }
            });

            $list.append($row);
        });

        $(document).on('click', '.sightline-remove-source', function () {
            var $list = $('#sightline-sources');
            if ($list.find('.sightline-source').length > 1) {
                $(this).closest('.sightline-source').remove();
            }
        });

        function toggleTarget() {
            var code = parseInt($('#sl_code').val(), 10);
            $('.sightline-target-row').toggle(code === 301 || code === 302 || code === 307);
        }

        if ($('#sl_code').length) {
            $('#sl_code').on('change', toggleTarget);
            toggleTarget();
        }

        $(document).on('click', '.sightline-delete', function (e) {
            if (!window.confirm('Delete this redirect rule?')) {
                e.preventDefault();
            }
        });
    }

    /**
     * The batched import.
     *
     * One request per batch, driven from here rather than from a loop on
     * the server: a shared host will kill a long request, and a progress
     * bar that only moves when the whole thing finishes is not a progress
     * bar.
     */
    function importer() {
        var $form = $('#sightline-import-form');

        if (!$form.length) {
            return;
        }

        $form.on('submit', function (e) {
            e.preventDefault();

            var overwrite = $form.find('[name="overwrite"]').is(':checked') ? 1 : 0,
                $progress = $('#sightline-import-progress'),
                $label = $progress.find('.sightline-progress-label'),
                $bar = $('#sightline-import-bar');

            $('#sightline-import-run').prop('disabled', true);
            $progress.prop('hidden', false);

            function step(name, offset) {
                $.post(sightlineAdmin.ajaxUrl, {
                    action: 'sightline_import_batch',
                    nonce: sightlineAdmin.importNonce,
                    step: name,
                    offset: offset,
                    overwrite: overwrite
                }).done(function (response) {
                    if (!response || !response.success) {
                        $label.text('The import stopped with an error. Nothing was deleted — reload and look at the report.');
                        $('#sightline-import-run').prop('disabled', false);
                        return;
                    }

                    var data = response.data,
                        percent = data.total > 0 ? Math.round((data.offset / data.total) * 100) : 100;

                    $label.text(data.label + ' — ' + data.offset + ' of ' + data.total);
                    $bar.val(percent);

                    if (!data.done) {
                        step(name, data.offset);
                    } else if (data.next) {
                        step(data.next, 0);
                    } else {
                        $label.text('Finished. Reloading the report…');
                        window.location.reload();
                    }
                }).fail(function () {
                    $label.text('The request failed. Nothing was deleted — reload and look at the report.');
                    $('#sightline-import-run').prop('disabled', false);
                });
            }

            step('settings', 0);
        });
    }

    $(function () {
        counters();
        imagePickers();
        redirectForm();
        importer();
    });
}(jQuery));
