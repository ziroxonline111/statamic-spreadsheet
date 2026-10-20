<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, shallowRef, toRaw, watch } from 'vue';
import { Head, Link } from '@statamic/cms/inertia';
import {
    Badge,
    Button,
    Checkbox,
    CommandPaletteItem,
    ConfirmationModal,
    Dropdown,
    DropdownMenu,
    Header,
    Input,
    Select,
} from '@statamic/cms/ui';
import { VGrid } from '@revolist/vue3-datagrid';
import CellPicker from '../components/CellPicker.vue';
import FindReplace from '../components/FindReplace.vue';
import { display, parse, same, toText } from '../lib/values.js';
import * as csv from '../lib/csv.js';
import { http } from '../http.js';

const props = defineProps({
    collection: Object,
    columns: Array,
    sites: { type: Array, default: () => [] },
    site: String,
    ids: { type: Array, default: () => [] },
    limit: Number,
    rowsUrl: String,
    saveUrl: String,
    lookupUrl: String,
    indexUrl: String,
});

const DIRTY = 'karelia-spreadsheet';
const columnsByHandle = Object.fromEntries(props.columns.map((c) => [c.handle, c]));

// ----- State -------------------------------------------------------------

const grid = ref(null);
const source = shallowRef([]);
const loading = ref(true);
const saving = ref(false);
const total = ref(0);
const truncated = ref(false);
const search = ref('');
const site = ref(props.site);
const selection = ref(props.ids);
const labels = reactive({ entries: {}, terms: {}, users: {}, assets: {} });

const originals = new Map(); // id -> { prop: value }
const changes = reactive({}); // id -> { prop: value }
const clientErrors = reactive({}); // id -> { prop: message }
const serverErrors = reactive({}); // id -> { prop: [messages] }
const notes = reactive({}); // id -> note

const history = [];
const future = [];
const historySize = ref(0);
const futureSize = ref(0);

const picker = shallowRef(null); // { row, column }
const findOpen = ref(false);
const confirmDiscard = ref(false);
const pendingLoad = ref(null);
const fileInput = ref(null);

// ----- Columns -----------------------------------------------------------

const storageKey = `karelia-spreadsheet.columns.${props.collection.handle}`;

function readPreference() {
    try {
        return JSON.parse(localStorage.getItem(storageKey));
    } catch {
        return null;
    }
}

const defaultVisible = props.columns.filter((c) => c.builtin || c.kind !== 'readonly').slice(0, 12).map((c) => c.handle);
const visible = ref(readPreference() ?? defaultVisible);

watch(visible, (value) => {
    try {
        localStorage.setItem(storageKey, JSON.stringify(value));
    } catch {
        // Private mode and the like. Not important.
    }
});

function toggleColumn(handle, on) {
    visible.value = on
        ? props.columns.map((c) => c.handle).filter((h) => h === handle || visible.value.includes(h))
        : visible.value.filter((h) => h !== handle);
}

function isLocked(model, prop) {
    const column = columnsByHandle[prop];
    if (!column || column.readonly || column.kind === 'readonly') return true;
    if (!model._can_edit) return true;
    if (prop === 'published' && !model._can_publish) return true;
    if (column.blueprints && !column.blueprints.includes(model._blueprint)) return true;
    return (model._locked || []).includes(prop);
}

function errorFor(id, prop) {
    return clientErrors[id]?.[prop] ?? serverErrors[id]?.[prop]?.join(' ') ?? null;
}

// On a phone, a pinned title column would leave no room to scroll.
const narrow = window.matchMedia('(max-width: 640px)').matches;

