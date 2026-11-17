/**
 * Lightweight SignaturePad
 * Minimal vanilla-JS canvas signature widget. No external dependencies.
 *
 * Usage:
 *   const pad = new SignaturePad(canvasEl, { penColor: '#000', lineWidth: 2 });
 *   pad.isEmpty();              // true / false
 *   pad.toDataURL();            // 'data:image/png;base64,...'
 *   pad.fromDataURL(dataUrl);   // restore a previous signature (stretch to fit)
 *   pad.loadImageFit(dataUrl);  // draw an UPLOADED image, aspect preserved
 *   pad.clear();                // erase
 *
 *   SignaturePad.forCanvas(canvasOrId)   // the live pad for a canvas, or null
 */
(function (global) {
    'use strict';

    // canvas element -> pad, so shared helpers (see includes/signature_upload_control.php)
    // can find the right instance without knowing the page's variable names.
    // Keyed on the element itself, and re-keyed on every construction, because
    // some screens re-create their pad each time a modal opens.
    var registry = [];

    function SignaturePad(canvas, options) {
        options = options || {};
        this.canvas = canvas;
        this.ctx = canvas.getContext('2d');
        this.penColor = options.penColor || '#000000';
        this.lineWidth = options.lineWidth || 2;
        this.backgroundColor = options.backgroundColor || 'rgba(255,255,255,0)';
        this._empty = true;
        this._points = [];
        this._drawing = false;

        this._resize();
        this._bind();

        for (var i = 0; i < registry.length; i++) {
            if (registry[i].canvas === canvas) {
                registry[i].pad = this;
                return;
            }
        }
        registry.push({ canvas: canvas, pad: this });
    }

    /**
     * The pad currently bound to a canvas, by element or by id. Null when the
     * canvas doesn't exist or no pad was constructed for it — which is the
     * normal case on screens where the signer already has a signature on file
     * and the canvas is never rendered.
     */
    SignaturePad.forCanvas = function (canvasOrId) {
        var el = typeof canvasOrId === 'string' ? document.getElementById(canvasOrId) : canvasOrId;
        if (!el) return null;
        for (var i = 0; i < registry.length; i++) {
            if (registry[i].canvas === el) return registry[i].pad;
        }
        return null;
    };

    SignaturePad.prototype._resize = function () {
        var canvas = this.canvas;
        var ratio = Math.max(window.devicePixelRatio || 1, 1);
        // Read the CSS size BEFORE setting width/height (which resets the canvas)
        var rect = canvas.getBoundingClientRect();
        var cssW = rect.width || canvas.clientWidth || canvas.width;
        var cssH = rect.height || canvas.clientHeight || canvas.height;
        canvas.width = cssW * ratio;
        canvas.height = cssH * ratio;
        this.ctx.scale(ratio, ratio);
        this.ctx.lineCap = 'round';
        this.ctx.lineJoin = 'round';
        this.ctx.strokeStyle = this.penColor;
        this.ctx.lineWidth = this.lineWidth;
        this._cssWidth = cssW;
        this._cssHeight = cssH;
    };

    SignaturePad.prototype._bind = function () {
        var self = this;
        var canvas = this.canvas;

        function getPoint(e) {
            var rect = canvas.getBoundingClientRect();
            var clientX = e.clientX != null ? e.clientX : (e.touches && e.touches[0] ? e.touches[0].clientX : 0);
            var clientY = e.clientY != null ? e.clientY : (e.touches && e.touches[0] ? e.touches[0].clientY : 0);
            return { x: clientX - rect.left, y: clientY - rect.top };
        }

        function start(e) {
            e.preventDefault();
            self._drawing = true;
            self._points = [getPoint(e)];
        }

        function move(e) {
            if (!self._drawing) return;
            e.preventDefault();
            var p = getPoint(e);
            self._points.push(p);
            self._drawSegment();
            self._empty = false;
        }

        function end(e) {
            if (!self._drawing) return;
            e.preventDefault();
            self._drawing = false;
            self._points = [];
        }

        // Pointer events cover mouse, touch, stylus uniformly
        if (window.PointerEvent) {
            canvas.addEventListener('pointerdown', start);
            canvas.addEventListener('pointermove', move);
            canvas.addEventListener('pointerup', end);
            canvas.addEventListener('pointerleave', end);
            canvas.addEventListener('pointercancel', end);
        } else {
            canvas.addEventListener('mousedown', start);
            canvas.addEventListener('mousemove', move);
            canvas.addEventListener('mouseup', end);
            canvas.addEventListener('mouseleave', end);
            canvas.addEventListener('touchstart', start);
            canvas.addEventListener('touchmove', move);
            canvas.addEventListener('touchend', end);
            canvas.addEventListener('touchcancel', end);
        }
    };

    SignaturePad.prototype._drawSegment = function () {
        var pts = this._points;
        var n = pts.length;
        if (n < 2) return;
        var ctx = this.ctx;
        if (n === 2) {
            ctx.beginPath();
            ctx.moveTo(pts[0].x, pts[0].y);
            ctx.lineTo(pts[1].x, pts[1].y);
            ctx.stroke();
            return;
        }
        // Quadratic Bezier: control = previous point, end = midpoint
        var p1 = pts[n - 3];
        var p2 = pts[n - 2];
        var p3 = pts[n - 1];
        var midA = { x: (p1.x + p2.x) / 2, y: (p1.y + p2.y) / 2 };
        var midB = { x: (p2.x + p3.x) / 2, y: (p2.y + p3.y) / 2 };
        ctx.beginPath();
        ctx.moveTo(midA.x, midA.y);
        ctx.quadraticCurveTo(p2.x, p2.y, midB.x, midB.y);
        ctx.stroke();
    };

    SignaturePad.prototype.isEmpty = function () {
        return this._empty;
    };

    SignaturePad.prototype.clear = function () {
        this.ctx.save();
        this.ctx.setTransform(1, 0, 0, 1, 0, 0);
        this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
        this.ctx.restore();
        // Re-apply pen state (scale was reset)
        var ratio = Math.max(window.devicePixelRatio || 1, 1);
        this.ctx.scale(ratio, ratio);
        this.ctx.lineCap = 'round';
        this.ctx.lineJoin = 'round';
        this.ctx.strokeStyle = this.penColor;
        this.ctx.lineWidth = this.lineWidth;
        this._empty = true;
        this._points = [];
    };

    SignaturePad.prototype.toDataURL = function (type) {
        return this.canvas.toDataURL(type || 'image/png');
    };

    SignaturePad.prototype.fromDataURL = function (dataUrl) {
        var self = this;
        var img = new Image();
        img.onload = function () {
            self.clear();
            self.ctx.save();
            self.ctx.setTransform(1, 0, 0, 1, 0, 0);
            self.ctx.drawImage(img, 0, 0, self.canvas.width, self.canvas.height);
            self.ctx.restore();
            self._empty = false;
        };
        img.src = dataUrl;
    };

    /**
     * Draw an image onto the pad CENTRED and scaled to fit, preserving its
     * aspect ratio (never enlarged past 1:1).
     *
     * Separate from fromDataURL() on purpose. That one stretches the image to
     * the full canvas, which is right for what it is used for — reloading a
     * signature that was captured on a canvas of this shape. A photo or scan of
     * a signature has an arbitrary aspect ratio, and stretching it to the pad
     * distorts the handwriting.
     *
     * @param {string}   dataUrl
     * @param {Function} [onDone] called with (true) on success, (false) if the
     *                            image could not be decoded
     */
    SignaturePad.prototype.loadImageFit = function (dataUrl, onDone) {
        var self = this;
        var img = new Image();

        img.onload = function () {
            var cw = self._cssWidth || self.canvas.width;
            var ch = self._cssHeight || self.canvas.height;

            var scale = Math.min(cw / img.width, ch / img.height, 1);
            var w = img.width * scale;
            var h = img.height * scale;

            self.clear();
            // clear() leaves the DPR scale applied, so draw in CSS pixels.
            self.ctx.drawImage(img, (cw - w) / 2, (ch - h) / 2, w, h);
            self._empty = false;

            if (typeof onDone === 'function') onDone(true);
        };

        img.onerror = function () {
            if (typeof onDone === 'function') onDone(false);
        };

        img.src = dataUrl;
    };

    global.SignaturePad = SignaturePad;
})(window);
