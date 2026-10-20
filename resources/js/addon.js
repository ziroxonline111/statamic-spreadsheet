import { defineAsyncComponent } from 'vue';
import SpreadsheetIndex from './pages/SpreadsheetIndex.vue';

// The grid is only downloaded when a spreadsheet is opened, so the rest of
// the Control Panel does not pay for it.
const SpreadsheetEditor = defineAsyncComponent(() => import('./pages/SpreadsheetEditor.vue'));

Statamic.booting(() => {
    Statamic.$inertia.register('statamic-spreadsheet::SpreadsheetIndex', SpreadsheetIndex);
    Statamic.$inertia.register('statamic-spreadsheet::SpreadsheetEditor', SpreadsheetEditor);
});
