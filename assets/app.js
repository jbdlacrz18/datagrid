/* Modal open/close + add/edit record form population. */
(function () {
    'use strict';

    function openModal(id) {
        var m = document.getElementById(id);
        if (m) { m.classList.add('open'); m.setAttribute('aria-hidden', 'false'); }
    }
    function closeModal(m) {
        m.classList.remove('open');
        m.setAttribute('aria-hidden', 'true');
    }

    var recordForm = document.getElementById('record-form');
    var recordTitle = document.getElementById('record-modal-title');

    // Fill the shared record form for either "add" (rec = null) or "edit".
    function fillForm(rec) {
        recordForm.querySelector('[name=action]').value = rec ? 'update' : 'create';
        recordForm.querySelector('[name=id]').value = rec ? rec.id : '';
        recordTitle.textContent = rec ? 'Edit record #' + rec.id : 'Add record';

        recordForm.querySelectorAll('[data-field]').forEach(function (el) {
            var name = el.getAttribute('name');
            var val = rec ? rec[name] : null;
            if (el.type === 'checkbox') {
                el.checked = (val === 1 || val === '1' || val === true);
            } else {
                el.value = (val === null || val === undefined) ? '' : val;
            }
        });
    }

    // Add record.
    var addBtn = document.getElementById('add-record-btn');
    if (addBtn) {
        addBtn.addEventListener('click', function () {
            fillForm(null);
            openModal('record-modal');
        });
    }

    // Edit record (data is embedded on each button as JSON).
    document.querySelectorAll('.edit-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            try {
                fillForm(JSON.parse(btn.getAttribute('data-record')));
                openModal('record-modal');
            } catch (e) { console.error('Bad record data', e); }
        });
    });

    // Generic "open modal" triggers.
    document.querySelectorAll('[data-open-modal]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openModal(btn.getAttribute('data-open-modal'));
        });
    });

    // Close handlers (backdrop, x, cancel).
    document.querySelectorAll('[data-close-modal]').forEach(function (el) {
        el.addEventListener('click', function () {
            var m = el.closest('.modal');
            if (m) { closeModal(m); }
        });
    });

    // Esc closes any open modal.
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal.open').forEach(closeModal);
        }
    });

    // Auto-dismiss flash messages.
    document.querySelectorAll('.flash').forEach(function (f) {
        setTimeout(function () { f.classList.add('hide'); }, 4000);
        setTimeout(function () { f.remove(); }, 4500);
    });
})();
