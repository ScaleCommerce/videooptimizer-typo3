// Frontend of the VideoOptimizer content elements (ES module, loaded with type="module"):
// native player (hls.js loaded only when needed), click-to-play facade, lightbox dialog and the
// background video of the hero. The hosted player stays a plain iframe.
import {
    attachVideoSource,
    autoplayWhileVisible,
    destroyVideoSource,
    markUnavailable,
    observeVisibility,
} from './video-source.js';
import { parseConfig, safeUrl } from './native-config.js';

const IFRAME_ALLOW = 'autoplay; fullscreen; picture-in-picture';

let dialog = null;
let lastTrigger = null;

function applyStartTime(video, start) {
    if (start > 0) {
        video.addEventListener(
            'loadedmetadata',
            () => {
                video.currentTime = start;
            },
            { once: true }
        );
    }
}

/**
 * Configures a <video> and attaches its source. `click` = the visitor asked for playback (facade,
 * lightbox): playback starts at once and with sound, otherwise autoplay is muted and only while the
 * video is visible. Returns a function that tears everything down.
 */
function setUpVideo(video, wrap, cfg, { click = false } = {}) {
    video.controls = cfg.controls;
    video.loop = cfg.loop;
    video.muted = click ? false : cfg.muted || cfg.autoplay;
    video.autoplay = false;
    video.playsInline = true;
    video.preload = 'none';
    if (cfg.poster) {
        video.poster = cfg.poster;
    }
    if (cfg.title) {
        video.title = cfg.title;
    }
    if (!cfg.controls) {
        video.setAttribute('tabindex', '-1');
    }
    applyStartTime(video, cfg.start);

    let stopAutoplay = null;
    let gone = false;
    const attached = attachVideoSource(
        video,
        { hls: cfg.hls, mp4: cfg.mp4 },
        {
            autoplay: click,
            deferLoad: !click,
            onUnplayable: () => markUnavailable(wrap, video, cfg.unavailable),
        }
    );
    if (!click && cfg.autoplay) {
        attached.then(() => {
            if (!gone) {
                stopAutoplay = autoplayWhileVisible(video, wrap);
            }
        });
    }

    return () => {
        gone = true;
        stopAutoplay?.();
        destroyVideoSource(video);
        video.pause();
        video.removeAttribute('src');
        video.load();
    };
}

function buildNativePlayer(cfg) {
    const wrap = document.createElement('div');
    wrap.className = 'vo-native vo-native--fill' + (cfg.cover ? ' vo-native--cover' : '');
    const video = document.createElement('video');
    video.className = 'vo-native__video';
    wrap.appendChild(video);
    const teardown = setUpVideo(video, wrap, cfg, { click: true });
    return { element: wrap, video, teardown };
}

function buildIframe(src, title) {
    const iframe = document.createElement('iframe');
    iframe.className = 'vo-player';
    iframe.title = title || '';
    iframe.allow = IFRAME_ALLOW;
    iframe.setAttribute('allowfullscreen', '');
    iframe.src = src;
    return { element: iframe, video: null, teardown: () => iframe.removeAttribute('src') };
}

/** Player for a trigger: native when it carries a usable config, else the hosted iframe. */
function buildPlayer(trigger, embedAttribute = 'data-vo-embed') {
    const cfg = parseConfig(trigger.getAttribute('data-vo-native'));
    if (cfg) {
        return buildNativePlayer(cfg);
    }
    const src = safeUrl(trigger.getAttribute(embedAttribute) || '');
    return src ? buildIframe(src, trigger.getAttribute('data-vo-title') || '') : null;
}

// ---------------------------------------------------------------------------------------------
// Lightbox
// ---------------------------------------------------------------------------------------------

let teardownPlayer = null;

function closeLightbox() {
    if (!dialog) {
        return;
    }
    const current = dialog;
    dialog = null;
    teardownPlayer?.();
    teardownPlayer = null;
    if (current.open) {
        current.close();
    }
    current.remove();
    if (lastTrigger && typeof lastTrigger.focus === 'function' && document.contains(lastTrigger)) {
        lastTrigger.focus();
    }
    lastTrigger = null;
}