const gridColumns = computed(() => {
    const open = {
        prop: '_open',
        name: '',
        size: 46,
        pin: 'colPinStart',
        readonly: true,
        sortable: false,
        cellTemplate: (h, { model }) =>
            h('a', {
                class: `ks-open ks-status-${model._status}`,
                href: model._edit_url,
                title: __('Open :title', { title: model.title ?? '' }),
                target: '_blank',
                rel: 'noopener',
            }, h('span', { class: 'ks-dot' })),
        cellProperties: ({ model }) => ({
            class: { 'ks-row-error': !!serverErrors[model._id]?._row },
            title: serverErrors[model._id]?._row?.join(' ') ?? notes[model._id] ?? null,
        }),
    };

    const cols = props.columns
        .filter((column) => visible.value.includes(column.handle))
        .map((column) => ({
            prop: column.handle,
            name: column.label + (column.required ? ' *' : ''),
            size: column.pinned ? (narrow ? 170 : 260) : column.kind === 'longtext' ? 280 : column.kind === 'toggle' ? 110 : column.kind === 'number' ? 120 : 190,
            pin: column.pinned && !narrow ? 'colPinStart' : undefined,
            sortable: true,
            readonly: ({ model, prop }) => isLocked(model, prop),
            columnProperties: () => ({
                class: { 'ks-head-readonly': column.readonly || column.kind === 'readonly' },
                title: column.instructions || (column.kind === 'readonly' ? __('Shown for reference. Edit it on the entry page.') : null),
            }),
            cellTemplate: (h, { model, prop }) => {
                const value = model[prop];
                const text = display(column, value, labels);

                if (column.kind === 'toggle') {
                    return h('span', { class: value ? 'ks-toggle ks-on' : 'ks-toggle' }, text);
                }

                return h('span', { class: `ks-text ks-${column.kind}` }, text);
            },
            cellProperties: ({ model, prop }) => {
                const id = model._id;
                const error = errorFor(id, prop);

                return {
                    class: {
                        'ks-changed': changes[id] && prop in changes[id],
                        'ks-error': !!error,
                        'ks-locked': isLocked(model, prop),
                    },
                    title: error,
                };
            },
        }));

    return [open, ...cols];
});

// ----- Loading -----------------------------------------------------------

const changeCount = computed(() => Object.values(changes).reduce((sum, props) => sum + Object.keys(props).length, 0));
const changedEntries = computed(() => Object.keys(changes).filter((id) => Object.keys(changes[id]).length).length);
const errorCount = computed(
    () =>
        Object.values(clientErrors).reduce((s, e) => s + Object.keys(e).length, 0) +
        Object.values(serverErrors).reduce((s, e) => s + Object.keys(e).length, 0)
);

function snapshot(row) {
    const copy = {};
    for (const column of props.columns) copy[column.handle] = structuredClone(row[column.handle] ?? null);
    return copy;
}

function load() {
    loading.value = true;

    http()
        .get(props.rowsUrl, {
            params: { site: site.value, search: search.value || undefined, ids: selection.value.length ? selection.value : undefined },
        })
        .then(({ data }) => {
            originals.clear();
            for (const key of [changes, clientErrors, serverErrors, notes]) Object.keys(key).forEach((k) => delete key[k]);
            history.length = 0;
            future.length = 0;
            historySize.value = 0;
            futureSize.value = 0;

            for (const row of data.rows) originals.set(row._id, snapshot(row));
            mergeLabels(data.labels);
            total.value = data.total;
            truncated.value = data.truncated;
            source.value = data.rows;
        })
        .catch((e) => Statamic.$toast.error(e.response?.data?.message || __('Something went wrong')))
        .finally(() => (loading.value = false));
}

function requestLoad(change) {
    if (changeCount.value > 0) {
        pendingLoad.value = change;
        confirmDiscard.value = true;
        return;
    }
    change();
    load();
}

function mergeLabels(incoming) {
    for (const [kind, items] of Object.entries(incoming || {})) {
        labels[kind] = { ...(labels[kind] || {}), ...items };
    }
}

function refreshGrid() {
    source.value = [...source.value];
}

// ----- Editing -----------------------------------------------------------

function rowById(id) {
    return source.value.find((row) => row._id === id);
}

