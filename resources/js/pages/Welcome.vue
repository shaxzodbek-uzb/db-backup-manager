<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, Cloud, Database, ShieldCheck } from '@lucide/vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { dashboard, login } from '@/routes';

const page = usePage();

const features = [
    {
        icon: ShieldCheck,
        title: 'Encrypted by default',
        text: 'Credentials are encrypted at rest and never sent back to the browser.',
    },
    {
        icon: Database,
        title: 'PostgreSQL & MySQL',
        text: 'Connect directly or over an SSH tunnel, then pick the databases to back up.',
    },
    {
        icon: Cloud,
        title: 'Flexible destinations',
        text: 'Ship backups to S3, DigitalOcean Spaces, or a Telegram channel.',
    },
];
</script>

<template>
    <Head title="Welcome" />

    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <header
            class="mx-auto flex w-full max-w-5xl items-center justify-between p-6"
        >
            <div class="flex items-center gap-2">
                <div
                    class="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground"
                >
                    <AppLogoIcon
                        class="size-5 fill-current text-white dark:text-black"
                    />
                </div>
                <span class="font-semibold">{{ page.props.name }}</span>
            </div>
            <Button as-child variant="outline" size="sm">
                <Link :href="page.props.auth.user ? dashboard() : login()">
                    {{ page.props.auth.user ? 'Dashboard' : 'Log in' }}
                </Link>
            </Button>
        </header>

        <main
            class="mx-auto flex w-full max-w-5xl flex-1 flex-col justify-center gap-12 p-6 py-16"
        >
            <div class="max-w-2xl">
                <h1
                    class="text-4xl font-semibold tracking-tight text-balance sm:text-5xl"
                >
                    Secure, scheduled database backups.
                </h1>
                <p class="mt-4 text-lg text-muted-foreground">
                    {{ page.props.name }} connects to your database servers,
                    dumps the databases you choose, and ships them off-site — on
                    a schedule, with credentials kept encrypted.
                </p>
                <div class="mt-8 flex flex-wrap gap-3">
                    <Button as-child size="lg">
                        <Link
                            :href="page.props.auth.user ? dashboard() : login()"
                        >
                            {{
                                page.props.auth.user
                                    ? 'Go to dashboard'
                                    : 'Log in'
                            }}
                            <ArrowRight />
                        </Link>
                    </Button>
                </div>
            </div>

            <div class="grid gap-6 sm:grid-cols-3">
                <div
                    v-for="feature in features"
                    :key="feature.title"
                    class="rounded-xl border border-sidebar-border/70 p-5 dark:border-sidebar-border"
                >
                    <component :is="feature.icon" class="size-5 text-primary" />
                    <h3 class="mt-3 font-medium">{{ feature.title }}</h3>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ feature.text }}
                    </p>
                </div>
            </div>
        </main>

        <footer
            class="mx-auto w-full max-w-5xl p-6 text-sm text-muted-foreground"
        >
            {{ page.props.name }}
        </footer>
    </div>
</template>
