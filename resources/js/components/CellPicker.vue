<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Button, Checkbox, Icon, Input, Modal, ModalClose, Radio, RadioGroup } from '@statamic/cms/ui';
import { http } from '../http.js';

const props = defineProps({
    row: Object,
    column: Object,
    labels: Object,
    lookupUrl: String,
    site: String,
});

const emit = defineEmits(['pick', 'close']);

const open = ref(true);
const multiple = computed(() => props.column.kind === 'relationship' ? props.column.max !== 1 : !!props.column.multiple);
const current = props.row[props.column.handle];
const selected = ref(current === null || current === undefined ? [] : [].concat(current).map(String));
const query = ref('');
const results = ref([]);
const searching = ref(false);
const found = ref({});

const known = computed(() => ({ ...(props.labels[props.column.relationship] || {}), ...found.value }));
const atMax = computed(() => props.column.max && selected.value.length >= props.column.max);

function title(id) {
    return known.value[id]?.title ?? id;
}

function toggle(id, on) {
    if (on) {
        if (!multiple.value) selected.value = [id];
        else if (!selected.value.includes(id) && !atMax.value) selected.value = [...selected.value, id];
    } else {
        selected.value = selected.value.filter((s) => s !== id);
    }
}

let timer = null;
function lookup() {
    if (props.column.kind !== 'relationship') return;
    clearTimeout(timer);
    timer = setTimeout(() => {
        searching.value = true;
        http()
            .get(props.lookupUrl, { params: { field: props.column.handle, q: query.value, site: props.site } })
            .then(({ data }) => {
                results.value = data.results;
                const add = {};
                for (const r of data.results) add[r.id] = { title: r.title, thumb: r.thumb };
                found.value = { ...found.value, ...add };
            })
            .finally(() => (searching.value = false));
    }, 200);
}

watch(query, lookup);
onMounted(lookup);

function done() {
    const value = props.column.kind === 'relationship'
        ? selected.value
        : multiple.value ? selected.value : selected.value[0] ?? null;

    const newLabels = props.column.kind === 'relationship'
        ? { [props.column.relationship]: Object.fromEntries(selected.value.map((id) => [id, known.value[id] ?? { title: id }])) }
        : {};

    emit('pick', value, newLabels);
}

function onKeydown(event) {
    if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') done();
}
</script>

<template>
    <Modal :open="open" :title="column.label" @update:open="(v) => !v && emit('close')" @keydown="onKeydown">
        <div class="space-y-4">
            <!-- Options -->
            <template v-if="column.kind === 'options'">
                <div v-if="multiple" class="space-y-2 max-h-80 overflow-auto">
                    <Checkbox
                        v-for="option in column.options"
                        :key="option.value"
                        :label="option.label"
                        :model-value="selected.includes(option.value)"
                        @update:model-value="toggle(option.value, $event)"
                    />
                </div>
                <RadioGroup v-else :model-value="selected[0] ?? null" @update:model-value="(v) => (selected = v === null ? [] : [v])">
                    <Radio v-for="option in column.options" :key="option.value" :value="option.value" :label="option.label" />
                </RadioGroup>
            </template>

            <!-- Relationships -->
            <template v-else>
                <div v-if="selected.length" class="flex flex-wrap gap-1.5">
                    <span
                        v-for="id in selected"
                        :key="id"
                        class="inline-flex items-center gap-1 rounded-md bg-gray-100 dark:bg-gray-800 px-2 py-1 text-sm"
                    >
                        <img v-if="known[id]?.thumb" :src="known[id].thumb" alt="" class="size-5 rounded object-cover" />
                        {{ title(id) }}
                        <button type="button" class="text-gray-500 hover:text-red-600" :aria-label="__('Remove')" @click="toggle(id, false)">
                            <Icon name="x" class="size-3" />
                        </button>
                    </span>
                </div>

                <Input v-model="query" :placeholder="__('Search…')" icon-prepend="search-magnifying-glass" :loading="searching" focus />

                <ul class="max-h-72 overflow-auto divide-y divide-gray-100 dark:divide-gray-800 rounded-lg border border-gray-200 dark:border-gray-800">
                    <li v-for="item in results" :key="item.id">
                        <button
                            type="button"
                            class="w-full flex items-center gap-3 px-3 py-2 text-start hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50"
                            :disabled="!selected.includes(item.id) && atMax && multiple"
                            @click="toggle(item.id, !selected.includes(item.id))"
                        >
                            <img v-if="item.thumb" :src="item.thumb" alt="" class="size-8 rounded object-cover" />
                            <span class="flex-1 min-w-0">
                                <span class="block truncate text-sm">{{ item.title }}</span>
                                <span v-if="item.hint" class="block truncate text-xs text-gray-500">{{ item.hint }}</span>
                            </span>
                            <Icon v-if="selected.includes(item.id)" name="checkmark" class="size-4 text-green-600" />
                        </button>
                    </li>
                    <li v-if="!results.length && !searching" class="px-3 py-4 text-sm text-gray-500">{{ __('Nothing found.') }}</li>
                </ul>

                <p v-if="column.max" class="text-xs text-gray-500">{{ __('Choose at most :max.', { max: column.max }) }}</p>
            </template>
        </div>

        <template #footer>
            <div class="flex items-center justify-between gap-3 pt-3 pb-1">
                <Button :text="__('Clear')" variant="ghost" :disabled="!selected.length" @click="selected = []" />
                <div class="flex items-center gap-3">
                    <ModalClose asChild>
                        <Button variant="ghost" :text="__('Cancel')" />
                    </ModalClose>
                    <Button variant="primary" :text="__('Done')" @click="done" />
                </div>
            </div>
        </template>
    </Modal>
</template>
