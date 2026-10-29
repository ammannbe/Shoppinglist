// Visual drag of a row between its sibling rows ([data-sort-id]). The DOM is only shifted
// with transforms while dragging; the caller applies the new order to the data at the end
// and Alpine renders it.

// Distance to the top/bottom edge of the viewport that scrolls while dragging.
const EDGE = 64;

/**
 * @param {HTMLElement} row
 * @param {number} clientY pointer position at the start
 * @returns {{ move: (clientY: number) => void, end: () => { from: number, to: number } }}
 */
export function startDrag(row, clientY) {
    const rows = [...row.parentElement.querySelectorAll(':scope > [data-sort-id]')];
    const from = rows.indexOf(row);
    const scrollStart = window.scrollY;
    const tops = rows.map((r) => r.getBoundingClientRect().top + scrollStart);
    const heights = rows.map((r) => r.offsetHeight);
    const startY = clientY;
    let pointerY = clientY;
    let to = from;
    let frame = null;

    row.style.position = 'relative';
    row.style.zIndex = '10';
    row.classList.add('shadow-lg');
    rows.forEach((r) => { if (r !== row) r.style.transition = 'transform 150ms'; });

    const update = () => {
        const dy = pointerY - startY + window.scrollY - scrollStart;
        row.style.transform = `translateY(${dy}px)`;

        const center = tops[from] + heights[from] / 2 + dy;
        to = rows.filter((r, i) => i !== from && tops[i] + heights[i] / 2 < center).length;
        rows.forEach((r, i) => {
            if (r === row) return;
            const shift = from < i && i <= to ? -heights[from] : to <= i && i < from ? heights[from] : 0;
            r.style.transform = shift ? `translateY(${shift}px)` : '';
        });

        // Scroll while the row is held at the top or bottom edge.
        const edge = pointerY < EDGE ? -1 : pointerY > window.innerHeight - EDGE ? 1 : 0;
        if (edge) {
            window.scrollBy(0, edge * 8);
            frame = requestAnimationFrame(update);
        } else {
            frame = null;
        }
    };

    return {
        move(y) {
            pointerY = y;
            if (frame === null) frame = requestAnimationFrame(update);
        },

        end() {
            cancelAnimationFrame(frame);
            rows.forEach((r) => {
                r.style.transform = '';
                r.style.transition = '';
            });
            row.style.position = '';
            row.style.zIndex = '';
            row.classList.remove('shadow-lg');

            return { from, to };
        },
    };
}