function openLightbox(player, title, closeLabel, trigger) {
    closeLightbox();
    lastTrigger = trigger;

    const el = document.createElement('dialog');
    el.className = 'vo-lightbox';
    if (title) {
        el.setAttribute('aria-label', title);
    }
    const frame = document.createElement('div');
    frame.className = 'vo-lightbox__frame';
    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'vo-lightbox__close';
    closeBtn.setAttribute('data-vo-close', '');
    closeBtn.setAttribute('aria-label', closeLabel || 'Close');
    closeBtn.textContent = '×';
    frame.appendChild(closeBtn);
    frame.appendChild(player.element);
    el.appendChild(frame);

    // Native close (ESC key) and backdrop clicks.
    el.addEventListener('close', () => {
        if (dialog === el) {
            closeLightbox();
        }
    });
    el.addEventListener('click', (event) => {
        if (event.target === el) {
            closeLightbox();
        }
    });

    document.body.appendChild(el);
    dialog = el;
    teardownPlayer = player.teardown;
    el.showModal();
}

// ---------------------------------------------------------------------------------------------
// Click handling
// ---------------------------------------------------------------------------------------------

document.addEventListener('click', (event) => {
    const target = event.target;
    if (!target || !target.closest) {
        return;
    }

    if (target.closest('[data-vo-close]')) {
        event.preventDefault();
        closeLightbox();
        return;
    }

    // Click-to-play surface of single/detail: poster with play button.
    const surface = target.closest('[data-vo-surface]');
    if (surface && target.closest('[data-vo-play]')) {
        const mode = surface.getAttribute('data-vo-surface');
        const player = buildPlayer(surface);
        if (!player) {
            return;
        }
        event.preventDefault();
        if (mode === 'lightbox' && typeof HTMLDialogElement !== 'undefined') {
            openLightbox(
                player,
                surface.getAttribute('data-vo-title') || '',
                surface.getAttribute('data-vo-close-label') || '',
                target.closest('[data-vo-play]')
            );
            return;
        }
        surface.replaceChildren(player.element);
        surface.classList.add('vo-surface--playing');
        const focusable = player.video ?? player.element;
        // A <video> without controls is not focusable on its own.
        if (focusable.tagName === 'VIDEO' && !focusable.controls) {
            focusable.setAttribute('tabindex', '-1');
        }
        focusable.focus({ preventScroll: true });
        return;
    }

    // Cards, list rows and hero button that open the lightbox.
    const trigger = target.closest('[data-vo-lightbox]');
    if (!trigger || typeof HTMLDialogElement === 'undefined') {
        return;
    }
    const player = buildPlayer(trigger);
    if (!player) {
        // Not an allowed player URL: let the link behave normally.
        return;
    }
    event.preventDefault();
    openLightbox(
        player,
        trigger.getAttribute('data-vo-title') || '',
        trigger.getAttribute('data-vo-close-label') || '',
        trigger
    );
});

// ---------------------------------------------------------------------------------------------
// Native players in the page: directly shown elements and the hero background
// ---------------------------------------------------------------------------------------------

function initDirect(wrap) {
    const cfg = parseConfig(wrap.getAttribute('data-vo-native'));
    const video = wrap.querySelector('video');
    if (!cfg || !video || wrap.hasAttribute('data-vo-ready')) {
        return;
    }
    wrap.setAttribute('data-vo-ready', '');
    // The source is attached shortly before the player scrolls into view, so hls.js is only
    // downloaded when a player is (nearly) visible.
    const stop = observeVisibility(
        wrap,
        (visible) => {
            if (visible) {
                stop();
                setUpVideo(video, wrap, cfg);
            }
        },
        '300px',
        0
    );
}

function initHero(video) {
    const cfg = parseConfig(video.getAttribute('data-vo-native'));
    if (!cfg || video.hasAttribute('data-vo-ready')) {
        return;
    }
    video.setAttribute('data-vo-ready', '');
    cfg.controls = false;
    setUpVideo(video, video.parentElement ?? video, cfg);
}

function init() {
    document.querySelectorAll('.vo-native[data-vo-native]').forEach(initDirect);
    document.querySelectorAll('video[data-vo-hero]').forEach(initHero);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
