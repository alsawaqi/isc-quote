const allowedRichTextElements = new Set([
    'P',
    'BR',
    'STRONG',
    'B',
    'EM',
    'I',
    'U',
    'S',
    'STRIKE',
    'MARK',
    'UL',
    'OL',
    'LI',
    'BLOCKQUOTE',
    'H2',
    'H3',
]);

const elementsRemovedWithContent = new Set([
    'SCRIPT',
    'STYLE',
    'IFRAME',
    'OBJECT',
    'EMBED',
    'SVG',
    'MATH',
    'TEMPLATE',
    'NOSCRIPT',
    'META',
    'LINK',
    'BASE',
    'FORM',
    'INPUT',
    'BUTTON',
    'TEXTAREA',
    'SELECT',
    'OPTION',
    'VIDEO',
    'AUDIO',
    'SOURCE',
]);

export function sanitizeRichTextHtml(value: string): string {
    if (!value.trim()) {
        return '';
    }

    const document = new DOMParser().parseFromString(value, 'text/html');
    sanitizeChildren(document.body);

    return document.body.innerHTML;
}

function sanitizeChildren(parent: Node): void {
    for (const child of Array.from(parent.childNodes)) {
        if (child.nodeType === Node.COMMENT_NODE || child.nodeType === Node.PROCESSING_INSTRUCTION_NODE) {
            child.remove();
            continue;
        }

        if (!(child instanceof Element)) {
            continue;
        }

        if (elementsRemovedWithContent.has(child.tagName)) {
            child.remove();
            continue;
        }

        sanitizeChildren(child);

        if (!allowedRichTextElements.has(child.tagName)) {
            child.replaceWith(...Array.from(child.childNodes));
            continue;
        }

        for (const attribute of Array.from(child.attributes)) {
            child.removeAttribute(attribute.name);
        }
    }
}
