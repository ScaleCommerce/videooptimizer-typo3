(function () {
    'use strict';

    var ALLOWED_HOST = 'videooptimizer.eu';
    var dialog = null;
    var lastTrigger = null;

    /**
     * Only https URLs on videooptimizer.eu (or a sub-domain) may be loaded into the lightbox iframe.
     * Returns the normalized URL or '' when not allowed.
     */
    function safeEmbedUrl(raw) {
        var url;
        try {
            url = new URL(raw, window.location.href);
        } catch (e) {
            return '';
        }
        var host = url.hostname.toLowerCase();
        if (url.protocol !== 'https:' || (host !== ALLOWED_HOST && !host.endsWith('.' + ALLOWED_HOST))) {
            return '';
        }
        return url.href;
    }

    function close() {
        if (!dialog) {
            return;
        }
        var current = dialog;
        dialog = null;
        var frame = current.querySelector('iframe');
        if (frame) {
            // Clearing src stops playback.
            frame.src = 'about:blank';
            frame.removeAttribute('src');
        }
        if (current.open) {
            current.close();
        }
        current.remove();
        if (lastTrigger && typeof lastTrigger.focus === 'function' && document.contains(lastTrigger)) {
            lastTrigger.focus();
        }
        lastTrigger = null;
    }

    function open(src, title, closeLabel, trigger) {
        close();
        lastTrigger = trigger;

        var el = document.createElement('dialog');
        el.className = 'vo-lightbox';
        if (title) {
            el.setAttribute('aria-label', title);
        }

        var frameWrap = document.createElement('div');
        frameWrap.className = 'vo-lightbox__frame';

        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'vo-lightbox__close';
        closeBtn.setAttribute('data-vo-close', '');
        closeBtn.setAttribute('aria-label', closeLabel || 'Close');
        closeBtn.textContent = '×';

        var iframe = document.createElement('iframe');
        iframe.title = title || '';
        iframe.allow = 'autoplay; fullscreen; picture-in-picture';
        iframe.setAttribute('allowfullscreen', '');
        iframe.src = src;

        frameWrap.appendChild(closeBtn);
        frameWrap.appendChild(iframe);
        el.appendChild(frameWrap);

        // Native close (ESC key) and backdrop clicks.
        el.addEventListener('close', function () {
            if (dialog === el) {
                close();
            }
        });
        el.addEventListener('click', function (event) {
            if (event.target === el) {
                close();
            }
        });

        document.body.appendChild(el);
        dialog = el;
        el.showModal();
    }

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!target || !target.closest) {
            return;
        }

        var closer = target.closest('[data-vo-close]');
        if (closer) {
            event.preventDefault();
            close();
            return;
        }

        var trigger = target.closest('[data-vo-lightbox]');
        if (!trigger || typeof HTMLDialogElement === 'undefined') {
            return;
        }
        var src = safeEmbedUrl(trigger.getAttribute('data-vo-embed') || '');
        if (src === '') {
            // Not an allowed player URL: let the link behave normally.
            return;
        }
        event.preventDefault();
        open(
            src,
            trigger.getAttribute('data-vo-title') || '',
            trigger.getAttribute('data-vo-close-label') || '',
            trigger
        );
    });
})();
