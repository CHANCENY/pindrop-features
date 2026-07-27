(function () {
    'use strict';

    // ---- Theme toggle -----------------------------------------------------
    var root = document.documentElement;
    var stored = window.localStorage.getItem('qa-theme');
    if (stored) { root.setAttribute('data-theme', stored); }

    var toggleBtn = document.getElementById('qa-theme-toggle');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            root.setAttribute('data-theme', next);
            window.localStorage.setItem('qa-theme', next);
        });
    }

    // ---- AJAX forms (vote / bookmark) --------------------------------------
    // Any <form class="qa-ajax-form"> is submitted via fetch instead of a
    // full page reload. The hidden _csrf_token field is auto-injected by
    // core into every rendered <form>, so FormData already carries it.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.classList || !form.classList.contains('qa-ajax-form')) {
            return;
        }
        e.preventDefault();

        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                var event = new CustomEvent('qa:ajax-success', { detail: { form: form, data: data } });
                document.dispatchEvent(event);
            })
            .catch(function (err) {
                console.error('Q&A request failed', err);
            });
    });

    // ---- Vote widgets: update the count + active state in place -----------
    document.addEventListener('qa:ajax-success', function (e) {
        var form = e.detail.form;
        var data = e.detail.data;

        if (!data || data.success !== true) {
            return;
        }

        if (form.dataset.qaAction === 'vote') {
            var widget = form.closest('.qa-vote-widget');
            if (widget) {
                var countEl = widget.querySelector('.qa-vote-count');
                if (countEl && typeof data.votes_count !== 'undefined') {
                    countEl.textContent = data.votes_count;
                }
                widget.querySelectorAll('.qa-vote-btn').forEach(function (btn) {
                    btn.classList.remove('active-up', 'active-down');
                });
                if (data.action === 'created' || data.action === 'switched') {
                    var type = form.querySelector('input[name="vote_type"]').value;
                    var activeBtn = widget.querySelector('.qa-vote-btn[data-type="' + type + '"]');
                    if (activeBtn) {
                        activeBtn.classList.add(type === 'upvote' ? 'active-up' : 'active-down');
                    }
                }
            }
        }

        if (form.dataset.qaAction === 'bookmark') {
            var btn = form.querySelector('.qa-icon-btn');
            if (btn) {
                btn.classList.toggle('active-bookmark', data.bookmarked === true);
                btn.innerHTML = data.bookmarked
                    ? '<i class="fa-solid fa-bookmark"></i> Bookmarked'
                    : '<i class="fa-regular fa-bookmark"></i> Bookmark';
            }
        }
    });
})();
