<script setup>
import { Head, Link } from '@statamic/cms/inertia';
import { Description, EmptyStateMenu, Header, Icon } from '@statamic/cms/ui';

defineProps({
    collections: { type: Array, default: () => [] },
});
</script>

<template>
    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <Head :title="__('Spreadsheet')" />

        <Header :title="__('Spreadsheet')" icon="fieldtype-table" />

        <Description
            :text="__('Pick a collection to edit its entries side by side. You can also select entries in any listing and choose Edit in Spreadsheet.')"
            class="-mt-4 mb-6"
        />

        <EmptyStateMenu v-if="!collections.length" :heading="__('There are no collections you can edit.')" />

        <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <Link
                v-for="collection in collections"
                :key="collection.handle"
                :href="collection.url"
                class="group flex items-center gap-4 rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-4 shadow-ui-sm hover:border-gray-300 dark:hover:border-gray-700"
            >
                <span class="size-10 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center shrink-0">
                    <Icon :name="collection.icon" class="size-5 text-gray-600 dark:text-gray-300" />
                </span>
                <span class="min-w-0">
                    <span class="block font-medium truncate group-hover:underline">{{ collection.title }}</span>
                    <span class="block text-sm text-gray-500">{{ __n(':count entry|:count entries', collection.count, { count: collection.count }) }}</span>
                </span>
            </Link>
        </div>
    </div>
</template>
