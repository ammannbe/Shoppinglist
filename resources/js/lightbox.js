// Full view of a product image (Alpine.store('lightbox'), <dialog> in app.blade.php).
// Shows the thumbnail at once and the large image as soon as it is loaded (offline the
// thumbnail stays). Zoom: double tap/click, two fingers or the mouse wheel; drag to move
// a zoomed image. Closes with a tap on the unzoomed image, ✕, Esc or the back button.

const MAX_SCALE = 4;
const DOUBLE_TAP = 300;
const TAP_MOVE = 8;

export function createLightboxStore() {
    return {
        image: null,
        loaded: false,
        scale: 1,
        x: 0,
        y: 0,
        pointers: new Map(),
        gesture: null,
        lastTap: null,
        tapTimer: null,
        dialog: null,

        get transform() {
            return `translate3d(${this.x}px, ${this.y}px, 0) scale(${this.scale})`;
        },

        open(image, dialog) {
            if (!image) return;
            this.dialog = dialog;
            this.image = image;
            this.loaded = false;
            this.reset();
            // The back button (Android) closes it like any dialog (layers.js).
            dialog.showModal();
        },

        close() {
            this.dialog?.close();
        },

        /**
         * Dialog closed (✕, Esc, tap or back button).
         */
        closed() {
            this.image = null;
            this.pointers.clear();
            this.gesture = null;
        },

        reset() {
            this.scale = 1;
            this.x = 0;
            this.y = 0;
        },

        /**
         * Zoom to `scale` around the point (px, py) relative to the centre of the view.
         */
        zoomTo(scale, px = 0, py = 0) {
            const next = Math.min(Math.max(scale, 1), MAX_SCALE);
            this.x = px - (px - this.x) * (next / this.scale);
            this.y = py - (py - this.y) * (next / this.scale);
            this.scale = next;
            if (next === 1) this.reset();
            this.clamp();
        },

        /**
         * A zoomed image may not be moved out of the view.
         */
        clamp() {
            const view = this.dialog?.getBoundingClientRect();
            if (!view) return;
            const maxX = (view.width * (this.scale - 1)) / 2;
            const maxY = (view.height * (this.scale - 1)) / 2;
            this.x = Math.min(Math.max(this.x, -maxX), maxX);
            this.y = Math.min(Math.max(this.y, -maxY), maxY);
        },

        relative(event) {
            const view = this.dialog.getBoundingClientRect();

            return { x: event.clientX - view.left - view.width / 2, y: event.clientY - view.top - view.height / 2 };
        },

        down(event) {
            event.currentTarget.setPointerCapture?.(event.pointerId);
            this.pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
            const points = [...this.pointers.values()];

            if (points.length === 2) {
                const mid = this.relative({ clientX: (points[0].x + points[1].x) / 2, clientY: (points[0].y + points[1].y) / 2 });
                this.gesture = { type: 'pinch', distance: distance(points), scale: this.scale, mid, x: this.x, y: this.y };
            } else if (points.length === 1) {
                this.gesture = { type: 'pan', startX: event.clientX, startY: event.clientY, x: this.x, y: this.y, moved: false, time: Date.now() };
            }
        },

        move(event) {
            if (!this.pointers.has(event.pointerId)) return;
            this.pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
            const gesture = this.gesture;
            if (!gesture) return;

            if (gesture.type === 'pinch' && this.pointers.size === 2) {
                const ratio = distance([...this.pointers.values()]) / gesture.distance;
                const next = Math.min(Math.max(gesture.scale * ratio, 1), MAX_SCALE);
                this.x = gesture.mid.x - (gesture.mid.x - gesture.x) * (next / gesture.scale);
                this.y = gesture.mid.y - (gesture.mid.y - gesture.y) * (next / gesture.scale);
                this.scale = next;
                this.clamp();
            } else if (gesture.type === 'pan') {
                const dx = event.clientX - gesture.startX;
                const dy = event.clientY - gesture.startY;
                if (Math.abs(dx) > TAP_MOVE || Math.abs(dy) > TAP_MOVE) gesture.moved = true;
                if (this.scale > 1) {
                    this.x = gesture.x + dx;
                    this.y = gesture.y + dy;
                    this.clamp();
                }
            }
        },

        up(event) {
            this.pointers.delete(event.pointerId);
            const gesture = this.gesture;

            if (gesture?.type === 'pinch') {
                // One finger stays down: continue as pan from here.
                const [rest] = this.pointers.values();
                this.gesture = rest ? { type: 'pan', startX: rest.x, startY: rest.y, x: this.x, y: this.y, moved: true } : null;
                if (this.scale < 1.05) this.reset();
                return;
            }
            this.gesture = null;
            if (gesture?.type !== 'pan' || gesture.moved || event.type === 'pointercancel') return;

            // Tap: a second one in time zooms in/out, a single one closes the unzoomed image.
            const point = this.relative(event);
            if (this.lastTap && Date.now() - this.lastTap < DOUBLE_TAP) {
                clearTimeout(this.tapTimer);
                this.lastTap = null;
                if (this.scale > 1) this.reset();
                else this.zoomTo(2.5, point.x, point.y);
                return;
            }
            this.lastTap = Date.now();
            this.tapTimer = setTimeout(() => {
                this.lastTap = null;
                if (this.scale === 1) this.close();
            }, DOUBLE_TAP);
        },

        wheel(event) {
            const point = this.relative(event);
            this.zoomTo(this.scale * Math.exp(-event.deltaY / 300), point.x, point.y);
        },
    };
}

function distance([a, b]) {
    return Math.hypot(a.x - b.x, a.y - b.y) || 1;
}