/** Sets one cell. Returns the undo record, or null if nothing changed. */
function setCell(row, prop, input, { record = true, recheck = false } = {}) {
    row = toRaw(row);
    input = Array.isArray(input) ? [...toRaw(input)] : toRaw(input);
    const column = columnsByHandle[prop];
    if (!column || isLocked(row, prop)) return null;

    const before = structuredClone(row[prop] ?? null);
    const { value, error } = parse(column, input, labels);

    if (!recheck && same(before, value) && !error && !clientErrors[row._id]?.[prop]) return null;

    row[prop] = value;

    const id = row._id;
    clientErrors[id] ??= {};
    if (error) clientErrors[id][prop] = error;
    else delete clientErrors[id][prop];

    if (serverErrors[id]) delete serverErrors[id][prop];

    changes[id] ??= {};
    if (same(value, originals.get(id)?.[prop])) delete changes[id][prop];
    else changes[id][prop] = value;

    return record ? { id, prop, before, after: structuredClone(value) } : null;
}

function commit(batch) {
    batch = batch.filter(Boolean);
    if (!batch.length) return;
    history.push(batch);
    if (history.length > 200) history.shift();
    future.length = 0;
    historySize.value = history.length;
    futureSize.value = 0;
    refreshGrid();
    resolveUnknown(batch);
}

/**
 * Pasted or imported relationship ids the sheet has not seen yet are looked
 * up, so missing items are marked instead of being saved.
 */
function resolveUnknown(batch) {
    const wanted = {}; // field handle -> Set of ids

    for (const step of batch) {
        const column = columnsByHandle[step.prop];
        if (column?.kind !== 'relationship') continue;
        const known = labels[column.relationship] || {};
        for (const id of [].concat(step.after || [])) {
            if (!known[id]) (wanted[step.prop] ??= new Set()).add(id);
        }
    }

    for (const [field, ids] of Object.entries(wanted)) {
        const column = columnsByHandle[field];

        http()
            .get(props.lookupUrl, { params: { field, ids: [...ids] } })
            .then(({ data }) => {
                mergeLabels({ [column.relationship]: data.labels });
                for (const step of batch.filter((s) => s.prop === field)) {
                    const row = rowById(step.id);
                    if (row) setCell(row, field, row[field], { record: false, recheck: true });
                }
                refreshGrid();
            });
    }
}

function undo() {
    const batch = history.pop();
    if (!batch) return;
    for (const step of [...batch].reverse()) {
        const row = rowById(step.id);
        if (row) setCell(row, step.prop, step.before, { record: false });
    }
    future.push(batch);
    historySize.value = history.length;
    futureSize.value = future.length;
    refreshGrid();
}

function redo() {
    const batch = future.pop();
    if (!batch) return;
    for (const step of batch) {
        const row = rowById(step.id);
        if (row) setCell(row, step.prop, step.after, { record: false });
    }
    history.push(batch);
    historySize.value = history.length;
    futureSize.value = future.length;
    refreshGrid();
}

// One cell typed into.
function onBeforeEdit(event) {
    event.preventDefault();
    const { model, prop, val } = event.detail;
    commit([setCell(model, prop, val)]);
}

// Paste, fill handle and clearing a selection.
function onBeforeRangeEdit(event) {
    event.preventDefault();
    const { data, models } = event.detail;
    const batch = [];

    for (const [index, values] of Object.entries(data)) {
        const model = models[index];
        if (!model) continue;
        for (const [prop, value] of Object.entries(values)) {
            if (prop.startsWith('_')) continue;
            batch.push(setCell(model, prop, value));
        }
    }

    commit(batch);
}

// Toggles flip, options and relationships open a picker.
function onBeforeEditStart(event) {
    const { model, prop } = event.detail;
    const column = columnsByHandle[prop];
    if (!column) return;

    if (column.kind === 'toggle') {
        event.preventDefault();
        commit([setCell(model, prop, !model[prop])]);
    } else if (column.kind === 'options' || column.kind === 'relationship') {
        event.preventDefault();
        picker.value = { row: model, column };
    }
}

function picked(value, newLabels) {
    mergeLabels(newLabels);
    const { row, column } = picker.value;
    picker.value = null;
    commit([setCell(row, column.handle, value)]);
}

