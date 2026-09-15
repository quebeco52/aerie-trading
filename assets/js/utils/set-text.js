/**
 * Rewrites a node's text in place. `textContent = …` discards the existing Text node and
 * creates a new one; on a page rewriting hundreds of live figures a second that was thousands
 * of DOM nodes waiting for garbage collection. Mutating the Text node's data changes nothing
 * but the characters, and a value that has not changed touches nothing at all.
 */
export function setText(node, text) {
    if (!node) return;
    const first = node.firstChild;
    if (first !== null && first.nodeType === Node.TEXT_NODE && first === node.lastChild) {
        if (first.data !== text) first.data = text;
        return;
    }
    node.textContent = text;
}
