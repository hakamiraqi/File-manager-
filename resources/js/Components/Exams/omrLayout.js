// The bubble sheet's geometry, in millimetres on an A4 page; the printed sheet and the scan
// reader both use it, so a bubble is read exactly where it was printed.
export const PAGE = { w: 210, h: 297 };
export const MARK = 10; // corner squares, mm
// Centres of the four corner squares: top-left, top-right, bottom-left, bottom-right.
export const MARKS = [[15, 15], [195, 15], [15, 282], [195, 282]];
export const TOP = 62; // first row of bubbles
export const ROW = 7;
export const ROWS = 30; // per column
export const COLS = [16, 79, 142]; // left edge of each column of questions
export const BUBBLE_R = 2.4;
export const BUBBLE_GAP = 8;
export const LETTERS = ['A', 'B', 'C', 'D', 'E'];

/** Where question k (0-based) sits: its number's position and each option's bubble centre. */
export function slot(k, options) {
    const col = Math.floor(k / ROWS);
    const row = k % ROWS;
    const y = TOP + row * ROW;
    const x0 = COLS[col] ?? COLS[0];
    return { col, number: [x0, y], bubbles: Array.from({ length: options }, (_, i) => [x0 + 10 + i * BUBBLE_GAP, y]) };
}

export const capacity = COLS.length * ROWS;
