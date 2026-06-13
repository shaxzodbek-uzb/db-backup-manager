<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index, store, update } from '@/routes/connections';

interface ConnectionRow {
    id: number;
    name: string;
    driver: 'mysql' | 'pgsql';
    host: string;
    port: number;
    username: string;
    has_password: boolean;
}

const props = defineProps<{ connection: ConnectionRow | null }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Connections', href: index() }],
    },
});

const isEdit = computed(() => props.connection !== null);

const defaultPorts: Record<'mysql' | 'pgsql', number> = {
    mysql: 3306,
    pgsql: 5432,
};

const form = useForm({
    name: props.connection?.name ?? '',
    driver: props.connection?.driver ?? 'mysql',
    host: props.connection?.host ?? '',
    port: props.connection?.port ?? defaultPorts.mysql,
    username: props.connection?.username ?? '',
    password: '',
});

// Keep the port in sync with the driver while it still holds a default value.
watch(
    () => form.driver,
    (driver, previous) => {
        if (!form.port || form.port === defaultPorts[previous]) {
            form.port = defaultPorts[driver];
        }
    },
);

const passwordPlaceholder = computed(() =>
    isEdit.value && props.connection?.has_password
        ? '•••••••• (unchanged)'
        : '',
);

function submit(): void {
    if (isEdit.value && props.connection) {
        form.put(update(props.connection.id).url, { preserveScroll: true });
    } else {
        form.post(store().url);
    }
}
</script>

<template>
    <Head :title="isEdit ? 'Edit connection' : 'New connection'" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            :title="isEdit ? 'Edit connection' : 'New connection'"
            description="Credentials are encrypted at rest and never sent back to the browser."
        />

        <form class="max-w-2xl space-y-6" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    v-model="form.name"
                    required
                    autocomplete="off"
                    placeholder="Production MySQL"
                />
                <InputError :message="form.errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="driver">Database type</Label>
                <Select v-model="form.driver">
                    <SelectTrigger id="driver" class="w-full">
                        <SelectValue placeholder="Select a database type" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="mysql">MySQL / MariaDB</SelectItem>
                        <SelectItem value="pgsql">PostgreSQL</SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.driver" />
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-[1fr_8rem]">
                <div class="grid gap-2">
                    <Label for="host">Host</Label>
                    <Input
                        id="host"
                        v-model="form.host"
                        required
                        autocomplete="off"
                        placeholder="db.example.com"
                    />
                    <InputError :message="form.errors.host" />
                </div>
                <div class="grid gap-2">
                    <Label for="port">Port</Label>
                    <Input
                        id="port"
                        v-model.number="form.port"
                        type="number"
                        min="1"
                        max="65535"
                        required
                    />
                    <InputError :message="form.errors.port" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="username">Username</Label>
                <Input
                    id="username"
                    v-model="form.username"
                    required
                    autocomplete="off"
                    placeholder="root"
                />
                <InputError :message="form.errors.username" />
            </div>

            <div class="grid gap-2">
                <Label for="password">Password</Label>
                <Input
                    id="password"
                    v-model="form.password"
                    type="password"
                    autocomplete="new-password"
                    :placeholder="passwordPlaceholder"
                />
                <p
                    v-if="isEdit && connection?.has_password"
                    class="text-sm text-muted-foreground"
                >
                    Leave blank to keep the current password.
                </p>
                <InputError :message="form.errors.password" />
            </div>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">Save</Button>
                <Button as-child type="button" variant="ghost">
                    <Link :href="index()">Cancel</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
