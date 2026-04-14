
$(document).ready(function () {

    /* ===========================
       1. FIELD REPEATER INIT
    ============================ */
    function initRepeaters() {
        return new Promise((resolve) => {

            $('[data-cardinality]').each(function () {
                const $input = $(this);
                const limit = parseInt($input.data('cardinality'), 10);

                if (!limit || limit <= 1) return;

                const $fieldGroup = $input.closest('.field-group');
                if (!$fieldGroup.length) return;

                let $template = $input.parent();

                if ($template.find('input, select, textarea').length > 1) {
                    $template = $input;
                }

                const $container = $('<div class="field-repeat-items"></div>');
                $template.before($container);
                $container.append($template);

                function normalizeNames($el) {
                    $el.find('input, select, textarea').addBack().each(function () {
                        if (this.name) {
                            this.name = this.name.replace(/\[\]$/, '') + '[]';
                        }
                    });
                }

                normalizeNames($template);

                const $addBtn = $('<button type="button" class="field-add-more">Add more</button>');
                $container.after($addBtn);

                let count = 1;

                $addBtn.on('click', function () {
                    if (count >= limit) return;

                    const $clone = $template.clone();

                    $clone.find('input, select, textarea').addBack().each(function () {
                        const $field = $(this);

                        if ($field.is(':checkbox, :radio')) {
                            $field.prop('checked', false);
                        } else {
                            $field.val('');
                        }

                        if (this.id) {
                            const oldId = this.id;
                            const newId = oldId + '_' + count;
                            $field.attr('id', newId);
                            $clone.find('label[for="' + oldId + '"]').attr('for', newId);
                        }
                    });

                    normalizeNames($clone);

                    const $removeBtn = $('<button type="button" class="field-remove">Remove</button>');
                    $removeBtn.on('click', function () {
                        $clone.remove();
                        count--;
                        $addBtn.prop('disabled', false);
                    });

                    $clone.append($removeBtn);
                    $container.append($clone);

                    count++;

                    if (count >= limit) {
                        $addBtn.prop('disabled', true);
                    }

                    loadReferenceFields();
                });
            });

            // Give DOM a tick to finish rendering
            setTimeout(resolve, 10);
        });
    }

    /* ===========================
       2. REPOPULATE AFTER REPEATER READY
    ============================ */
    initRepeaters().then(() => {

        const form = document.querySelector('#node-form');
        const lineType = form.getAttribute('typeof');

        $.getJSON('/admin/content/'+lineType+'/submit/data/failed', function (response) {

            if (!response || !response.data) return;

            const data = response.data;

            $.each(data, function (name, values) {

                if (!Array.isArray(values)) {
                    values = [values];
                }

                // Select all matching fields
                let $fields = $('[name="' + name + '"], [name="' + name + '[]"]');

                const $first = $fields.first();

                // Handle repeater
                if ($first.data('cardinality') > 1) {
                    const $group = $first.closest('.field-repeat-items');
                    const $addBtn = $group.next('.field-add-more');

                    for (let i = 1; i < values.length; i++) {
                        $addBtn.trigger('click');
                    }

                    $fields = $group.find('[name^="' + name + '"]');

                }

                // Assign values to each field
                if ($fields.length > 1) {
                    $fields.each(function (i) {

                        const $field = $(this);
                        const tag = this.tagName.toLowerCase();
                        const type = ($field.attr('type') || '').toLowerCase();
                        const val = values[i] !== undefined ? values[i] : '';

                        if (type === 'checkbox') {
                            $field.prop('checked', values.includes($field.val()));
                        } else if (type === 'radio') {
                            $field.prop('checked', values.includes($field.val()));
                        } else if (tag === 'select' && $field.prop('multiple')) {
                            $field.val(values);
                        }
                        else if (tag === 'textarea') {
                            $field.text(val);
                            const editor = window.editors[$field.get(0).id];
                            editor.data.set(val)
                        }
                        else {
                            $field.val(val);
                        }
                    });
                }
                else {
                    const $field = $fields
                    if (!$field.length) return;
                    const tag = $field.get(0).tagName.toLowerCase();
                    const type = ($field.attr('type') || '').toLowerCase();
                    const val = values[0] !== undefined ? values[0] : '';

                    if (type === 'checkbox') {
                        $field.prop('checked', values.includes($field.val()));
                    } else if (type === 'radio') {
                        $field.prop('checked', values.includes($field.val()));
                    } else if (tag === 'select' && $field.prop('multiple')) {
                        $field.val(values);
                    }
                    else if (tag === 'textarea') {
                        $field.text(val)
                        const editor = window.editors[$field.get(0).id];
                        editor.data.set(val)
                    }
                    else {
                        $field.val(val);
                    }
                }
            });

        });

    });

});
