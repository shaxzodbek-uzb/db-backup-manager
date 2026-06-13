<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Database } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/connections';

interface ConnectionRow {
    id: number;
    name: string;
    driver: 'mysql' | 'pgsql';
    host: string;
}

interface DatabaseRow {
    name: string;
    size_bytes: number;
}

const props = defineProps<{
    connection: ConnectionRow;
    databases: DatabaseRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Connections', href: index() }],
    },
});

const totalBytes = computed(() =>
    props.databases.reduce((sum, db) => sum + db.size_bytes, 0),
);

function humanSize(bytes: number): string {
    if (!bytes) {
        return '—';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }

    return `${value.toFixed(unit === 0 || value >= 10 ? 0 : 1)} ${units[unit]}`;
}
</script>

<template>
    <Head :title="`Databases — ${connection.name}`" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                :title="`Databases on ${connection.name}`"
                :description="`${databases.length} database(s) · ${humanSize(totalBytes)} total`"
            />
            <Button as-child size="sm" variant="outline">
                <Link :href="index()">
                    <ArrowLeft />
                    Back
                </Link>
            </Button>
        </div>

        <div
            v-if="databases.length === 0"
            class="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-sidebar-border/70 p-12 text-center dark:border-sidebar-border"
        >
            <Database class="size-8 text-muted-foreground" />
            <p class="text-sm text-muted-foreground">
                No user databases found on this server.
            </p>
        </div>

        <div
            v-else
            class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
        >
            <table class="w-full text-sm">
                <thead
                    class="border-b border-sidebar-border/70 text-left text-muted-foreground dark:border-sidebar-border"
                >
                    <tr>
                        <th class="px-4 py-3 font-medium">Database</th>
                        <th class="px-4 py-3 text-right font-medium">Size</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="db in databases"
                        :key="db.name"
                        class="border-b border-sidebar-border/50 last:border-0 dark:border-sidebar-border/50"
                    >
                        <td class="px-4 py-3 font-medium">{{ db.name }}</td>
                        <td class="px-4 py-3 text-right text-muted-foreground">
                            {{ humanSize(db.size_bytes) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
