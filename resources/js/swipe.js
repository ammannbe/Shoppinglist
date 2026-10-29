// Swipe-to-reveal rows (like iOS Mail / the former Ionic app): swipe left to show the
// actions, swipe all the way to run the last (destructive) action. Touch and pen only,
// mouse users get the hover menu. Keyboard and screen reader users use the menu too.

// Android gesture navigation and iOS Safari "back" start at the screen edges.
const EDGE_GUARD = 24;
// Movement before deciding between scrolling (vertical) and swiping (horizontal).
const AXIS_LOCK = 10;
// Share of the row width that triggers the full swipe action.
const FULL_SWIPE = 0.6;

export function swipeRow() {
    return {
        offset: 0,
        open: false,
        dragging: false,
        armed: false,
        start: null,
        axis: null,
        suppressClick: false,

        init() {
            // Only one open row at a time.
            window.addEventListener('swipe-row-open', (event) => {
                if (event.detail !== this.$root) this.close();
            });
        },

        revealWidth: 0,

        /**
         * Natural width of all actions; measured before dragging because the
         * destructive action grows with the drag.
         */
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
            if (event.pointerType === 'mouse' || !event.isPrimary) return;
            if (event.clientX < EDGE_GUARD || event.clientX > window.innerWidth - EDGE_GUARD) return;

            this.start = { x: event.clientX, y: event.clientY, offset: this.offset, pointerId: event.pointerId, target: event.currentTarget };
            this.axis = null;
        },

        move(event) {
            if (!this.start || event.pointerId !== this.start.pointerId) return;
            const dx = event.clientX - this.start.x;
            const dy = event.clientY - this.start.y;

            if (!this.axis) {
                if (Math.abs(dx) < AXIS_LOCK && Math.abs(dy) < AXIS_LOCK) return;
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

            const width = this.$root.offsetWidth;
            // Only to the left; slight resistance when pulled right while open.
            const x = this.start.offset + dx;
            this.offset = Math.max(Math.min(x, 0), -width);

            // Beyond the revealed actions, but always reachable.
            const threshold = Math.min(Math.max(width * FULL_SWIPE, this.revealWidth + 48), width * 0.9);
            const armed = -this.offset > threshold;
            if (armed !== this.armed) {
                this.armed = armed;
                // Haptic tick where supported (Android); iOS Safari has no vibration API.
                navigator.vibrate?.(10);
            }
        },

        up() {
            if (!this.start) return;
            this.start = null;
            if (!this.dragging) return;
            this.dragging = false;

            if (this.armed) {
                this.armed = false;
                this.offset = -this.$root.offsetWidth;
                this.$dispatch('swipe-full');
                return;
            }

            this.open = -this.offset > this.revealWidth / 2;
            this.offset = this.open ? -this.revealWidth : 0;
        },

        close() {
            this.open = false;
            this.armed = false;
            this.offset = 0;
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
