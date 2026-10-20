// Converting between what a cell shows, what a person types or pastes, and
// what is sent to the server (the publish form's value shape).

export function isEmpty(value) {
    return value === null || value === undefined || value === '' || (Array.isArray(value) && value.length === 0);
}

export function same(a, b) {
    if (isEmpty(a) && isEmpty(b)) return true;
    return JSON.stringify(a) === JSON.stringify(b);
}

const TRUE_WORDS = ['1', 'true', 'yes', 'y', 'on', 'ja', 'x', '✓'];
const FALSE_WORDS = ['0', 'false', 'no', 'n', 'off', 'nej', '', '-', '–'];

function splitList(text) {
    return String(text)
        .split(/\s*[,;|\n]\s*/)
        .map((part) => part.trim())
        .filter((part) => part !== '');
}

/**
 * Turns typed or pasted input into the value for a column.
 * Returns { value, error }.
 */
export function parse(column, input, labels = {}) {
    if (input === undefined) input = null;

    switch (column.kind) {
        case 'number': {
            if (isEmpty(input)) return { value: null };
            const text = String(input).trim().replace(/\s/g, '').replace(',', '.');
            const number = Number(text);
            if (Number.isNaN(number)) return { value: input, error: __('Not a number') };
            if (column.integer && !Number.isInteger(number)) return { value: input, error: __('Must be a whole number') };
            return { value: number };
        }

        case 'toggle': {
            if (typeof input === 'boolean') return { value: input };
            const word = String(input ?? '').trim().toLowerCase();
            if (TRUE_WORDS.includes(word)) return { value: true };
            if (FALSE_WORDS.includes(word)) return { value: false };
            return { value: input, error: __('Use yes or no') };
        }

        case 'options': {
            const options = column.options || [];
            const find = (part) => {
                const lower = String(part).toLowerCase();
                return options.find((o) => o.value.toLowerCase() === lower || o.label.toLowerCase() === lower);
            };

            if (column.multiple) {
                const parts = Array.isArray(input) ? input : splitList(input ?? '');
                const values = [];
                for (const part of parts) {
                    const match = find(part);
                    if (!match) return { value: input, error: __('":value" is not an option', { value: part }) };
                    if (!values.includes(match.value)) values.push(match.value);
                }
                return { value: values };
            }

            if (isEmpty(input)) return { value: null };
            const match = find(Array.isArray(input) ? input[0] : input);
            return match ? { value: match.value } : { value: input, error: __('":value" is not an option', { value: input }) };
        }

        case 'relationship': {
            let ids = Array.isArray(input) ? input.map(String) : splitList(input ?? '');

            // Single-taxonomy term fields accept plain slugs.
            const taxonomies = [].concat(column.taxonomies || []);
            if (column.relationship === 'terms' && taxonomies.length === 1) {
                ids = ids.map((id) => (id.includes('::') ? id : `${taxonomies[0]}::${id}`));
            }

            ids = [...new Set(ids)];

            if (column.max && ids.length > column.max) {
                return { value: ids, error: __('At most :max', { max: column.max }) };
            }

            const known = labels[column.relationship] || {};
            const unknown = ids.filter((id) => known[id]?.missing);
            if (unknown.length) return { value: ids, error: __('Not found: :items', { items: unknown.join(', ') }) };

            return { value: ids };
        }

        case 'date': {
            if (isEmpty(input)) return { value: null };
            const text = String(input).trim();
            const pattern = column.time ? /^\d{4}-\d{2}-\d{2}( \d{1,2}:\d{2})?$/ : /^\d{4}-\d{2}-\d{2}$/;
            if (!pattern.test(text) || Number.isNaN(Date.parse(text.replace(' ', 'T')))) {
                return { value: text, error: column.time ? __('Use YYYY-MM-DD HH:MM') : __('Use YYYY-MM-DD') };
            }
            return { value: text };
        }

        default:
            return { value: input === null ? null : String(input) };
    }
}

/** What the cell shows. */
export function display(column, value, labels = {}) {
    if (isEmpty(value)) return '';

    switch (column.kind) {
        case 'toggle':
            return value ? __('Yes') : __('No');

        case 'options': {
            const label = (v) => column.options?.find((o) => o.value === String(v))?.label ?? v;
            return Array.isArray(value) ? value.map(label).join(', ') : label(value);
        }

        case 'relationship': {
            const known = labels[column.relationship] || {};
            return [].concat(value).map((id) => known[id]?.title ?? id).join(', ');
        }

        default:
            return Array.isArray(value) ? value.join(', ') : String(value);
    }
}

/** Plain text for copying and CSV: ids rather than titles, so it pastes back. */
export function toText(column, value) {
    if (isEmpty(value)) return '';
    if (column.kind === 'toggle') return value ? 'yes' : 'no';
    if (Array.isArray(value)) return value.join(', ');
    return String(value);
}