// Copy ids rather than titles, so copied cells paste back the same.
function onBeforeCopyApply(event) {
    const detail = event.detail;
    if (!detail?.data) return;
    // RevoGrid copies model values already; arrays are joined below.
    detail.data = detail.data.map((row) => row.map((cell) => (Array.isArray(cell) ? cell.join(', ') : typeof cell === 'boolean' ? (cell ? 'yes' : 'no') : cell)));
}

function applyReplacements(replacements) {
    commit(replacements.map(({ row, prop, value }) => setCell(row, prop, value)));
    findOpen.value = false;
    Statamic.$toast.success(__n('Replaced in :count cell|Replaced in :count cells', replacements.length, { count: replacements.length }));
}

function discard() {
    load();
}

// ----- Saving ------------------------------------------------------------

function save() {
    const payload = [];
    let skipped = 0;

    for (const [id, values] of Object.entries(changes)) {
        const clean = {};
        for (const [prop, value] of Object.entries(values)) {
            if (clientErrors[id]?.[prop]) {
                skipped++;
                continue;
            }
            clean[prop] = value;
        }
        if (Object.keys(clean).length) payload.push({ id, values: clean });
    }

    if (!payload.length) {
        if (skipped) Statamic.$toast.error(__('Fix the cells marked in red first.'));
        return;
    }

    saving.value = true;

    http()
        .post(props.saveUrl, { changes: payload })
        .then(({ data }) => {
            mergeLabels(data.labels);

            for (const [id, result] of Object.entries(data.results)) {
                const row = rowById(id);
                delete notes[id];

                if (result.ok) {
                    delete serverErrors[id];
                    for (const prop of Object.keys(payload.find((p) => p.id === id).values)) delete changes[id]?.[prop];
                    if (row && result.row) {
                        Object.assign(row, result.row);
                        originals.set(id, snapshot(row));
                        // Re-apply anything changed while saving or skipped for errors.
                        for (const [prop, value] of Object.entries(changes[id] || {})) row[prop] = value;
                    }
                    if (result.note) notes[id] = result.note;
                } else {
                    serverErrors[id] = result.errors;
                }
            }

            const failed = Object.values(data.results).some((r) => !r.ok);
            failed ? Statamic.$toast.error(data.message) : Statamic.$toast.success(data.message);
            refreshGrid();
        })
        .catch((e) => Statamic.$toast.error(e.response?.data?.message || __('Something went wrong')))
        .finally(() => (saving.value = false));
}

// ----- CSV ---------------------------------------------------------------

function exportCsv() {
    const columns = props.columns.filter((c) => visible.value.includes(c.handle));
    const rows = [['id', ...columns.map((c) => c.handle)]];

    for (const row of source.value) {
        rows.push([row._id, ...columns.map((c) => toText(c, row[c.handle]))]);
    }

    const date = new Date().toISOString().slice(0, 10);
    csv.download(`${props.collection.handle}${site.value && props.sites.length ? '-' + site.value : ''}-${date}.csv`, csv.stringify(rows));
}

function importCsv(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;

    file.text().then((text) => {
        const rows = csv.parse(text);
        if (rows.length < 2) return Statamic.$toast.error(__('The file has no rows.'));

        const header = rows[0].map((h) => h.trim());
        const find = (name) =>
            props.columns.find((c) => c.handle.toLowerCase() === name.toLowerCase() || c.label.toLowerCase() === name.toLowerCase());

        const idIndex = header.findIndex((h) => ['id', '_id'].includes(h.toLowerCase()));
        const slugIndex = header.findIndex((h) => h.toLowerCase() === 'slug');

        if (idIndex === -1 && slugIndex === -1) {
            return Statamic.$toast.error(__('The file needs an "id" or "slug" column to match entries.'));
        }

        const mapped = header.map((h, i) => (i === idIndex ? null : find(h)));
        const batch = [];
        let unmatched = 0;

        for (const cells of rows.slice(1)) {
            const row =
                idIndex !== -1
                    ? rowById(cells[idIndex])
                    : source.value.find((r) => r.slug === cells[slugIndex]);

            if (!row) {
                unmatched++;
                continue;
            }

            mapped.forEach((column, i) => {
                if (!column || column.kind === 'readonly' || (idIndex === -1 && i === slugIndex)) return;
                batch.push(setCell(row, column.handle, csv.unguard(cells[i] ?? '')));
            });
        }

        commit(batch);

        const changed = batch.filter(Boolean).length;
        let message = __n('Imported :count change|Imported :count changes', changed, { count: changed });
        if (unmatched) message += '. ' + __n(':count row did not match an entry|:count rows did not match an entry', unmatched, { count: unmatched });
        Statamic.$toast.success(message + '. ' + __('Check them, then save.'));
    });
}

