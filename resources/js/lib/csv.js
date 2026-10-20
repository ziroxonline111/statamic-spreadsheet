// Small RFC 4180 CSV reader and writer. Handles quotes, commas and line
// breaks inside cells, and detects ";" as the separator (Excel in Europe).

// A cell starting with one of these can run as a formula when the file is
// opened in Excel or Sheets, so it is written with a leading apostrophe.
const FORMULA = /^[=+\-@\t\r]/;

export function guard(text) {
    return typeof text === 'string' && FORMULA.test(text) && !/^-?\d+([.,]\d+)?$/.test(text) ? "'" + text : text;
}

export function unguard(text) {
    return typeof text === 'string' && text.startsWith("'") && FORMULA.test(text.slice(1)) ? text.slice(1) : text;
}

export function stringify(rows, separator = ',') {
    const escape = (cell) => {
        const text = cell === null || cell === undefined ? '' : String(guard(cell));
        return /["\n\r]/.test(text) || text.includes(separator) ? '"' + text.replace(/"/g, '""') + '"' : text;
    };

    // The BOM makes Excel read the file as UTF-8 (å, ä, ö and so on).
    return '﻿' + rows.map((row) => row.map(escape).join(separator)).join('\r\n') + '\r\n';
}

export function parse(text) {
    text = text.replace(/^﻿/, '');

    const firstLine = text.split(/\r?\n/, 1)[0] ?? '';
    const separator = (firstLine.match(/;/g) || []).length > (firstLine.match(/,/g) || []).length ? ';' : ',';

    const rows = [];
    let row = [];
    let cell = '';
    let quoted = false;

    for (let i = 0; i < text.length; i++) {
        const char = text[i];

        if (quoted) {
            if (char === '"' && text[i + 1] === '"') {
                cell += '"';
                i++;
            } else if (char === '"') {
                quoted = false;
            } else {
                cell += char;
            }
            continue;
        }

        if (char === '"') {
            quoted = true;
        } else if (char === separator) {
            row.push(cell);
            cell = '';
        } else if (char === '\n' || char === '\r') {
            if (char === '\r' && text[i + 1] === '\n') i++;
            row.push(cell);
            rows.push(row);
            row = [];
            cell = '';
        } else {
            cell += char;
        }
    }

    if (cell !== '' || row.length) {
        row.push(cell);
        rows.push(row);
    }

    return rows.filter((r) => r.some((c) => c !== ''));
}

export function download(filename, content) {
    const blob = new Blob([content], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}
