/* Decorative only. Pointer movement is never part of verification or sent anywhere. */
(function () {
    'use strict';
    var stage = document.getElementById('bg-stage'), tilt = document.getElementById('bg-tilt');
    if (!stage || !tilt || !window.matchMedia || !window.requestAnimationFrame) return;
    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
    var pointer = window.matchMedia('(hover: hover) and (pointer: fine)');
    if (reduced.matches || !pointer.matches) return;
    var frame = 0, x = 0, y = 0;
    function draw() {
        frame = 0;
        tilt.style.setProperty('--bg-tilt-x', y + 'deg');
        tilt.style.setProperty('--bg-tilt-y', x + 'deg');
    }
    function reset() {
        if (frame) window.cancelAnimationFrame(frame);
        x = y = 0;
        draw();
    }
    stage.addEventListener('pointermove', function (event) {
        if (reduced.matches || !pointer.matches) return;
        var rect = stage.getBoundingClientRect();
        x = Math.max(-9, Math.min(9, (event.clientX - rect.left - rect.width / 2) / rect.width * 18));
        y = Math.max(-7, Math.min(7, -(event.clientY - rect.top - rect.height / 2) / rect.height * 14));
        if (!frame) frame = window.requestAnimationFrame(draw);
    }, {passive: true});
    stage.addEventListener('pointerleave', reset);
    if (reduced.addEventListener) reduced.addEventListener('change', function () {
        reset();
    });
})();
