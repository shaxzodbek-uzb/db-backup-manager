<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Cloud, Pencil, Plug, Plus, Send, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { create, destroy, edit, index, test } from '@/routes/destinations';

interface DestinationRow {
    id: number;
    name: string;
    type: 's3' | 'telegram';
    config: Record<string, string | boolean | undefined>;
    last_tested_at: string | null;
    last_test_ok: boolean | null;
    last_test_error: string | null;
}

defineProps<{ destinations: DestinationRow[] }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Destinations', href: index() }],
    },
});

const typeLabels: Record<DestinationRow['type'], string> = {
    s3: 'S3 / Spaces',
    telegram: 'Telegram',
};

function targetLabel(destination: DestinationRow): string {
    const config = destination.config;

    if (destination.type === 's3') {
        const location = config.endpoint || config.region || '';
        return [config.bucket, location].filter(Boolean).join(' · ');
    }

    return `chat ${config.chat_id ?? ''}`;
}

const testingId = ref<number | null>(null);

function runTest(destination: DestinationRow): void {
    router.post(
        test(destination.id).url,
        {},
        {
            preserveScroll: true,
            onStart: () => (testingId.value = destination.id),
            onFinish: () => (testingId.value = null),
        },
    );
}

function destroyDestination(destination: DestinationRow): void {
    if (
        !window.confirm(
            `Delete destination "${destination.name}"? This cannot be undone.`,
        )
    ) {
        return;
    }

    router.delete(destroy(destination.id).url, { preserveScroll: true });
}
</script>

<template>
    <Head title="Destinations" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="Destinations"
                description="Where backups are delivered — S3-compatible storage or a Telegram channel."
            />
            <Button as-child size="sm">
                <Link :href="create()">
                    <Plus />
                    New destination
                </Link>
            </Button>
        </div>

        <div
            v-if="destinations.length === 0"
            class="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-sidebar-border/70 p-12 text-center dark:border-sidebar-border"
        >
            <Cloud class="size-8 text-muted-foreground" />
            <div>
                <p class="font-medium">No destinations yet</p>
                <p class="text-sm text-muted-foreground">
                    Add a DigitalOcean Space (or any S3 bucket) or a Telegram
                    channel to store backups.
                </p>
            </div>
            <Button as-child size="sm" variant="outline">
                <Link :href="create()">
                    <Plus />
                    Add your first destination
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
                        <th class="px-4 py-3 font-medium">Target</th>
                        <th class="px-4 py-3 font-medium">Last test</th>
                        <th class="px-4 py-3 text-right font-medium">
                            Actions
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="destination in destinations"
                        :key="destination.id"
                        class="border-b border-sidebar-border/50 last:border-0 dark:border-sidebar-border/50"
                    >
                        <td class="px-4 py-3 font-medium">
                            {{ destination.name }}
                        </td>
                        <td class="px-4 py-3">
                            <Badge variant="secondary" class="gap-1">
                                <Send
                                    v-if="destination.type === 'telegram'"
                                    class="size-3"
                                />
                                <Cloud v-else class="size-3" />
                                {{ typeLabels[destination.type] }}
                            </Badge>
                        </td>
                        <td
                            class="px-4 py-3 text-muted-foreground"
                            :title="targetLabel(destination)"
                        >
                            {{ targetLabel(destination) }}
                        </td>
                        <td class="px-4 py-3">
                            <Badge
                                v-if="destination.last_test_ok === true"
                                class="bg-green-600 text-white hover:bg-green-600"
                                :title="destination.last_tested_at ?? undefined"
                                >Passed</Badge
                            >
                            <Badge
                                v-else-if="destination.last_test_ok === false"
                                variant="destructive"
                                :title="
                                    destination.last_test_error ?? undefined
                                "
                                >Failed</Badge
                            >
                            <Badge v-else variant="secondary">Never</Badge>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    :disabled="testingId === destination.id"
                                    @click="runTest(destination)"
                                >
                                    <Spinner
                                        v-if="testingId === destination.id"
                                    />
                                    <Plug v-else />
                                    Test
                                </Button>
                                <Button as-child size="sm" variant="ghost">
                                    <Link :href="edit(destination.id)">
                                        <Pencil />
                                        Edit
                                    </Link>
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    class="text-destructive hover:text-destructive"
                                    @click="destroyDestination(destination)"
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
