// Frontend of the VideoOptimizer content elements (ES module, loaded with type="module"):
// native player (hls.js loaded only when needed), click-to-play facade, lightbox dialog and the
// background video of the hero. The hosted player stays a plain iframe.
import {
    attachVideoSource,
    autoplayWhileVisible,
    destroyVideoSource,
    applyTracks,
    bindPlaybackToggle,
    markUnavailable,
    motionReduced,
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
function setUpVideo(video, wrap, cfg, { click = false, shouldPlay = () => false, still = false, onUnplayable = null } = {}) {
    video.controls = cfg.controls;
    video.loop = cfg.loop;
    video.muted = click ? false : cfg.muted || cfg.autoplay;
    video.autoplay = false;
    video.playsInline = true;
    video.preload = click ? 'auto' : cfg.preload;
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
    applyTracks(video, cfg.tracks);

    let stopAutoplay = null;
    let gone = false;
    const attached = attachVideoSource(
        video,
        { hls: cfg.hls, mp4: cfg.mp4 },
        {
            autoplay: click,
            deferLoad: !click,
            shouldPlay,
            onUnplayable: () => (onUnplayable ?? (() => markUnavailable(wrap, video, cfg.unavailable)))(),
        }
    );
    // With reduced motion / data saver nothing starts by itself (`still`).
    if (!click && !still && cfg.autoplay) {
        attached.then(() => {
            if (!gone) {
                stopAutoplay = autoplayWhileVisible(video, wrap);
            }
        });
    }

    const teardown = () => {
        gone = true;
        stopAutoplay?.();
        destroyVideoSource(video);
        video.pause();
        video.removeAttribute('src');
        video.load();
    };
    teardown.attached = attached;
    return teardown;
}

function buildNativePlayer(cfg) {
    const wrap = document.createElement('div');
    wrap.className = 'vo-native vo-native--fill' + (cfg.cover ? ' vo-native--cover' : '');
    const video = document.createElement('video');
    video.className = 'vo-native__video';
    if (cfg.accent) {
        wrap.style.setProperty('--vo-accent', cfg.accent);
    }
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
let inertElements = [];

// Escape closes the lightbox wherever the focus is inside this document (close button, native
// controls). Focus inside a cross-origin iframe never reaches the page: browsers give the iframe
// the keyboard there, so the close button and the backdrop remain the way out.
function onKeydown(event) {
    if (event.key === 'Escape' && dialog) {
        event.preventDefault();
        event.stopPropagation();
        closeLightbox();
    }
}

// showModal() already makes the page inert in current browsers; this covers the rest explicitly
// (and screen readers that ignore the top layer).
function inertPage(except) {
    inertElements = [...document.body.children].filter(
        (element) => element !== except && !element.hasAttribute('inert') && !['SCRIPT', 'STYLE', 'LINK'].includes(element.tagName)
    );
    inertElements.forEach((element) => element.setAttribute('inert', ''));
}

function restorePage() {
    inertElements.forEach((element) => element.removeAttribute('inert'));
    inertElements = [];
}

function closeLightbox() {
    if (!dialog) {
        return;
    }
    const current = dialog;
    dialog = null;
    teardownPlayer?.();
    teardownPlayer = null;
    document.removeEventListener('keydown', onKeydown, true);
    restorePage();
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
    inertPage(el);
    document.addEventListener('keydown', onKeydown, true);
    el.showModal();
    // Focus starts on the close button, not inside an iframe, so Escape works right away.
    closeBtn.focus();
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
    const section = video.closest('.vo-hero');
    const button = section?.querySelector('[data-vo-toggle]');
    const still = motionReduced();
    let toggle = null;
    const teardown = setUpVideo(video, section ?? video, cfg, {
        still,
        // A click on the button before the source is ready is honoured once it is.
        shouldPlay: () => toggle?.shouldPlay() ?? false,
        onUnplayable: () => {
            video.removeAttribute('src');
            button?.setAttribute('hidden', '');
        },
    });
    if (button) {
        toggle = bindPlaybackToggle(video, button, teardown.attached, {
            play: button.getAttribute('data-vo-label-play') || 'Play background video',
            pause: button.getAttribute('data-vo-label-pause') || 'Pause background video',
        });
    }
}

// Hosted hero with reduced motion: the iframe would autoplay a looping video, so it is swapped for
// the still (autoplay off) player URL the server rendered next to it.
function initHostedHero(frame) {
    if (!motionReduced()) {
        return;
    }
    const still = safeUrl(frame.getAttribute('data-vo-still') || '');
    if (still) {
        frame.src = still;
    }
}

function init() {
    document.querySelectorAll('.vo-native[data-vo-native]').forEach(initDirect);
    document.querySelectorAll('video[data-vo-hero]').forEach(initHero);
    document.querySelectorAll('iframe[data-vo-still]').forEach(initHostedHero);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
