<script setup>
import { computed, ref } from 'vue';
import { Button, Checkbox, Field, Input, Modal, ModalClose, Select } from '@statamic/cms/ui';

const props = defineProps({
    rows: Array,
    columns: Array,
    isLocked: Function,
});

const emit = defineEmits(['apply', 'close']);

const TEXT_KINDS = ['text', 'longtext'];

const open = ref(true);
const find = ref('');
const replace = ref('');
const column = ref('*');
const matchCase = ref(false);
const wholeCell = ref(false);

const textColumns = computed(() => props.columns.filter((c) => TEXT_KINDS.includes(c.kind) && !c.readonly));
const columnOptions = computed(() => [
    { label: __('All text columns'), value: '*' },
    ...textColumns.value.map((c) => ({ label: c.label, value: c.handle })),
]);

function escape(text) {
    return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

const matches = computed(() => {
    if (find.value === '') return [];

    const flags = matchCase.value ? 'g' : 'gi';
    const pattern = wholeCell.value ? new RegExp('^' + escape(find.value) + '$', flags) : new RegExp(escape(find.value), flags);
    const handles = column.value === '*' ? textColumns.value.map((c) => c.handle) : [column.value];
    const out = [];

    for (const row of props.rows) {
        for (const prop of handles) {
            const value = row[prop];
            if (typeof value !== 'string' || props.isLocked(row, prop)) continue;
            pattern.lastIndex = 0;
            if (!pattern.test(value)) continue;
            pattern.lastIndex = 0;
            out.push({ row, prop, value: value.replace(pattern, () => replace.value) });
        }
    }

    return out;
});

const entryCount = computed(() => new Set(matches.value.map((m) => m.row._id)).size);
</script>

<template>
    <Modal :open="open" :title="__('Find and replace')" @update:open="(v) => !v && emit('close')">
        <div class="space-y-4">
            <Field :label="__('Find')">
                <Input v-model="find" focus />
            </Field>
            <Field :label="__('Replace with')">
                <Input v-model="replace" />
            </Field>
            <Field :label="__('In')">
                <Select v-model="column" :options="columnOptions" />
            </Field>
            <div class="flex flex-wrap gap-4">
                <Checkbox v-model="matchCase" :label="__('Match case')" size="sm" />
                <Checkbox v-model="wholeCell" :label="__('Whole cell only')" size="sm" />
            </div>
            <p class="text-sm text-gray-600 dark:text-gray-400" aria-live="polite">
                <template v-if="find">
                    {{ __n(':count match|:count matches', matches.length, { count: matches.length }) }}
                    <template v-if="matches.length">{{ __n('in :count entry|in :count entries', entryCount, { count: entryCount }) }}</template>.
                    {{ __('Nothing is saved until you press Save.') }}
                </template>
            </p>
        </div>

        <template #footer>
            <div class="flex items-center justify-end gap-3 pt-3 pb-1">
                <ModalClose asChild>
                    <Button variant="ghost" :text="__('Cancel')" />
                </ModalClose>
                <Button variant="primary" :text="__('Replace all')" :disabled="!matches.length" @click="emit('apply', matches)" />
            </div>
        </template>
    </Modal>
</template>
