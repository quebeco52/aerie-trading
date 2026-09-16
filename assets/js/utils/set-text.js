/**
 * Rewrites a node's text in place. `textContent = …` discards the existing Text node and
 * creates a new one; on a page rewriting hundreds of live figures a second that was thousands
 * of DOM nodes waiting for garbage collection. Mutating the Text node's data changes nothing
 * but the characters, and a value that has not changed touches nothing at all.
 *
 * Returns whether the text on screen actually changed, which is the only honest trigger for a
 * tick flash: a move too small to survive rounding shows the reader the same digits, and
 * flashing them is noise.
 */
export function setText(node, text) {
    if (!node) return false;
    const first = node.firstChild;
    if (first !== null && first.nodeType === Node.TEXT_NODE && first === node.lastChild) {
        if (first.data === text) return false;
        first.data = text;
        return true;
    }
    const changed = node.textContent !== text;
    node.textContent = text;
    return changed;
}
