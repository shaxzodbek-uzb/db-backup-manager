<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Database, Pencil, Plug, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    create,
    databases,
    destroy,
    edit,
    index,
    test,
} from '@/routes/connections';

interface ConnectionRow {
    id: number;
    name: string;
    driver: 'mysql' | 'pgsql';
    host: string;
    port: number;
    username: string;
    ssh_enabled: boolean;
    has_password: boolean;
    last_tested_at: string | null;
    last_test_ok: boolean | null;
    last_test_error: string | null;
}

defineProps<{ connections: ConnectionRow[] }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Connections', href: index() }],
    },
});

const driverLabels: Record<ConnectionRow['driver'], string> = {
    mysql: 'MySQL / MariaDB',
    pgsql: 'PostgreSQL',
};

const testingId = ref<number | null>(null);

function runTest(connection: ConnectionRow): void {
    router.post(
        test(connection.id).url,
        {},
        {
            preserveScroll: true,
            onStart: () => (testingId.value = connection.id),
            onFinish: () => (testingId.value = null),
        },
    );
}

function destroyConnection(connection: ConnectionRow): void {
    if (
        !window.confirm(
            `Delete connection "${connection.name}"? This cannot be undone.`,
        )
    ) {
        return;
    }

    router.delete(destroy(connection.id).url, { preserveScroll: true });
}
</script>

<template>
    <Head title="Connections" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="Connections"
                description="Database servers this tool can connect to and back up."
            />
            <Button as-child size="sm">
                <Link :href="create()">
                    <Plus />
                    New connection
                </Link>
            </Button>
        </div>

        <div
            v-if="connections.length === 0"
            class="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-sidebar-border/70 p-12 text-center dark:border-sidebar-border"
        >
            <Database class="size-8 text-muted-foreground" />
            <div>
                <p class="font-medium">No connections yet</p>
                <p class="text-sm text-muted-foreground">
                    Add a database server to test connectivity and list its
                    databases.
                </p>
            </div>
            <Button as-child size="sm" variant="outline">
                <Link :href="create()">
                    <Plus />
                    Add your first connection
                </Link>
            </Button>
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
                        <th class="px-4 py-3 font-medium">Name</th>
                        <th class="px-4 py-3 font-medium">Type</th>
                        <th class="px-4 py-3 font-medium">Server</th>
                        <th class="px-4 py-3 font-medium">Last test</th>
                        <th class="px-4 py-3 text-right font-medium">
                            Actions
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="connection in connections"
                        :key="connection.id"
                        class="border-b border-sidebar-border/50 last:border-0 dark:border-sidebar-border/50"
                    >
                        <td class="px-4 py-3 font-medium">
                            {{ connection.name }}
                            <Badge
                                v-if="connection.ssh_enabled"
                                variant="outline"
                                class="ml-2"
                                >SSH</Badge
                            >
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ driverLabels[connection.driver] }}
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ connection.username }}@{{ connection.host }}:{{
                                connection.port
                            }}
                        </td>
                        <td class="px-4 py-3">
                            <Badge
                                v-if="connection.last_test_ok === true"
                                class="bg-green-600 text-white hover:bg-green-600"
                                :title="connection.last_tested_at ?? undefined"
                                >Passed</Badge
                            >
                            <Badge
                                v-else-if="connection.last_test_ok === false"
                                variant="destructive"
                                :title="connection.last_test_error ?? undefined"
                                >Failed</Badge
                            >
                            <Badge v-else variant="secondary">Never</Badge>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    :disabled="testingId === connection.id"
                                    @click="runTest(connection)"
                                >
                                    <Spinner
                                        v-if="testingId === connection.id"
                                    />
                                    <Plug v-else />
                                    Test
                                </Button>
                                <Button as-child size="sm" variant="ghost">
                                    <Link :href="databases(connection.id)">
                                        <Database />
                                        Databases
                                    </Link>
                                </Button>
                                <Button as-child size="sm" variant="ghost">
                                    <Link :href="edit(connection.id)">
                                        <Pencil />
                                        Edit
                                    </Link>
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    class="text-destructive hover:text-destructive"
                                    @click="destroyConnection(connection)"
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
