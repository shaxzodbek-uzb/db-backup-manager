<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
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
import { index, store, update } from '@/routes/destinations';

interface DestinationConfig {
    region?: string;
    bucket?: string;
    access_key?: string;
    endpoint?: string;
    prefix?: string;
    use_path_style?: boolean;
    chat_id?: string;
}

interface DestinationRow {
    id: number;
    name: string;
    type: 's3' | 'telegram';
    config: DestinationConfig;
    secrets: Record<string, boolean>;
}

const props = defineProps<{ destination: DestinationRow | null }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Destinations', href: index() }],
    },
});

const isEdit = computed(() => props.destination !== null);

const form = useForm({
    name: props.destination?.name ?? '',
    type: props.destination?.type ?? 's3',
    config: {
        region: props.destination?.config.region ?? '',
        bucket: props.destination?.config.bucket ?? '',
        access_key: props.destination?.config.access_key ?? '',
        secret_key: '',
        endpoint: props.destination?.config.endpoint ?? '',
        prefix: props.destination?.config.prefix ?? '',
        use_path_style: props.destination?.config.use_path_style ?? false,
        chat_id: props.destination?.config.chat_id ?? '',
        bot_token: '',
    },
});

function err(key: string): string | undefined {
    return (form.errors as Record<string, string | undefined>)[key];
}

function secretSet(key: string): boolean {
    return isEdit.value && Boolean(props.destination?.secrets[key]);
}

function submit(): void {
    if (isEdit.value && props.destination) {
        form.put(update(props.destination.id).url, { preserveScroll: true });
    } else {
        form.post(store().url);
    }
}
</script>

<template>
    <Head :title="isEdit ? 'Edit destination' : 'New destination'" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            :title="isEdit ? 'Edit destination' : 'New destination'"
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
                    placeholder="Daily off-site backups"
                />
                <InputError :message="err('name')" />
            </div>

            <div class="grid gap-2">
                <Label for="type">Type</Label>
                <Select v-model="form.type">
                    <SelectTrigger id="type" class="w-full">
                        <SelectValue placeholder="Select a destination type" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="s3">
                            S3-compatible (AWS S3, DigitalOcean Spaces, MinIO)
                        </SelectItem>
                        <SelectItem value="telegram">
                            Telegram channel
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="err('type')" />
            </div>

            <!-- S3 / DigitalOcean Spaces -->
            <template v-if="form.type === 's3'">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="bucket">Bucket / Space name</Label>
                        <Input
                            id="bucket"
                            v-model="form.config.bucket"
                            autocomplete="off"
                            placeholder="my-backups"
                        />
                        <InputError :message="err('config.bucket')" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="region">Region</Label>
                        <Input
                            id="region"
                            v-model="form.config.region"
                            autocomplete="off"
                            placeholder="fra1"
                        />
                        <p class="text-xs text-muted-foreground">
                            DO Spaces slug (fra1, nyc3…) or AWS region
                            (us-east-1).
                        </p>
                        <InputError :message="err('config.region')" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="endpoint">Endpoint</Label>
                    <Input
                        id="endpoint"
                        v-model="form.config.endpoint"
                        autocomplete="off"
                        placeholder="https://fra1.digitaloceanspaces.com"
                    />
                    <p class="text-xs text-muted-foreground">
                        For DigitalOcean Spaces use
                        <code
                            >https://&lt;region&gt;.digitaloceanspaces.com</code
                        >. Leave empty for AWS S3.
                    </p>
                    <InputError :message="err('config.endpoint')" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="access_key">Access key</Label>
                        <Input
                            id="access_key"
                            v-model="form.config.access_key"
                            autocomplete="off"
                            placeholder="DO00..."
                        />
                        <InputError :message="err('config.access_key')" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="secret_key">Secret key</Label>
                        <Input
                            id="secret_key"
                            v-model="form.config.secret_key"
                            type="password"
                            autocomplete="new-password"
                            :placeholder="
                                secretSet('secret_key')
                                    ? '•••••••• (unchanged)'
                                    : ''
                            "
                        />
                        <p
                            v-if="secretSet('secret_key')"
                            class="text-xs text-muted-foreground"
                        >
                            Leave blank to keep the current secret key.
                        </p>
                        <InputError :message="err('config.secret_key')" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="prefix">Path prefix (optional)</Label>
                    <Input
                        id="prefix"
                        v-model="form.config.prefix"
                        autocomplete="off"
                        placeholder="db-backups"
                    />
                    <p class="text-xs text-muted-foreground">
                        Folder inside the bucket where backups are stored.
                    </p>
                    <InputError :message="err('config.prefix')" />
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <Checkbox v-model="form.config.use_path_style" />
                    Use path-style endpoint (MinIO and some S3-compatibles)
                </label>
                <InputError :message="err('config.use_path_style')" />
            </template>

            <!-- Telegram -->
            <template v-else-if="form.type === 'telegram'">
                <div class="grid gap-2">
                    <Label for="chat_id">Channel / chat id</Label>
                    <Input
                        id="chat_id"
                        v-model="form.config.chat_id"
                        autocomplete="off"
                        placeholder="@my_channel or -1001234567890"
                    />
                    <p class="text-xs text-muted-foreground">
                        Add the bot as an administrator of the channel first.
                    </p>
                    <InputError :message="err('config.chat_id')" />
                </div>

                <div class="grid gap-2">
                    <Label for="bot_token">Bot token</Label>
                    <Input
                        id="bot_token"
                        v-model="form.config.bot_token"
                        type="password"
                        autocomplete="new-password"
                        :placeholder="
                            secretSet('bot_token') ? '•••••••• (unchanged)' : ''
                        "
                    />
                    <p class="text-xs text-muted-foreground">
                        Issued by
                        <code>@BotFather</code>. Telegram bots can send files up
                        to 50&nbsp;MB.
                    </p>
                    <p
                        v-if="secretSet('bot_token')"
                        class="text-xs text-muted-foreground"
                    >
                        Leave blank to keep the current bot token.
                    </p>
                    <InputError :message="err('config.bot_token')" />
                </div>
            </template>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">Save</Button>
                <Button as-child type="button" variant="ghost">
                    <Link :href="index()">Cancel</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
