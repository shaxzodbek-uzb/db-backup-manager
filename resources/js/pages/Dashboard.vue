<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    CircleCheck,
    CircleDashed,
    CircleX,
    Cloud,
    Database,
    Plus,
} from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import BackupDailyChart from '@/components/BackupDailyChart.vue';
import { dashboard } from '@/routes';
import {
    create as createConnection,
    edit as editConnection,
    index as connectionsIndex,
} from '@/routes/connections';
import {
    create as createDestination,
    edit as editDestination,
    index as destinationsIndex,
} from '@/routes/destinations';

interface Breakdown {
    total: number;
    passing: number;
    failing: number;
    untested: number;
}

interface RecentConnection {
    id: number;
    name: string;
    driver: 'mysql' | 'pgsql';
    host: string;
    last_test_ok: boolean | null;
    last_tested_at: string | null;
}

interface RecentDestination {
    id: number;
    name: string;
    type: 's3' | 'telegram';
    last_test_ok: boolean | null;
    last_tested_at: string | null;
}

interface DailyPoint {
    date: string;
    success: number;
    failed: number;
    bytes: number;
}

const props = defineProps<{
    stats: { connections: Breakdown; destinations: Breakdown };
    backupDaily: DailyPoint[];
    recentConnections: RecentConnection[];
    recentDestinations: RecentDestination[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
});

const isEmpty = computed(
    () =>
        props.stats.connections.total === 0 &&
        props.stats.destinations.total === 0,
);

const driverLabels: Record<RecentConnection['driver'], string> = {
    mysql: 'MySQL / MariaDB',
    pgsql: 'PostgreSQL',
};

const typeLabels: Record<RecentDestination['type'], string> = {
    s3: 'S3 / Spaces',
    telegram: 'Telegram',
};

