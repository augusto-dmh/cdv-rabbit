<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ExternalLink } from 'lucide-vue-next';
import { computed } from 'vue';
import { index as reviewsIndex, show as reviewShow } from '@/actions/App/Http/Controllers/Reviews/ReviewController';
import { index as workspacesIndex } from '@/actions/App/Http/Controllers/Workspaces/WorkspaceController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type Repository = {
    id: number;
    name: string;
    full_slug: string;
};

type Review = {
    id: number;
    pull_request_number: number;
    status: string;
    created_at: string;
    started_at: string | null;
    finished_at: string | null;
    prompt_tokens: number | null;
    completion_tokens: number | null;
    cost_usd_cents: number | null;
    cost_usd: string;
    repository: Repository;
};

type PaginatorLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type Paginator = {
    data: Review[];
    links: PaginatorLink[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

type Workspace = {
    id: number;
    name: string;
    slug: string;
};

type Filters = {
    repository_id?: string | null;
    status?: string | null;
    date_from?: string | null;
    date_to?: string | null;
};

type Props = {
    workspace: Workspace;
    reviews: Paginator;
    filters: Filters;
    repositories?: Repository[];
};

const props = defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Workspaces', href: workspacesIndex.url() },
            { title: 'Workspace', href: '#' },
            { title: 'Reviews', href: '#' },
        ],
    },
});

const statusOptions = [
    { value: 'queued', label: 'Queued' },
    { value: 'running', label: 'Running' },
    { value: 'posted', label: 'Posted' },
    { value: 'failed', label: 'Failed' },
    { value: 'skipped', label: 'Skipped' },
];

const repoOptions = computed<Repository[]>(() => {
    if (props.repositories && props.repositories.length > 0) {
        return props.repositories;
    }

    const seen = new Set<number>();
    const out: Repository[] = [];

    for (const r of props.reviews.data) {
        if (r.repository && !seen.has(r.repository.id)) {
            seen.add(r.repository.id);
            out.push(r.repository);
        }
    }

    return out;
});

function statusVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (status === 'posted') {
return 'secondary';
}

    if (status === 'failed') {
return 'destructive';
}

    if (status === 'running') {
return 'default';
}

    return 'outline';
}

function totalTokens(review: Review): string {
    const t = (review.prompt_tokens ?? 0) + (review.completion_tokens ?? 0);

    if (t === 0) {
return '—';
}

    if (t >= 1_000_000) {
return (t / 1_000_000).toFixed(1) + 'M';
}

    if (t >= 1_000) {
return (t / 1_000).toFixed(1) + 'K';
}

    return String(t);
}

function duration(review: Review): string {
    if (!review.started_at || !review.finished_at) {
return '—';
}

    const ms = new Date(review.finished_at).getTime() - new Date(review.started_at).getTime();

    if (ms < 1000) {
return ms + 'ms';
}

    return (ms / 1000).toFixed(1) + 's';
}

function applyFilter(key: string, value: string | null): void {
    router.get(
        reviewsIndex.url(props.workspace),
        { ...props.filters, [key]: value || undefined },
        { preserveScroll: true, preserveState: true },
    );
}

function goToPage(url: string | null): void {
    if (!url) {
return;
}

    router.visit(url, { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <Head title="Reviews" />

    <div class="flex flex-col space-y-6 p-6">
        <Heading title="Reviews" description="Automated code reviews for pull requests." />

        <Card>
            <CardHeader>
                <CardTitle class="text-sm font-medium">Filters</CardTitle>
            </CardHeader>
            <CardContent>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="grid gap-1.5">
                        <Label>Repository</Label>
                        <Select
                            :model-value="filters.repository_id ?? ''"
                            @update:model-value="(v) => applyFilter('repository_id', String(v))"
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="All repositories" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="">All repositories</SelectItem>
                                <SelectItem
                                    v-for="repo in repoOptions"
                                    :key="repo.id"
                                    :value="String(repo.id)"
                                >
                                    {{ repo.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div class="grid gap-1.5">
                        <Label>Status</Label>
                        <Select
                            :model-value="filters.status ?? ''"
                            @update:model-value="(v) => applyFilter('status', String(v))"
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="All statuses" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="">All statuses</SelectItem>
                                <SelectItem
                                    v-for="opt in statusOptions"
                                    :key="opt.value"
                                    :value="opt.value"
                                >
                                    {{ opt.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="date_from">From</Label>
                        <Input
                            id="date_from"
                            type="date"
                            :value="filters.date_from ?? ''"
                            @change="(e: Event) => applyFilter('date_from', (e.target as HTMLInputElement).value)"
                        />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="date_to">To</Label>
                        <Input
                            id="date_to"
                            type="date"
                            :value="filters.date_to ?? ''"
                            @change="(e: Event) => applyFilter('date_to', (e.target as HTMLInputElement).value)"
                        />
                    </div>
                </div>
            </CardContent>
        </Card>

        <Card>
            <CardContent class="p-0">
                <div
                    v-if="reviews.data.length === 0"
                    class="py-12 text-center text-sm text-muted-foreground"
                >
                    No reviews found. Reviews appear here once the pipeline runs.
                </div>

                <table v-else class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-left text-muted-foreground">
                            <th class="px-4 py-3 font-medium">PR</th>
                            <th class="px-4 py-3 font-medium">Repository</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 font-medium">Created</th>
                            <th class="px-4 py-3 font-medium">Tokens</th>
                            <th class="px-4 py-3 font-medium">Cost</th>
                            <th class="px-4 py-3 font-medium">Duration</th>
                            <th class="px-4 py-3 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="review in reviews.data"
                            :key="review.id"
                            class="border-b last:border-0 hover:bg-muted/30"
                        >
                            <td class="px-4 py-3 font-medium">
                                <a
                                    :href="`https://bitbucket.org/${review.repository.full_slug}/pull-requests/${review.pull_request_number}`"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="flex items-center gap-1 hover:underline"
                                >
                                    #{{ review.pull_request_number }}
                                    <ExternalLink class="h-3 w-3 text-muted-foreground" />
                                </a>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">{{ review.repository.name }}</td>
                            <td class="px-4 py-3">
                                <Badge :variant="statusVariant(review.status)">{{ review.status }}</Badge>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ new Date(review.created_at).toLocaleDateString() }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">{{ totalTokens(review) }}</td>
                            <td class="px-4 py-3 text-muted-foreground">{{ review.cost_usd }}</td>
                            <td class="px-4 py-3 text-muted-foreground">{{ duration(review) }}</td>
                            <td class="px-4 py-3">
                                <Button as-child size="sm" variant="outline">
                                    <a :href="reviewShow.url({ workspace: workspace.slug, review: review.id })">
                                        View
                                    </a>
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div v-if="reviews.last_page > 1" class="flex items-center justify-between border-t px-4 py-3">
                    <p class="text-sm text-muted-foreground">
                        Showing {{ reviews.from }}–{{ reviews.to }} of {{ reviews.total }}
                    </p>
                    <div class="flex flex-wrap gap-1">
                        <button
                            v-for="link in reviews.links"
                            :key="link.label"
                            :disabled="!link.url"
                            class="inline-flex h-8 min-w-8 items-center justify-center rounded-md border px-2 text-sm font-medium transition-colors disabled:pointer-events-none disabled:opacity-50"
                            :class="link.active ? 'bg-primary text-primary-foreground border-primary' : 'bg-background hover:bg-accent hover:text-accent-foreground'"
                            @click="goToPage(link.url)"
                            v-html="link.label"
                        />
                    </div>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
