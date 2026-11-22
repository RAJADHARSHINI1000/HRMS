/* HRMS shared JavaScript (PeopleFlow theme) - no frameworks */
(function () {
    'use strict';

    window.toast = function (msg) {
        var t = document.getElementById('toast');
        if (!t) return;
        t.textContent = msg;
        t.classList.add('show');
        setTimeout(function () { t.classList.remove('show'); }, 2600);
    };

    // Mobile sidebar
    var sidebar = document.getElementById('sidebar');
    var toggle = document.getElementById('sidebarToggle');
    if (toggle && sidebar) {
        toggle.addEventListener('click', function (e) { e.stopPropagation(); sidebar.classList.toggle('open'); });
        document.addEventListener('click', function (e) {
            if (sidebar.classList.contains('open') && !sidebar.contains(e.target)) sidebar.classList.remove('open');
        });
    }

    // ---- Modals:  <button data-bs-toggle="modal" data-bs-target="#id" data-reset data-title="..." data-fill='{"field":"value"}'>
    function fillModal(modal, trigger) {
        var form = modal.querySelector('form');
        if (trigger.hasAttribute('data-reset') && form) form.reset();
        if (trigger.dataset.title) {
            var t = modal.querySelector('.modal-title');
            if (t) t.textContent = trigger.dataset.title;
        }
        if (trigger.dataset.fill) {
            var data = {};
            try { data = JSON.parse(trigger.dataset.fill); } catch (err) { data = {}; }
            if (form) {
                Object.keys(data).forEach(function (k) {
                    var el = form.elements[k];
                    if (!el) return;
                    var v = data[k] === null ? '' : data[k];
                    if (el.type === 'checkbox') el.checked = String(v) === '1';
                    else el.value = v;
                });
            }
            modal.querySelectorAll('[data-view]').forEach(function (n) {
                var v = data[n.getAttribute('data-view')];
                n.textContent = (v === null || v === undefined || v === '') ? '—' : v;
            });
        }
    }
    function openModal(modal, trigger) {
        document.querySelectorAll('.modal.show').forEach(function (o) { if (o !== modal) closeModal(o); });
        if (trigger) fillModal(modal, trigger);
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        var first = modal.querySelector('input:not([type=hidden]):not([disabled]), select, textarea');
        if (first) setTimeout(function () { try { first.focus(); } catch (e) {} }, 60);
    }
    function closeModal(modal) {
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }

    document.addEventListener('click', function (e) {
        var opener = e.target.closest('[data-bs-toggle="modal"]');
        if (opener) {
            var m = document.querySelector(opener.getAttribute('data-bs-target'));
            if (m) { e.preventDefault(); openModal(m, opener); }
            return;
        }
        var closer = e.target.closest('[data-bs-dismiss="modal"]');
        if (closer) {
            var mm = closer.closest('.modal');
            if (mm) closeModal(mm);
            return;
        }
        var alertClose = e.target.closest('[data-bs-dismiss="alert"]');
        if (alertClose) {
            var a = alertClose.closest('.alert');
            if (a) a.remove();
            return;
        }
        if (e.target.classList && e.target.classList.contains('modal') && e.target.classList.contains('show')) {
            closeModal(e.target);
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') document.querySelectorAll('.modal.show').forEach(closeModal);
    });

    // Confirm before destructive / important actions:  <form data-confirm="Are you sure?">
    document.addEventListener('submit', function (ev) {
        var msg = ev.target.getAttribute && ev.target.getAttribute('data-confirm');
        if (msg && !window.confirm(msg)) ev.preventDefault();
    });

    // Prevent double submits (re-enabled if the page is restored from the back/forward cache)
    document.addEventListener('submit', function (ev) {
        if (ev.defaultPrevented) return;
        var btn = ev.target.querySelector && ev.target.querySelector('button[type=submit]:not([data-keep])');
        if (btn) setTimeout(function () { btn.disabled = true; }, 0);
    });
    window.addEventListener('pageshow', function () {
        document.querySelectorAll('button[type=submit][disabled]').forEach(function (b) { b.disabled = false; });
    });

    // Fade out success alerts
    setTimeout(function () {
        document.querySelectorAll('.alert-success').forEach(function (a) { a.remove(); });
    }, 6000);

    // Login page role picker (visual choice; the server checks the real role)
    document.querySelectorAll('[data-role]').forEach(function (b) {
        b.addEventListener('click', function () {
            document.querySelectorAll('[data-role]').forEach(function (x) { x.classList.toggle('selected', x === b); });
            var h = document.getElementById('roleInput');
            if (h) h.value = b.dataset.role;
        });
    });
})();