function timeAgo(iso: string | null): string {
    if (!iso) {
        return 'never tested';
    }

    const seconds = Math.floor((Date.now() - new Date(iso).getTime()) / 1000);
    if (seconds < 60) {
        return 'just now';
    }
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) {
        return `${minutes}m ago`;
    }
    const hours = Math.floor(minutes / 60);
    if (hours < 24) {
        return `${hours}h ago`;
    }
    return `${Math.floor(hours / 24)}d ago`;
}
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="Dashboard"
            description="Overview of your database connections and backup destinations."
        />

        <Card v-if="isEmpty" class="border-dashed">
            <CardHeader>
                <CardTitle>Get started</CardTitle>
                <CardDescription>
                    Set up your first secure backup in two steps.
                </CardDescription>
            </CardHeader>
            <CardContent
                class="flex flex-col gap-3 sm:flex-row sm:items-center"
            >
                <Button as-child>
                    <Link :href="createConnection()">
                        <Database />
                        1. Add a connection
                    </Link>
                </Button>
                <ArrowRight
                    class="hidden size-4 text-muted-foreground sm:block"
                />
                <Button as-child variant="outline">
                    <Link :href="createDestination()">
                        <Cloud />
                        2. Add a destination
                    </Link>
                </Button>
            </CardContent>
        </Card>

        <div class="grid gap-4 md:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <Database class="size-4 text-muted-foreground" />
                        Connections
                    </CardTitle>
                    <CardAction>
                        <Button as-child size="sm" variant="ghost">
                            <Link :href="createConnection()">
                                <Plus />
                                New
                            </Link>
                        </Button>
                    </CardAction>
                </CardHeader>
                <CardContent>
                    <div class="text-3xl font-semibold">
                        {{ stats.connections.total }}
                    </div>
                    <div
                        class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground"
                    >
                        <span class="inline-flex items-center gap-1.5">
                            <CircleCheck class="size-3.5 text-green-600" />
                            {{ stats.connections.passing }} passing
                        </span>
                        <span class="inline-flex items-center gap-1.5">
                            <CircleX class="size-3.5 text-destructive" />
                            {{ stats.connections.failing }} failing
                        </span>
                        <span class="inline-flex items-center gap-1.5">
                            <CircleDashed class="size-3.5" />
                            {{ stats.connections.untested }} untested
                        </span>
                    </div>
                </CardContent>
                <CardFooter>
                    <Link
                        :href="connectionsIndex()"
                        class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
                    >
                        Manage connections
                        <ArrowRight class="size-3.5" />
                    </Link>
                </CardFooter>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <Cloud class="size-4 text-muted-foreground" />
                        Destinations
                    </CardTitle>
                    <CardAction>
                        <Button as-child size="sm" variant="ghost">
                            <Link :href="createDestination()">
                                <Plus />
                                New
                            </Link>
                        </Button>
                    </CardAction>
                </CardHeader>
                <CardContent>
                    <div class="text-3xl font-semibold">
                        {{ stats.destinations.total }}
                    </div>
                    <div
                        class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground"
                    >
                        <span class="inline-flex items-center gap-1.5">
                            <CircleCheck class="size-3.5 text-green-600" />
                            {{ stats.destinations.passing }} passing
                        </span>
                        <span class="inline-flex items-center gap-1.5">
                            <CircleX class="size-3.5 text-destructive" />
                            {{ stats.destinations.failing }} failing
                        </span>
                        <span class="inline-flex items-center gap-1.5">
                            <CircleDashed class="size-3.5" />
                            {{ stats.destinations.untested }} untested
                        </span>
                    </div>
                </CardContent>
                <CardFooter>
                    <Link
                        :href="destinationsIndex()"
                        class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
                    >
                        Manage destinations
                        <ArrowRight class="size-3.5" />
                    </Link>
                </CardFooter>
            </Card>
        </div>

        <BackupDailyChart :data="backupDaily" />

        <div v-if="!isEmpty" class="grid gap-4 md:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle>Recent connections</CardTitle>
                </CardHeader>
                <CardContent class="px-0">
                    <p
                        v-if="recentConnections.length === 0"
                        class="px-6 text-sm text-muted-foreground"
                    >
                        No connections yet.
                    </p>
                    <ul v-else>
                        <li
                            v-for="connection in recentConnections"
                            :key="connection.id"
                        >
                            <Link
                                :href="editConnection(connection.id)"
                                class="flex items-center justify-between gap-3 px-6 py-2.5 hover:bg-muted/50"
                            >
                                <div class="min-w-0">
                                    <p class="truncate font-medium">
                                        {{ connection.name }}
                                    </p>
                                    <p
                                        class="truncate text-xs text-muted-foreground"
                                    >
                                        {{ driverLabels[connection.driver] }} ·
                                        {{ connection.host }}
                                    </p>
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    <Badge
                                        v-if="connection.last_test_ok === true"
                                        class="bg-green-600 text-white hover:bg-green-600"
                                        >Passed</Badge
                                    >
                                    <Badge
                                        v-else-if="
                                            connection.last_test_ok === false
                                        "
                                        variant="destructive"
                                        >Failed</Badge
                                    >
                                    <Badge v-else variant="secondary"
                                        >Never</Badge
                                    >
                                    <span
                                        class="hidden text-xs text-muted-foreground sm:inline"
                                        >{{
                                            timeAgo(connection.last_tested_at)
                                        }}</span
                                    >
                                </div>
                            </Link>
                        </li>
                    </ul>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Recent destinations</CardTitle>
                </CardHeader>
                <CardContent class="px-0">
                    <p
                        v-if="recentDestinations.length === 0"
                        class="px-6 text-sm text-muted-foreground"
                    >
                        No destinations yet.
                    </p>
                    <ul v-else>
                        <li
                            v-for="destination in recentDestinations"
                            :key="destination.id"
                        >
                            <Link
                                :href="editDestination(destination.id)"
                                class="flex items-center justify-between gap-3 px-6 py-2.5 hover:bg-muted/50"
                            >
                                <div class="min-w-0">
                                    <p class="truncate font-medium">
                                        {{ destination.name }}
                                    </p>
                                    <p
                                        class="truncate text-xs text-muted-foreground"
                                    >
                                        {{ typeLabels[destination.type] }}
                                    </p>
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    <Badge
                                        v-if="destination.last_test_ok === true"
                                        class="bg-green-600 text-white hover:bg-green-600"
                                        >Passed</Badge
                                    >
                                    <Badge
                                        v-else-if="
                                            destination.last_test_ok === false
                                        "
                                        variant="destructive"
                                        >Failed</Badge
                                    >
                                    <Badge v-else variant="secondary"
                                        >Never</Badge
                                    >
                                    <span
                                        class="hidden text-xs text-muted-foreground sm:inline"
                                        >{{
                                            timeAgo(destination.last_tested_at)
                                        }}</span
                                    >
                                </div>
                            </Link>
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
