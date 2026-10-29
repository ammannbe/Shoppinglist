import Alpine from 'alpinejs';
import { startDrag } from './drag.js';

// Row gestures (<x-swipe-row>):
// - touch, setting "swipe": swipe left to reveal the actions (like iOS Mail / the former Ionic app)
// - touch, setting "longpress": hold and release opens the actions menu (event "row-menu")
// - touch: hold and move drags the row to a new position (rows with data-sort-id, event "row-move")
// - mouse: drag the row to a new position, hover "…" menu and right-click menu
// Keyboard and screen reader users use the "…" menu.

// Android gesture navigation and iOS Safari "back" start at the screen edges.
const EDGE_GUARD = 24;
// Movement before deciding between scrolling (vertical) and swiping (horizontal).
const AXIS_LOCK = 10;
// Hold time of a long press.
const LONG_PRESS = 450;
// Movement of a held row (or mouse) that starts dragging.
const DRAG_START = 6;

export function swipeRow() {
    return {
        offset: 0,
        open: false,
        dragging: false,
        lifted: false,
        start: null,
        axis: null,
        pressTimer: null,
        drag: null,
        touchListeners: null,
        suppressClick: false,

        init() {
            // Only one open row at a time.
            window.addEventListener('swipe-row-open', (event) => {
                if (event.detail !== this.$root) this.close();
            });
        },

        revealWidth: 0,

        get longPress() {
            return Alpine.store('prefs').row_actions === 'longpress';
        },

        get sortable() {
            return this.$root.hasAttribute('data-sort-id');
        },

        measureActions() {
            const buttons = [...(this.$refs.actions?.children ?? [])].filter((el) => el.tagName === 'BUTTON');
            this.revealWidth = buttons.reduce((width, button) => width + button.offsetWidth, 0);
        },

        get contentStyle() {
            return `transform: translate3d(${this.offset}px, 0, 0)`;
        },

        get actionsStyle() {
            return `width: ${Math.max(-this.offset, 0)}px`;
        },

        down(event) {
            // Touch drags often end without a click, never swallow the next real tap.
            this.suppressClick = false;
            if (!event.isPrimary) return;

            if (event.pointerType === 'mouse') {
                if (event.button !== 0 || !this.sortable || event.target.closest('[aria-haspopup]')) return;
                this.start = { x: event.clientX, y: event.clientY, pointerId: event.pointerId, target: event.currentTarget, mouse: true };
                return;
            }

            if (event.clientX < EDGE_GUARD || event.clientX > window.innerWidth - EDGE_GUARD) return;
            this.start = { x: event.clientX, y: event.clientY, offset: this.offset, pointerId: event.pointerId, target: event.currentTarget };
            this.axis = null;

            if (!this.open && (this.sortable || this.longPress)) {
                clearTimeout(this.pressTimer);
                this.pressTimer = setTimeout(() => this.lift(), LONG_PRESS);
            }
        },

        /**
         * Long press: the row is held. Moving now drags it, releasing opens the menu
         * (setting "longpress"). Scrolling is blocked until the finger lifts.
         */
        lift() {
            this.pressTimer = null;
            this.lifted = true;
            // The finger lifts later: that click must not toggle or open the row.
            this.suppressClick = true;
            navigator.vibrate?.(10);

            const move = (event) => {
                event.preventDefault();
                this.follow(event.touches[0].clientX, event.touches[0].clientY);
            };
            const end = () => this.release();
            document.addEventListener('touchmove', move, { passive: false });
            document.addEventListener('touchend', end);
            document.addEventListener('touchcancel', end);
            this.touchListeners = { move, end };
        },

        /**
         * Pointer position of a held (lifted) row or a mouse press.
         */
        follow(x, y) {
            if (this.drag) {
                this.drag.move(y);
                return;
            }
            if (Math.abs(x - this.start.x) < DRAG_START && Math.abs(y - this.start.y) < DRAG_START) return;
            if (!this.sortable) return;
            this.suppressClick = true;
            this.drag = startDrag(this.$root, this.start.y);
            this.drag.move(y);
        },

        move(event) {
            if (!this.start || event.pointerId !== this.start.pointerId) return;
            if (this.lifted || this.start.mouse) {
                if (this.start.mouse && !this.drag) this.start.target.setPointerCapture?.(event.pointerId);
                this.follow(event.clientX, event.clientY);
                return;
            }

            const dx = event.clientX - this.start.x;
            const dy = event.clientY - this.start.y;

            if (!this.axis) {
                if (Math.abs(dx) < AXIS_LOCK && Math.abs(dy) < AXIS_LOCK) return;
                // Moved before the long press: scrolling or swiping.
                clearTimeout(this.pressTimer);
                if (this.longPress) {
                    this.start = null;
                    return;
                }
                this.axis = Math.abs(dx) > Math.abs(dy) ? 'x' : 'y';
                if (this.axis === 'y') {
                    this.start = null;
                    return;
                }
                if (!this.open) this.measureActions();
                this.start.target.setPointerCapture?.(event.pointerId);
                this.dragging = true;
                this.suppressClick = true;
                window.dispatchEvent(new CustomEvent('swipe-row-open', { detail: this.$root }));
            }

            // Only to the left and not further than the actions (no full swipe action).
            const x = this.start.offset + dx;
            this.offset = Math.max(Math.min(x, 0), -this.revealWidth - 24);
        },

        up() {
            clearTimeout(this.pressTimer);
            // A held row ends with the touch events (pointer events may be cancelled).
            if (this.lifted) return;
            if (this.start?.mouse) {
                this.start = null;
                this.drop();
                return;
            }
            if (!this.start) return;
            this.start = null;
            if (!this.dragging) return;
            this.dragging = false;

            this.open = -this.offset > this.revealWidth / 2;
            this.offset = this.open ? -this.revealWidth : 0;
        },

        release() {
            const { move, end } = this.touchListeners ?? {};
            document.removeEventListener('touchmove', move);
            document.removeEventListener('touchend', end);
            document.removeEventListener('touchcancel', end);
            this.touchListeners = null;
            this.lifted = false;
            this.start = null;

            if (this.drag) this.drop();
            else if (this.longPress) this.$dispatch('row-menu');
        },

        drop() {
            if (!this.drag) return;
            const { from, to } = this.drag.end();
            this.drag = null;
            if (from !== to) this.$dispatch('row-move', { from, to });
        },

        close() {
            this.open = false;
            this.offset = 0;
        },

        /**
         * Right click (mouse) opens the actions menu; a long press must not open the
         * browser's own menu (Android fires contextmenu on long press).
         */
        contextMenu(event) {
            event.preventDefault();
            if (event.pointerType === 'touch' || event.pointerType === 'pen') return;
            this.$dispatch('row-menu');
        },

        /**
         * Capture phase click on the row content: a drag or a tap on an open row
         * must not trigger the row's own click (open/edit).
         */
        guardClick(event) {
            if (this.suppressClick || this.open) {
                event.preventDefault();
                event.stopPropagation();
                if (!this.suppressClick) this.close();
            }
            this.suppressClick = false;
        },

        /**
         * Run a revealed action and close the row.
         */
        act(callback) {
            this.close();
            callback();
        },
    };
}