// ----- Keyboard and dirty state -----------------------------------------

function onKeydown(event) {
    const mod = event.ctrlKey || event.metaKey;
    if (!mod) return;

    const key = event.key.toLowerCase();
    const typing = ['INPUT', 'TEXTAREA'].includes(document.activeElement?.tagName);

    if (key === 's') {
        event.preventDefault();
        save();
    } else if (!typing && key === 'z' && !event.shiftKey) {
        event.preventDefault();
        undo();
    } else if (!typing && (key === 'y' || (key === 'z' && event.shiftKey))) {
        event.preventDefault();
        redo();
    }
}

const dirty = () => Statamic.$app.config.globalProperties.$dirty;

watch(changeCount, (count) => (count > 0 ? dirty().add(DIRTY) : dirty().remove(DIRTY)));

let searchTimer = null;
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => requestLoad(() => {}), 350);
});

onMounted(() => {
    load();
    window.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    dirty().remove(DIRTY);
});

const siteOptions = computed(() => props.sites.map((s) => ({ label: s.name, value: s.handle })));

function changeSite(value) {
    const previous = site.value;
    site.value = value;
    requestLoad(() => {});
    if (changeCount.value > 0) site.value = previous;
}

function clearSelection() {
    requestLoad(() => (selection.value = []));
}

function confirmedDiscard() {
    confirmDiscard.value = false;
    const change = pendingLoad.value;
    pendingLoad.value = null;
    change?.();
    load();
}
</script>

