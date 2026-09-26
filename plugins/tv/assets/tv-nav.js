// Moving about with four arrows and an OK button (lib/tv-nav.ts).
//
// The grid is rows of tiles. Nothing wraps at the end of a row and moving
// between rows keeps your column, because with no pointer to look for,
// focus reappearing somewhere unexpected leaves somebody lost in a way a
// mouse user never is: they cannot see where it went, only that the thing
// they were on is no longer lit.
//
// Pure, so it can be tested without a television.

/** Where focus is: which row, and which tile along it. */
export function isValid(rows, at) {
  return (
    Array.isArray(rows) &&
    Number.isInteger(at?.row) &&
    Number.isInteger(at?.column) &&
    at.row >= 0 &&
    at.row < rows.length &&
    at.column >= 0 &&
    at.column < (rows[at.row] || 0)
  );
}

/** The first row with anything in it — there may not be one. */
export function firstFocusable(rows) {
  for (let row = 0; row < (rows || []).length; row += 1) {
    if (rows[row] > 0) return { row, column: 0 };
  }
  return null;
}

/**
 * Where the four arrows take you.
 *
 * `rows` is how many tiles each row holds. `memory` is the column somebody
 * was last on in a long row: moving down into a short row and back up should
 * return you to where you came from, not to the end of the short one.
 */
export function move(rows, at, direction, memory = null) {
  const lengths = Array.isArray(rows) ? rows : [];
  if (!isValid(lengths, at)) return firstFocusable(lengths);
  const wanted = memory === null ? at.column : Math.max(memory, at.column);

  if (direction === 'left') {
    // Stops at the end rather than wrapping.
    return { row: at.row, column: Math.max(0, at.column - 1), memory: Math.max(0, at.column - 1) };
  }
  if (direction === 'right') {
    const column = Math.min(lengths[at.row] - 1, at.column + 1);
    return { row: at.row, column, memory: column };
  }

  const step = direction === 'up' ? -1 : 1;
  for (let row = at.row + step; row >= 0 && row < lengths.length; row += step) {
    // An empty row is stepped over: focus must never land in it.
    if (lengths[row] > 0) {
      return { row, column: Math.min(wanted, lengths[row] - 1), memory: wanted };
    }
  }
  // Stops at the top and the bottom.
  return { row: at.row, column: at.column, memory: wanted };
}

/** What the remote sent, however this platform spells it. */
export function keyOf(event) {
  const key = event?.key || '';
  const code = event?.keyCode;
  if (key === 'ArrowLeft' || code === 37) return 'left';
  if (key === 'ArrowUp' || code === 38) return 'up';
  if (key === 'ArrowRight' || code === 39) return 'right';
  if (key === 'ArrowDown' || code === 40) return 'down';
  // OK is Enter on a keyboard, Select on a television, and 13 on a set whose
  // browser predates key.
  if (key === 'Enter' || key === 'Select' || key === 'OK' || code === 13) return 'ok';
  // Back is a real button on every remote, and every platform names it
  // differently.
  if (key === 'Backspace' || key === 'Escape' || key === 'GoBack' || key === 'BrowserBack' || key === 'XF86Back' || code === 8 || code === 27 || code === 461 || code === 10009) return 'back';
  return null;
}
