(function () {
    'use strict';

    function parseJsonAttribute(element, attributeName, fallback) {
        if (!element) {
            return fallback;
        }

        var value = element.getAttribute(attributeName);
        if (!value) {
            return fallback;
        }

        try {
            return JSON.parse(value);
        } catch (error) {
            return fallback;
        }
    }

    function replaceIndex(markup, index) {
        return String(markup || '').split('__INDEX__').join(String(index));
    }

    function initConfirmations() {
        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!form || !form.getAttribute) {
                return;
            }

            var message = form.getAttribute('data-confirm');
            if (message && !window.confirm(message)) {
                event.preventDefault();
            }
        });
    }

    function initSelectAll() {
        var selectAll = document.getElementById('ces-select-all-emails');
        var form = document.getElementById('ces-bulk-email-form');
        if (!selectAll || !form) {
            return;
        }

        selectAll.addEventListener('change', function () {
            form.querySelectorAll('input[name="email_ids[]"]').forEach(function (checkbox) {
                checkbox.checked = selectAll.checked;
            });
        });


        form.addEventListener('submit', function (event) {
            var action = form.querySelector('select[name="bulk_action"]');
            if (!action || action.value !== 'delete') {
                return;
            }

            var message = form.getAttribute('data-delete-confirm');
            if (message && !window.confirm(message)) {
                event.preventDefault();
            }
        });
    }


    function refreshRuntimeRequired() {
        var enabled = document.getElementById('ces_enabled');
        if (!enabled) {
            return;
        }

        document.querySelectorAll('[data-ces-runtime-required="1"]').forEach(function (field) {
            field.required = enabled.checked;
            field.setAttribute('aria-required', enabled.checked ? 'true' : 'false');
        });
    }

    function initBuilder(config) {
        var dataElement = document.getElementById(config.dataId);
        var table = document.getElementById(config.tableId);
        var addButton = document.getElementById(config.addButtonId);
        var addSelect = document.getElementById(config.addSelectId);
        var emptyMessage = document.getElementById(config.emptyMessageId);

        if (!dataElement || !table) {
            return;
        }

        var nextIndex = parseInt(dataElement.getAttribute('data-next-index') || '0', 10);
        var settingsTemplates = parseJsonAttribute(dataElement, 'data-settings-templates', {});
        var descriptions = parseJsonAttribute(dataElement, 'data-descriptions', {});
        var selectTemplate = parseJsonAttribute(dataElement, 'data-select-template', '');
        var removeLabel = dataElement.getAttribute('data-remove-label') || 'Remove';
        var noSettingsLabel = dataElement.getAttribute('data-no-settings-label') || 'No settings required.';

        if (isNaN(nextIndex) || nextIndex < 0) {
            nextIndex = 0;
        }

        function refreshEmptyMessage() {
            if (!emptyMessage || !table) {
                return;
            }
            var rows = table.querySelectorAll('tbody tr.' + config.rowClass);
            emptyMessage.style.display = rows.length ? 'none' : '';
        }

        function updateRowSettings(row) {
            var select = row.querySelector('.' + config.keyClass);
            var settingsCell = row.querySelector('.' + config.settingsClass);
            var description = row.querySelector('.' + config.descriptionClass);
            var index = row.getAttribute('data-index');
            var key = select ? select.value : '';

            if (settingsCell) {
                settingsCell.innerHTML = replaceIndex(settingsTemplates[key] || '<span class="description">' + noSettingsLabel + '</span>', index);
            }

            if (description) {
                description.textContent = descriptions[key] || '';
            }

            refreshRuntimeRequired();
        }

        function buildRow(itemKey) {
            var index = nextIndex++;
            var row = document.createElement('tr');
            row.className = config.rowClass;
            row.setAttribute('data-index', String(index));

            var operatorMarkup = config.includeOperator
                ? '<td class="ces-builder-result" data-label="Required result"><select name="condition_rows[' + index + '][operator]"><option value="is_true">Must be true</option><option value="is_false">Must be false</option></select></td>'
                : '';

            row.innerHTML = '<td class="ces-builder-primary" data-label="' + (config.includeOperator ? 'Condition' : 'Filter') + '">' + replaceIndex(selectTemplate, index) + '<br><span class="description ' + config.descriptionClass + '"></span></td>'
                + operatorMarkup
                + '<td class="' + config.settingsClass + '" data-label="Settings"></td>'
                + '<td class="ces-builder-action"><button type="button" class="button-link-delete ' + config.removeClass + '">' + removeLabel + '</button></td>';

            var select = row.querySelector('.' + config.keyClass);
            if (select && itemKey) {
                select.value = itemKey;
            }

            updateRowSettings(row);
            return row;
        }

        if (addButton && addSelect) {
            addButton.addEventListener('click', function () {
                var tbody = table.querySelector('tbody');
                if (!tbody) {
                    return;
                }

                tbody.appendChild(buildRow(addSelect.value));
                refreshEmptyMessage();
            });
        }

        table.addEventListener('click', function (event) {
            if (event.target && event.target.classList.contains(config.removeClass)) {
                var row = event.target.closest('tr');
                if (row) {
                    row.remove();
                    refreshEmptyMessage();
                }
            }
        });

        table.addEventListener('change', function (event) {
            if (event.target && event.target.classList.contains(config.keyClass)) {
                var row = event.target.closest('tr');
                if (row) {
                    updateRowSettings(row);
                }
            }
        });
    }


    function initEventDetails() {
        var select = document.getElementById('event_key');
        var description = document.getElementById('ces-event-description');
        var hooks = document.getElementById('ces-event-hooks');
        var settings = document.getElementById('ces-event-settings');
        var settingsTemplates = parseJsonAttribute(settings, 'data-settings-templates', {});

        if (!select || !description || !hooks || !settings) {
            return;
        }

        function refresh(updateSettings) {
            var option = select.options[select.selectedIndex];
            var descriptionText = option ? option.getAttribute('data-description') || '' : '';
            var hooksText = option ? option.getAttribute('data-hooks') || '' : '';
            var hooksPrefix = hooks.getAttribute('data-prefix') || 'Bound WordPress hooks:';

            description.textContent = descriptionText;
            description.hidden = descriptionText === '';
            hooks.textContent = hooksText === '' ? '' : hooksPrefix + ' ' + hooksText;
            hooks.hidden = hooksText === '';
            if (updateSettings) {
                settings.innerHTML = settingsTemplates[select.value] || '';
                refreshRuntimeRequired();
            }
        }

        select.addEventListener('change', function () {
            refresh(true);
        });
        refresh(false);
    }

    function initBuilders() {
        initBuilder({
            dataId: 'ces-condition-builder-data',
            tableId: 'ces-condition-rows',
            addButtonId: 'ces-add-condition-row',
            addSelectId: 'ces-condition-to-add',
            emptyMessageId: 'ces-no-conditions',
            rowClass: 'ces-condition-row',
            keyClass: 'ces-condition-key',
            settingsClass: 'ces-condition-settings',
            descriptionClass: 'ces-condition-description',
            removeClass: 'ces-remove-condition-row',
            includeOperator: true
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initConfirmations();
        initSelectAll();
        initBuilders();
        initEventDetails();
        refreshRuntimeRequired();

        var enabled = document.getElementById('ces_enabled');
        if (enabled) {
            enabled.addEventListener('change', refreshRuntimeRequired);
        }
    });
}());
