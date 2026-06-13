<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
    ssl_mode: string | null;
    ssh_enabled: boolean;
    ssh_host: string | null;
    ssh_port: number | null;
    ssh_user: string | null;
    ssh_auth: string | null;
    has_ssh_private_key: boolean;
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
    driver: props.connection?.driver ?? 'pgsql',
    host: props.connection?.host ?? '',
    port: props.connection?.port ?? defaultPorts.pgsql,
    username: props.connection?.username ?? '',
    password: '',
    ssl_mode: props.connection?.ssl_mode ?? '',
    ssh_enabled: props.connection?.ssh_enabled ?? false,
    ssh_host: props.connection?.ssh_host ?? '',
    ssh_port: props.connection?.ssh_port ?? 22,
    ssh_user: props.connection?.ssh_user ?? '',
    ssh_auth: 'key',
    ssh_private_key: '',
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

const keyPlaceholder = computed(() =>
    isEdit.value && props.connection?.has_ssh_private_key
        ? '•••••••• (unchanged)'
        : '-----BEGIN OPENSSH PRIVATE KEY-----',
);

const textareaClass =
    'border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 dark:bg-input/30 w-full min-w-0 rounded-md border bg-transparent px-3 py-2 font-mono text-xs shadow-xs transition-[color,box-shadow] outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50';

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
                    placeholder="Production PostgreSQL"
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
                        <SelectItem value="pgsql">PostgreSQL</SelectItem>
                        <SelectItem value="mysql">MySQL / MariaDB</SelectItem>
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
                    placeholder="postgres"
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

            <!-- TLS (PostgreSQL sslmode is wired into the runtime connection) -->
            <div v-if="form.driver === 'pgsql'" class="grid gap-2">
                <Label for="ssl_mode">SSL mode</Label>
                <Select v-model="form.ssl_mode">
                    <SelectTrigger id="ssl_mode" class="w-full">
                        <SelectValue placeholder="Default (prefer)" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="prefer">prefer</SelectItem>
                        <SelectItem value="require">require</SelectItem>
                        <SelectItem value="verify-ca">verify-ca</SelectItem>
                        <SelectItem value="verify-full">verify-full</SelectItem>
                        <SelectItem value="allow">allow</SelectItem>
                        <SelectItem value="disable">disable</SelectItem>
                    </SelectContent>
                </Select>
                <p class="text-xs text-muted-foreground">
                    Use <code>require</code> or higher to force TLS. Client
                    certificate verification is coming later.
                </p>
                <InputError :message="form.errors.ssl_mode" />
            </div>

            <!-- SSH tunnel -->
            <div class="space-y-4 rounded-lg border p-4">
                <label class="flex items-center gap-2 text-sm font-medium">
                    <Checkbox v-model="form.ssh_enabled" />
                    Reach the database through an SSH tunnel
                </label>

                <template v-if="form.ssh_enabled">
                    <p class="text-xs text-muted-foreground">
                        The tunnel forwards a local port to
                        <code>{{ form.host || 'host' }}:{{ form.port }}</code>
                        from the SSH server. Only key authentication
                        (passphrase-less) is supported.
                    </p>

                    <div
                        class="grid grid-cols-1 gap-4 sm:grid-cols-[1fr_8rem_1fr]"
                    >
                        <div class="grid gap-2">
                            <Label for="ssh_host">SSH host</Label>
                            <Input
                                id="ssh_host"
                                v-model="form.ssh_host"
                                autocomplete="off"
                                placeholder="bastion.example.com"
                            />
                            <InputError :message="form.errors.ssh_host" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="ssh_port">SSH port</Label>
                            <Input
                                id="ssh_port"
                                v-model.number="form.ssh_port"
                                type="number"
                                min="1"
                                max="65535"
                            />
                            <InputError :message="form.errors.ssh_port" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="ssh_user">SSH user</Label>
                            <Input
                                id="ssh_user"
                                v-model="form.ssh_user"
                                autocomplete="off"
                                placeholder="tunnel"
                            />
                            <InputError :message="form.errors.ssh_user" />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="ssh_private_key">Private key</Label>
                        <textarea
                            id="ssh_private_key"
                            v-model="form.ssh_private_key"
                            rows="6"
                            autocomplete="off"
                            spellcheck="false"
                            :class="textareaClass"
                            :placeholder="keyPlaceholder"
                        />
                        <p
                            v-if="isEdit && connection?.has_ssh_private_key"
                            class="text-xs text-muted-foreground"
                        >
                            Leave blank to keep the stored key.
                        </p>
                        <InputError :message="form.errors.ssh_private_key" />
                    </div>
                </template>
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