<template>
    <div class="ks-page" data-max-width-wrapper>
        <Head :title="[collection.title, __('Spreadsheet')]" />

        <Header :title="collection.title" icon="fieldtype-table">
            <template #title>
                <span class="flex items-center gap-2">
                    <Link :href="indexUrl" class="text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">{{ __('Spreadsheet') }}</Link>
                    <span class="text-gray-400">/</span>
                    <span>{{ collection.title }}</span>
                </span>
            </template>

            <span v-if="changeCount" class="text-sm text-gray-600 dark:text-gray-400">
                {{ __n(':count unsaved change|:count unsaved changes', changeCount, { count: changeCount }) }}
                <template v-if="changedEntries > 1">({{ __n('in :count entry|in :count entries', changedEntries, { count: changedEntries }) }})</template>
            </span>
            <Button v-if="changeCount" :text="__('Discard')" variant="ghost" @click="confirmDiscard = true" />
            <Button
                :text="__('Save')"
                variant="primary"
                :loading="saving"
                :disabled="!changeCount || saving"
                @click="save"
            />
            <CommandPaletteItem category="Actions" :text="__('Save changes')" icon="save" :action="save" />
            <CommandPaletteItem category="Actions" :text="__('Export CSV')" icon="download" :action="exportCsv" />
            <CommandPaletteItem category="Actions" :text="__('Find and replace')" icon="search-magnifying-glass" :action="() => (findOpen = true)" />
        </Header>

        <div class="flex flex-wrap items-center gap-2 mb-3">
            <Input v-model="search" :placeholder="__('Search titles…')" icon-prepend="search-magnifying-glass" class="w-64" size="sm" clearable />

            <Select
                v-if="siteOptions.length > 1"
                :model-value="site"
                :options="siteOptions"
                size="sm"
                class="w-44"
                @update:model-value="changeSite"
            />

            <Dropdown align="start">
                <template #trigger>
                    <Button :text="__('Columns')" icon="columns" size="sm" />
                </template>
                <DropdownMenu>
                    <div class="max-h-96 overflow-auto p-2 space-y-1.5 min-w-56">
                        <Checkbox
                            v-for="column in columns.filter((c) => !c.pinned)"
                            :key="column.handle"
                            :label="column.label"
                            :model-value="visible.includes(column.handle)"
                            size="sm"
                            @update:model-value="toggleColumn(column.handle, $event)"
                        />
                    </div>
                </DropdownMenu>
            </Dropdown>

            <Button :text="__('Find and replace')" icon="search-magnifying-glass" size="sm" @click="findOpen = true" />
            <Button :text="__('Undo')" icon="history" size="sm" variant="ghost" :disabled="!historySize" @click="undo" />
            <Button :text="__('Redo')" size="sm" variant="ghost" :disabled="!futureSize" @click="redo" />

            <div class="flex-1" />

            <Button :text="__('Export CSV')" icon="download" size="sm" variant="ghost" @click="exportCsv" />
            <Button :text="__('Import CSV')" icon="upload" size="sm" variant="ghost" @click="fileInput.click()" />
            <input ref="fileInput" type="file" accept=".csv,text/csv" class="hidden" @change="importCsv" />
        </div>

        <div v-if="selection.length" class="ks-banner mb-3">
            {{ __n('Showing the :count entry you selected.|Showing the :count entries you selected.', selection.length, { count: selection.length }) }}
            <button type="button" class="underline" @click="clearSelection">{{ __('Show all entries') }}</button>
        </div>

        <div v-if="truncated" class="ks-banner ks-banner-warning mb-3">
            {{ __('Showing the first :limit of :total entries. Search or select entries in the listing to edit the rest.', { limit, total }) }}
        </div>

        <div class="ks-grid-wrap" :class="{ 'ks-loading': loading }">
            <VGrid
                ref="grid"
                theme="compact"
                :source="source"
                :columns="gridColumns"
                :range="true"
                :resize="true"
                :use-clipboard="true"
                :can-focus="true"
                :row-headers="true"
                :hide-attribution="true"
                :row-size="36"
                @beforeeditstart="onBeforeEditStart"
                @beforeedit="onBeforeEdit"
                @beforerangeedit="onBeforeRangeEdit"
                @beforecopyapply="onBeforeCopyApply"
            />
            <div v-if="!loading && !source.length" class="ks-empty">
                {{ search ? __('No entries match your search.') : __('This collection has no entries in this site yet.') }}
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3 mt-2 text-xs text-gray-600 dark:text-gray-400">
            <span>{{ __n(':count entry|:count entries', source.length, { count: source.length }) }}</span>
            <Badge v-if="errorCount" color="red" size="sm" :text="__n(':count cell needs attention|:count cells need attention', errorCount, { count: errorCount })" />
            <span class="ms-auto">{{ __('Enter edits. Paste from Excel or Google Sheets. Drag the corner to fill. Ctrl+Z undoes, Ctrl+S saves.') }}</span>
        </div>

        <CellPicker
            v-if="picker"
            :row="picker.row"
            :column="picker.column"
            :labels="labels"
            :lookup-url="lookupUrl"
            :site="site"
            @pick="picked"
            @close="picker = null"
        />

        <FindReplace
            v-if="findOpen"
            :rows="source"
            :columns="columns.filter((c) => visible.includes(c.handle))"
            :is-locked="isLocked"
            @apply="applyReplacements"
            @close="findOpen = false"
        />

        <ConfirmationModal
            :open="confirmDiscard"
            :title="__('Discard your changes?')"
            :body-text="__n('Your :count unsaved change will be lost.|Your :count unsaved changes will be lost.', changeCount, { count: changeCount })"
            :button-text="__('Discard')"
            danger
            @update:open="confirmDiscard = $event"
            @confirm="pendingLoad ? confirmedDiscard() : discard()"
            @cancel="pendingLoad = null"
        />
    </div>
</template>
