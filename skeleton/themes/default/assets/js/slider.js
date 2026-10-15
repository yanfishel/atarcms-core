// The home page's slider (partials/hero-slider.php, loaded only on a page with data-slider): the slides fade into
// each other every INTERVAL ms; the arrows, the dots and the arrow keys (turned with the page direction, so a
// right-to-left page goes the other way) choose one. It never moves by itself under prefers-reduced-motion or the
// accessibility toolbar's "Stop animations" (a11y-still on <html>, watched while the page is open), while the pointer
// rests on it or the focus is inside, while the tab is hidden, or after Pause. The slides not shown are inert. A slide's
// video (muted, looping) plays while that slide is shown and motion is allowed (not still, the tab visible, no Pause;
// hovering holds only the turning), and pauses otherwise; a browser that refuses to play leaves the poster. A single
// video slide comes here too (data-slider), with Pause alone. Without JavaScript the first slide shows (site.css) and
// a video its poster. Classes, ARIA properties and textContent only (no markup, no styles).

const INTERVAL = 6000;
const root = document.documentElement;
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
const still = () => reducedMotion.matches || root.classList.contains('a11y-still');

for (const slider of document.querySelectorAll('[data-slider]')) {
    const slides = [...slider.querySelectorAll('[data-slide]')];
    const dots = [...slider.querySelectorAll('[data-slider-dot]')];
    const viewport = slider.querySelector('[data-slider-viewport]');
    const pause = slider.querySelector('[data-slider-pause]');
    let current = 0;
    let timer = null;
    let paused = false; // the visitor pressed Pause
    let held = false; // the pointer rests on the slider or the focus is inside

    const videosMove = () => !paused && !still() && !document.hidden;

    // The shown slide's video plays while motion is allowed; every other video stays paused where it is.
    const playVideos = () => {
        slides.forEach((slide, number) => {
            const video = slide.querySelector('[data-slide-video]');
            if (video === null) {
                return;
            }
            if (videosMove() && number === current) {
                video.muted = true;
                video.play()?.catch(() => {});
            } else {
                video.pause();
            }
        });
    };

    const show = (index) => {
        current = (index + slides.length) % slides.length;
        slides.forEach((slide, number) => {
            const active = number === current;
            slide.classList.toggle('is-active', active);
            slide.inert = !active;
        });
        dots.forEach((dot, number) => {
            dot.ariaCurrent = number === current ? 'true' : null;
        });
        playVideos();
    };

    function shouldRun() {
        return !paused && !held && !still() && !document.hidden;
    }

    // Starts or stops the timer by the state above (one slide never turns); a moving slider is never announced
    // (aria-live off), a still one may be (polite; whether a class toggle is read out is up to the screen reader).
    const update = () => {
        playVideos();
        if (slides.length > 1 && shouldRun()) {
            timer ??= window.setInterval(() => show(current + 1), INTERVAL);
        } else if (timer !== null) {
            window.clearInterval(timer);
            timer = null;
        }
        viewport.ariaLive = timer === null ? 'polite' : 'off';
        // Nothing moves by itself under reduced motion (no turning, no video): no Pause to offer.
        pause.hidden = still();
        slider.classList.toggle('is-paused', paused);
    };

    // A choice restarts the wait, so a slide just chosen stays its full time.
    const choose = (index) => {
        show(index);
        if (timer !== null) {
            window.clearInterval(timer);
            timer = null;
        }
        update();
    };

    // One video slide has no arrows and no dots.
    slider.querySelector('[data-slider-previous]')?.addEventListener('click', () => choose(current - 1));
    slider.querySelector('[data-slider-next]')?.addEventListener('click', () => choose(current + 1));
    for (const dot of dots) {
        dot.addEventListener('click', () => choose(Number(dot.dataset.sliderDot)));
    }
    pause.addEventListener('click', () => {
        paused = !paused;
        // The icon-only button: its name and tooltip follow the state (the press already hid the open tip).
        const label = paused ? pause.dataset.playLabel : pause.dataset.pauseLabel;
        pause.ariaLabel = label;
        pause.title = label;
        update();
    });
    slider.addEventListener('keydown', (event) => {
        // On the controls only: a slide's own link or button keeps its keys.
        if ((event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') || event.target.closest('[data-slide]') !== null || slides.length < 2) {
            return;
        }
        const forward = (event.key === 'ArrowRight') !== (getComputedStyle(slider).direction === 'rtl');
        event.preventDefault();
        choose(current + (forward ? 1 : -1));
    });
    slider.addEventListener('pointerenter', () => {
        held = true;
        update();
    });
    slider.addEventListener('pointerleave', () => {
        held = slider.contains(document.activeElement);
        update();
    });
    slider.addEventListener('focusin', () => {
        held = true;
        update();
    });
    slider.addEventListener('focusout', (event) => {
        held = slider.contains(event.relatedTarget) || slider.matches(':hover');
        update();
    });

    show(0);
    update();
    reducedMotion.addEventListener('change', update);
    document.addEventListener('visibilitychange', update);
    new MutationObserver(update).observe(root, { attributes: true, attributeFilter: ['class'] });
}
