<script setup lang="ts">
import { computed } from 'vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

interface DailyPoint {
    date: string;
    success: number;
    failed: number;
    bytes: number;
}

const props = defineProps<{ data: DailyPoint[] }>();

const totals = computed(() => {
    const success = props.data.reduce((sum, d) => sum + d.success, 0);
    const failed = props.data.reduce((sum, d) => sum + d.failed, 0);
    const runs = success + failed;

    return {
        runs,
        success,
        failed,
        rate: runs > 0 ? Math.round((success / runs) * 100) : 0,
    };
});

const max = computed(() =>
    Math.max(1, ...props.data.map((d) => d.success + d.failed)),
);

function pct(value: number): string {
    return `${(value / max.value) * 100}%`;
}

function dayLabel(date: string): string {
    return date.slice(8); // DD from YYYY-MM-DD
}

function tip(d: DailyPoint): string {
    return `${d.date}: ${d.success} ok, ${d.failed} failed`;
}
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle class="flex items-center justify-between gap-2">
                <span>Daily backups</span>
                <span class="text-sm font-normal text-muted-foreground"
                    >last {{ data.length }} days</span
                >
            </CardTitle>
        </CardHeader>
        <CardContent>
            <div class="mb-4 flex flex-wrap gap-x-5 gap-y-1 text-sm">
                <span class="text-muted-foreground"
                    >{{ totals.runs }} runs</span
                >
                <span class="inline-flex items-center gap-1.5">
                    <span class="size-2.5 rounded-[2px] bg-green-600" />
                    {{ totals.success }} success
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <span class="size-2.5 rounded-[2px] bg-destructive" />
                    {{ totals.failed }} failed
                </span>
                <span class="text-muted-foreground">{{ totals.rate }}% ok</span>
            </div>

            <div
                v-if="totals.runs === 0"
                class="py-8 text-center text-sm text-muted-foreground"
            >
                No backups in this period yet. Scheduled runs will appear here.
            </div>

            <template v-else>
                <div class="flex h-40 items-end gap-1">
                    <div
                        v-for="(d, i) in data"
                        :key="i"
                        class="flex h-full flex-1 flex-col justify-end"
                        :title="tip(d)"
                    >
                        <div
                            v-if="d.failed > 0"
                            class="w-full rounded-t-[2px] bg-destructive"
                            :style="{ height: pct(d.failed) }"
                        />
                        <div
                            v-if="d.success > 0"
                            class="w-full bg-green-600"
                            :class="{ 'rounded-t-[2px]': d.failed === 0 }"
                            :style="{ height: pct(d.success) }"
                        />
                    </div>
                </div>
                <div class="mt-1.5 flex gap-1">
                    <div
                        v-for="(d, i) in data"
                        :key="i"
                        class="flex-1 text-center text-[10px] text-muted-foreground"
                    >
                        {{ dayLabel(d.date) }}
                    </div>
                </div>
            </template>
        </CardContent>
    </Card>
</template>
