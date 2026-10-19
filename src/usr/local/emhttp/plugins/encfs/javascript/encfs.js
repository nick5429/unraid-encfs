/*
 * EncFS plugin settings page.
 * Talks to /plugins/encfs/include/exec.php; renders everything with textContent
 * (no HTML from the server is ever inserted).
 */
(function () {
    'use strict';

    var ENDPOINT = '/plugins/encfs/include/exec.php';
    var POLL_MS = 10000;

    var root = document.getElementById('encfs');
    var state = {
        arrayState: 'Unknown',
        volumes: [],
        busy: false,
        pending: {}          // volume name -> label while an unmount is running
    };

    /* ------------------------------------------------------------ */
    /* Server calls                                                 */
    /* ------------------------------------------------------------ */

    function csrfToken() {
        if (typeof window.csrf_token === 'string' && window.csrf_token !== '') {
            return window.csrf_token;
        }
        return root.getAttribute('data-csrf') || '';
    }

    function parseResponse(response) {
        return response.text().then(function (text) {
            var data = null;
            try {
                data = JSON.parse(text);
            } catch (e) {
                data = null;
            }
            if (data === null) {
                throw new Error('Unexpected response from server (HTTP ' + response.status + ')');
            }
            if (!data.ok) {
                throw new Error(data.error || 'Request failed');
            }
            return data;
        });
    }

    // Mount/initialize can spend up to 90 s in encfs (server-side limit) and a forced
    // unmount escalates for several seconds; everything else should answer at once.
    var SLOW_ACTIONS = ['mount', 'initialize', 'unmount'];
    var FAST_TIMEOUT_MS = 30000;
    var SLOW_TIMEOUT_MS = 150000;

    /** fetch() that rejects with a readable error instead of waiting forever. */
    function fetchWithTimeout(url, options, timeoutMs) {
        var controller = new AbortController();
        var timer = setTimeout(function () { controller.abort(); }, timeoutMs);
        options.signal = controller.signal;
        return fetch(url, options)
            .catch(function (err) {
                if (err && err.name === 'AbortError') {
                    throw new Error('No response from the server after ' + Math.round(timeoutMs / 1000) +
                                    ' seconds. Check the Recent EncFS log, then reload the page.');
                }
                throw err;
            })
            .finally(function () { clearTimeout(timer); });
    }

    function apiGet(action, params) {
        var query = new URLSearchParams(params || {});
        query.set('action', action);
        return fetchWithTimeout(ENDPOINT + '?' + query.toString(), {
            credentials: 'same-origin',
            cache: 'no-store'
        }, FAST_TIMEOUT_MS).then(parseResponse);
    }

    /*
     * POST bodies are sent URL-encoded, like the rest of the Unraid webGUI.
     * multipart/form-data (a FormData body) stalls before reaching PHP on
     * Unraid 7.3.2, so it must not be used here.
     */
    function apiPost(action, fields) {
        var body = new URLSearchParams();
        body.append('action', action);
        body.append('csrf_token', csrfToken());
        Object.keys(fields || {}).forEach(function (key) {
            body.append(key, fields[key]);
        });
        var timeoutMs = SLOW_ACTIONS.indexOf(action) !== -1 ? SLOW_TIMEOUT_MS : FAST_TIMEOUT_MS;
        return fetchWithTimeout(ENDPOINT, {
            method: 'POST',
            credentials: 'same-origin',
            body: body
        }, timeoutMs).then(parseResponse);
    }

    /* ------------------------------------------------------------ */
    /* Small DOM helpers                                            */
    /* ------------------------------------------------------------ */

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined && text !== null) {
            node.textContent = String(text);
        }
        return node;
    }

    function button(label, onClick, disabledReason) {
        var input = el('input');
        input.type = 'button';
        input.value = label;
        if (disabledReason) {
            input.disabled = true;
            input.title = disabledReason;
        } else {
            input.addEventListener('click', onClick);
        }
        return input;
    }

    /** data-label is shown in front of each cell on narrow screens. */
    function labelled(cell, label) {
        cell.setAttribute('data-label', label);
        return cell;
    }

    function setField(dialog, field, value) {
        dialog.querySelectorAll('[data-field="' + field + '"]').forEach(function (node) {
            node.textContent = value;
        });
    }

    function role(dialog, name) {
        return dialog.querySelector('[data-role="' + name + '"]');
    }

    function showError(dialog, message) {
        var box = role(dialog, 'error');
        if (!box) {
            return;
        }
        box.textContent = message || '';
        box.hidden = !message;
    }

    function setBusy(dialog, busy, busyLabel) {
        var submit = role(dialog, 'submit');
        if (submit) {
            if (busy) {
                submit.setAttribute('data-label', submit.value);
                submit.value = busyLabel || 'Working...';
            } else if (submit.getAttribute('data-label')) {
                submit.value = submit.getAttribute('data-label');
            }
            submit.disabled = busy;
        }
        dialog.classList.toggle('encfs-busy', busy);
        state.busy = busy;
    }

    function openDialog(dialog) {
        showError(dialog, '');
        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', '');
        }
    }

    function closeDialog(dialog) {
        if (typeof dialog.close === 'function') {
            dialog.close();
        } else {
            dialog.removeAttribute('open');
        }
        state.busy = false;
        dialog.classList.remove('encfs-busy');
    }

    function anyDialogOpen() {
        return document.querySelector('dialog.encfs-dialog[open]') !== null;
    }

    function insecureConnection() {
        var local = ['localhost', '127.0.0.1', '[::1]'];
        return window.location.protocol !== 'https:' && local.indexOf(window.location.hostname) === -1;
    }

    function formatDate(epochSeconds) {
        var date = new Date(epochSeconds * 1000);
        return date.toLocaleString();
    }

    /* ------------------------------------------------------------ */
    /* Status presentation                                          */
    /* ------------------------------------------------------------ */

    function describeStatus(row) {
        var s = row.status;
        switch (s.state) {
            case 'mounted':
                return { label: s.readonly ? 'Mounted (read-only)' : 'Mounted', tone: 'ok' };
            case 'stale':
                return { label: 'Stale mount', tone: 'warn',
                         detail: 'The encfs process is gone. Force unmount to clean up.' };
            case 'foreign':
                return { label: 'Other filesystem', tone: 'bad',
                         detail: 'A ' + s.fstype + ' filesystem is mounted at the mount point.' };
            case 'missing':
                if (state.arrayState !== 'Started') {
                    return { label: 'Unavailable', tone: 'idle', detail: 'Array is not started.' };
                }
                return { label: 'Directory missing', tone: 'warn',
                         detail: 'Check the path, or Initialize to create a new volume there.' };
            case 'uninitialized':
                return { label: 'Not initialized', tone: 'info',
                         detail: 'Empty directory. Initialize to create a new volume.' };
            case 'noconfig':
                return { label: 'Config missing', tone: 'bad',
                         detail: 'Files exist but no .encfs6.xml was found.' + (s.backup ? ' A backup exists on the flash drive.' : '') };
            default:
                break;
        }

        var detail = '';
        if (s.mountpointEmpty === false) {
            detail = 'Mount point is not empty.';
        } else if (row.volume.AUTOMOUNT === 'keyfile' && s.keyfileOk === false) {
            detail = 'Password file not readable.';
        } else if (!s.backup) {
            detail = 'No config backup on flash yet.';
        }
        return { label: 'Not mounted', tone: 'idle', detail: detail };
    }

    function automountLabel(row) {
        if (row.volume.AUTOMOUNT !== 'keyfile') {
            return 'No';
        }
        return row.status.keyfileOk === false ? 'Yes (file missing)' : 'Yes';
    }

    /* ------------------------------------------------------------ */
    /* Table rendering                                              */
    /* ------------------------------------------------------------ */

    function renderBanner() {
        var banner = document.getElementById('encfs-banner');
        if (state.arrayState !== 'Started') {
            banner.textContent = 'The array is not started (' + state.arrayState + '). Volumes on the array can be mounted once it is running.';
            banner.hidden = false;
        } else {
            banner.hidden = true;
        }
    }

    function actionsFor(row) {
        var name = row.name;
        var st = row.status.state;
        var arrayDown = state.arrayState !== 'Started' ? 'Start the array first' : '';
        var list = [];

        if (state.pending[name]) {
            list.push(button(state.pending[name], null, 'Please wait'));
            return list;
        }

        if (st === 'mounted') {
            list.push(button('Unmount', function () { doUnmount(name, false); }));
        } else if (st === 'stale') {
            list.push(button('Force unmount', function () { confirmForceUnmount(name, ''); }));
        } else if (st === 'unmounted') {
            list.push(button('Mount', function () { openMountDialog(row); }, arrayDown));
        } else if (st === 'uninitialized' || st === 'missing') {
            list.push(button('Initialize', function () { openInitDialog(row); }, arrayDown));
        }

        list.push(button('Info', function () { openInfoDialog(name); }));

        var locked = (st === 'mounted' || st === 'stale') ? 'Unmount first' : '';
        list.push(button('Edit', function () { openEditDialog(row); }, locked));
        list.push(button('Remove', function () { confirmRemove(name); }, locked));
        return list;
    }

    function renderRows() {
        var tbody = document.getElementById('encfs-rows');
        tbody.textContent = '';

        if (state.volumes.length === 0) {
            var emptyRow = el('tr');
            var cell = el('td', 'encfs-muted', 'No volumes configured yet. Use "Add volume" to register an existing encfs directory or to create a new one.');
            cell.colSpan = 6;
            emptyRow.appendChild(cell);
            tbody.appendChild(emptyRow);
            return;
        }

        state.volumes.forEach(function (row) {
            var tr = el('tr');
            var info = describeStatus(row);

            tr.appendChild(labelled(el('td', 'encfs-name', row.name), 'Volume'));
            tr.appendChild(labelled(el('td', 'encfs-path', row.volume.ENCDIR), 'Encrypted'));
            tr.appendChild(labelled(el('td', 'encfs-path', row.volume.MOUNTPOINT), 'Mount point'));

            var statusCell = labelled(el('td'), 'Status');
            statusCell.appendChild(el('span', 'encfs-status ' + info.tone, info.label));
            if (info.detail) {
                statusCell.appendChild(el('span', 'encfs-substatus', info.detail));
            }
            tr.appendChild(statusCell);

            tr.appendChild(labelled(el('td', null, automountLabel(row)), 'Auto-mount'));

            var actionsCell = el('td', 'encfs-actions');
            actionsFor(row).forEach(function (node) {
                actionsCell.appendChild(node);
            });
            tr.appendChild(actionsCell);

            tbody.appendChild(tr);
        });
    }

    function applyList(data) {
        if (data.arrayState) {
            state.arrayState = data.arrayState;
        }
        if (data.volumes) {
            state.volumes = data.volumes;
        }
        if (data.version !== undefined) {
            document.getElementById('encfs-version').textContent =
                data.version ? 'encfs ' + data.version : 'encfs binary not found - reinstall the plugin';
        }
        renderBanner();
        renderRows();
    }

    function refresh() {
        return apiGet('list').then(applyList).catch(function (err) {
            var tbody = document.getElementById('encfs-rows');
            tbody.textContent = '';
            var tr = el('tr');
            var td = el('td', 'encfs-status bad', 'Could not load volumes: ' + err.message);
            td.colSpan = 6;
            tr.appendChild(td);
            tbody.appendChild(tr);
        });
    }

    function findRow(name) {
        for (var i = 0; i < state.volumes.length; i++) {
            if (state.volumes[i].name === name) {
                return state.volumes[i];
            }
        }
        return null;
    }

    /* ------------------------------------------------------------ */
    /* Message / confirmation dialog                                */
    /* ------------------------------------------------------------ */

    var messageDialog = document.getElementById('encfs-message-dialog');

    function showMessage(title, message, details, confirmLabel, onConfirm) {
        role(messageDialog, 'title').textContent = title;
        role(messageDialog, 'message').textContent = message || '';

        var pre = role(messageDialog, 'details');
        pre.textContent = details || '';
        pre.hidden = !details;

        var confirmButton = role(messageDialog, 'confirm');
        var replacement = confirmButton.cloneNode(false);
        confirmButton.parentNode.replaceChild(replacement, confirmButton);
        if (confirmLabel && onConfirm) {
            replacement.value = confirmLabel;
            replacement.hidden = false;
            replacement.addEventListener('click', function () {
                closeDialog(messageDialog);
                onConfirm();
            });
        } else {
            replacement.hidden = true;
        }
        openDialog(messageDialog);
    }

    role(messageDialog, 'cancel').addEventListener('click', function () {
        closeDialog(messageDialog);
    });

    /* ------------------------------------------------------------ */
    /* Mount                                                        */
    /* ------------------------------------------------------------ */

    var mountDialog = document.getElementById('encfs-mount-dialog');
    var mountForm = document.getElementById('encfs-mount-form');
    var mountTarget = null;

    function openMountDialog(row) {
        mountTarget = row.name;
        setField(mountDialog, 'name', row.name);
        setField(mountDialog, 'encdir', row.volume.ENCDIR);
        setField(mountDialog, 'mountpoint', row.volume.MOUNTPOINT);
        role(mountDialog, 'http-warning').hidden = !insecureConnection();
        mountForm.password.value = '';
        mountForm.password.type = 'password';
        openDialog(mountDialog);
        mountForm.password.focus();
    }

    role(mountDialog, 'reveal').addEventListener('click', function () {
        var field = mountForm.password;
        field.type = field.type === 'password' ? 'text' : 'password';
        field.focus();
    });

    role(mountDialog, 'cancel').addEventListener('click', function () {
        mountForm.password.value = '';
        closeDialog(mountDialog);
    });

    mountForm.addEventListener('submit', function (event) {
        event.preventDefault();
        if (state.busy) {
            return;
        }
        var password = mountForm.password.value;
        if (password === '') {
            showError(mountDialog, 'Enter the password.');
            return;
        }
        showError(mountDialog, '');
        setBusy(mountDialog, true, 'Mounting...');

        apiPost('mount', { name: mountTarget, password: password })
            .then(function (data) {
                mountForm.password.value = '';
                setBusy(mountDialog, false);
                closeDialog(mountDialog);
                applyList(data);
            })
            .catch(function (err) {
                setBusy(mountDialog, false);
                showError(mountDialog, err.message);
                mountForm.password.select();
            });
        password = '';
    });

    /* ------------------------------------------------------------ */
    /* Unmount                                                      */
    /* ------------------------------------------------------------ */

    function doUnmount(name, force) {
        state.pending[name] = force ? 'Force unmounting...' : 'Unmounting...';
        renderRows();
        apiPost('unmount', { name: name, force: force ? '1' : '0' })
            .then(function (data) {
                delete state.pending[name];
                applyList(data);
            })
            .catch(function (err) {
                delete state.pending[name];
                if (!force) {
                    confirmForceUnmount(name, err.message);
                } else {
                    showMessage('Unmount failed', err.message);
                }
                refresh();
            });
    }

    function confirmForceUnmount(name, reason) {
        var text = 'Force unmount detaches the volume even if files are open and then stops encfs. ' +
                   'Programs with open files on it will get errors and unsaved writes may be lost.';
        showMessage('Unmount ' + name, text, reason, 'Force unmount', function () {
            doUnmount(name, true);
        });
    }

    /* ------------------------------------------------------------ */
    /* Remove                                                       */
    /* ------------------------------------------------------------ */

    function confirmRemove(name) {
        showMessage('Remove ' + name,
            'Remove this volume from the plugin? The encrypted files, the .encfs6.xml config and the flash backup are left untouched.',
            '', 'Remove', function () {
                apiPost('delete', { name: name })
                    .then(applyList)
                    .catch(function (err) { showMessage('Remove failed', err.message); });
            });
    }

    /* ------------------------------------------------------------ */
    /* Add / edit                                                   */
    /* ------------------------------------------------------------ */

    var editDialog = document.getElementById('encfs-edit-dialog');
    var editForm = document.getElementById('encfs-edit-form');
    var EDIT_FIELDS = ['ENCDIR', 'MOUNTPOINT', 'CONFIG', 'UID', 'GID', 'READONLY', 'IDLE',
                       'LOCK_MOUNTPOINT', 'AUTOMOUNT', 'KEYFILE'];
    var DEFAULTS = {
        ENCDIR: '', MOUNTPOINT: '', CONFIG: '', UID: '99', GID: '100', READONLY: 'no',
        IDLE: '0', LOCK_MOUNTPOINT: 'yes', AUTOMOUNT: 'no', KEYFILE: ''
    };

    function syncKeyfileVisibility() {
        role(editDialog, 'keyfile-block').hidden = editForm.AUTOMOUNT.value !== 'keyfile';
    }

    function openEditDialog(row) {
        var volume = row ? row.volume : DEFAULTS;
        role(editDialog, 'title').textContent = row ? 'Edit ' + row.name : 'Add volume';
        editForm.original.value = row ? row.name : '';
        editForm.volname.value = row ? row.name : '';
        EDIT_FIELDS.forEach(function (key) {
            editForm[key].value = volume[key] !== undefined ? volume[key] : DEFAULTS[key];
        });
        var advanced = editDialog.querySelector('.encfs-advanced');
        var nonDefault = ['CONFIG', 'UID', 'GID', 'READONLY', 'IDLE', 'LOCK_MOUNTPOINT'].some(function (key) {
            return String(volume[key]) !== DEFAULTS[key];
        });
        advanced.open = nonDefault;
        syncKeyfileVisibility();
        openDialog(editDialog);
        editForm.volname.focus();
    }

    editForm.AUTOMOUNT.addEventListener('change', syncKeyfileVisibility);

    role(editDialog, 'cancel').addEventListener('click', function () {
        closeDialog(editDialog);
    });

    editForm.addEventListener('submit', function (event) {
        event.preventDefault();
        if (state.busy) {
            return;
        }
        var fields = { name: editForm.volname.value.trim(), original: editForm.original.value };
        EDIT_FIELDS.forEach(function (key) {
            fields[key] = editForm[key].value.trim();
        });

        setBusy(editDialog, true, 'Saving...');
        apiPost('save', fields)
            .then(function (data) {
                setBusy(editDialog, false);
                closeDialog(editDialog);
                applyList(data);
                var saved = findRow(fields.name);
                if (saved && saved.status.state === 'uninitialized') {
                    showMessage('Volume saved',
                        'The encrypted directory is empty. Use "Initialize" to create a new encfs volume in it.');
                } else if (saved && saved.status.state === 'missing' && state.arrayState === 'Started') {
                    showMessage('Volume saved',
                        'The encrypted directory does not exist yet. If this is a new volume, use "Initialize" to create it; ' +
                        'otherwise check the path.');
                } else if (saved && saved.status.state === 'noconfig') {
                    showMessage('Volume saved',
                        'Warning: the encrypted directory has files but no .encfs6.xml config was found. ' +
                        'Set "Config file" under Advanced if it is stored elsewhere. The volume cannot be mounted without it.');
                }
            })
            .catch(function (err) {
                setBusy(editDialog, false);
                showError(editDialog, err.message);
            });
    });

    document.getElementById('encfs-add').addEventListener('click', function () {
        openEditDialog(null);
    });

    /* ------------------------------------------------------------ */
    /* Initialize                                                   */
    /* ------------------------------------------------------------ */

    var initDialog = document.getElementById('encfs-init-dialog');
    var initForm = document.getElementById('encfs-init-form');
    var initTarget = null;

    function openInitDialog(row) {
        initTarget = row.name;
        setField(initDialog, 'name', row.name);
        setField(initDialog, 'encdir', row.volume.ENCDIR);
        setField(initDialog, 'mountpoint', row.volume.MOUNTPOINT);
        role(initDialog, 'http-warning').hidden = !insecureConnection();
        initForm.reset();
        openDialog(initDialog);
        initForm.mode.focus();
    }

    role(initDialog, 'cancel').addEventListener('click', function () {
        initForm.reset();
        closeDialog(initDialog);
    });

    initForm.addEventListener('submit', function (event) {
        event.preventDefault();
        if (state.busy) {
            return;
        }
        if (initForm.password.value.length < 8) {
            showError(initDialog, 'Use a password of at least 8 characters.');
            return;
        }
        if (initForm.password.value !== initForm.confirm.value) {
            showError(initDialog, 'The passwords do not match.');
            return;
        }
        if (!initForm.ack.checked) {
            showError(initDialog, 'Please confirm that you have read the warning.');
            return;
        }
        showError(initDialog, '');
        setBusy(initDialog, true, 'Creating...');

        apiPost('initialize', { name: initTarget, mode: initForm.mode.value, password: initForm.password.value })
            .then(function (data) {
                initForm.reset();
                setBusy(initDialog, false);
                closeDialog(initDialog);
                applyList(data);
                showMessage('Volume created',
                    'The new volume is mounted. Its .encfs6.xml config was copied to the flash drive; ' +
                    'consider keeping another copy somewhere safe as well.');
            })
            .catch(function (err) {
                setBusy(initDialog, false);
                showError(initDialog, err.message);
            });
    });

    /* ------------------------------------------------------------ */
    /* Info                                                         */
    /* ------------------------------------------------------------ */

    var infoDialog = document.getElementById('encfs-info-dialog');
    var infoTarget = null;

    function addKv(table, key, value) {
        var tr = el('tr');
        tr.appendChild(el('td', null, key));
        tr.appendChild(el('td', null, value));
        table.appendChild(tr);
    }

    function fillInfo(info) {
        var table = role(infoDialog, 'kv');
        var v = info.volume;
        var s = info.status;
        table.textContent = '';
        addKv(table, 'Status', describeStatus({ name: info.name, volume: v, status: s }).label +
              (s.pid ? ' (encfs pid ' + s.pid + ')' : ''));
        addKv(table, 'Encrypted directory', v.ENCDIR);
        addKv(table, 'Mount point', v.MOUNTPOINT);
        addKv(table, 'Config file', info.configFile || 'not found');
        addKv(table, 'Config backup', s.backup ? s.backup.file + ' (' + formatDate(s.backup.mtime) + ')' : 'none');
        addKv(table, 'Owner', v.UID + ':' + v.GID);
        addKv(table, 'Read-only', v.READONLY === 'yes' ? 'Yes' : 'No');
        addKv(table, 'Idle unmount', v.IDLE === '0' ? 'Off' : v.IDLE + ' min');
        addKv(table, 'Lock mount point', v.LOCK_MOUNTPOINT === 'yes'
              ? 'Yes' + (s.locked === true ? ' (locked now)' : '')
              : 'No');
        addKv(table, 'Auto-mount', v.AUTOMOUNT === 'keyfile' ? 'Password file ' + v.KEYFILE : 'No');
        role(infoDialog, 'details').textContent = info.details || '';
        role(infoDialog, 'backup').disabled = !info.configFile;
    }

    function openInfoDialog(name) {
        infoTarget = name;
        setField(infoDialog, 'name', name);
        role(infoDialog, 'kv').textContent = '';
        role(infoDialog, 'details').textContent = 'Loading...';
        openDialog(infoDialog);
        apiGet('info', { name: name })
            .then(function (data) { fillInfo(data.info); })
            .catch(function (err) {
                role(infoDialog, 'details').textContent = '';
                showError(infoDialog, err.message);
            });
    }

    role(infoDialog, 'backup').addEventListener('click', function () {
        showError(infoDialog, '');
        apiPost('backup', { name: infoTarget })
            .then(function (data) {
                applyList(data);
                return apiGet('info', { name: infoTarget });
            })
            .then(function (data) { fillInfo(data.info); })
            .catch(function (err) { showError(infoDialog, err.message); });
    });

    role(infoDialog, 'cancel').addEventListener('click', function () {
        closeDialog(infoDialog);
    });

    /* ------------------------------------------------------------ */
    /* Log                                                          */
    /* ------------------------------------------------------------ */

    var logBox = document.getElementById('encfs-log');
    var logText = document.getElementById('encfs-log-text');

    function loadLog() {
        logText.textContent = 'Loading...';
        apiGet('log')
            .then(function (data) {
                logText.textContent = data.lines.length ? data.lines.join('\n') : 'No EncFS entries in the current syslog.';
                logText.scrollTop = logText.scrollHeight;
            })
            .catch(function (err) { logText.textContent = err.message; });
    }

    logBox.addEventListener('toggle', function () {
        if (logBox.open) {
            loadLog();
        }
    });
    document.getElementById('encfs-log-refresh').addEventListener('click', loadLog);

    /* ------------------------------------------------------------ */
    /* Start                                                        */
    /* ------------------------------------------------------------ */

    // Escape closes a dialog natively; keep our busy flag consistent.
    document.querySelectorAll('dialog.encfs-dialog').forEach(function (dialog) {
        dialog.addEventListener('cancel', function (event) {
            if (state.busy) {
                event.preventDefault();
                return;
            }
            dialog.querySelectorAll('input[type=password]').forEach(function (field) {
                field.value = '';
            });
        });
    });

    refresh();
    setInterval(function () {
        if (document.visibilityState === 'visible' && !anyDialogOpen() && !state.busy) {
            refresh();
        }
    }, POLL_MS);
})();
