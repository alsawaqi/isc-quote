export function humanizeStatus(value: string | null | undefined): string {
    if (!value) {
        return '-';
    }

    return value
        .split('_')
        .filter(Boolean)
        .map((part) => {
            const acronym = part.toUpperCase();

            if (['PO', 'RFQ', 'ETA', 'VAT'].includes(acronym)) {
                return acronym;
            }

            return part.charAt(0).toUpperCase() + part.slice(1);
        })
        .join(' ');
}
