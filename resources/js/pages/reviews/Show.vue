<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ExternalLink } from 'lucide-vue-next';
import { index as workspacesIndex } from '@/actions/App/Http/Controllers/Workspaces/WorkspaceController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

type Repository = {
    id: number;
    name: string;
    full_name: string;
};

type Review = {
    id: number;
    pull_request_number: number;
    status: string;
    started_at: string | null;
    finished_at: string | null;
    prompt_tokens: number | null;
    completion_tokens: number | null;
    cost_usd_cents: number | null;
    cost_usd: string;
    secrets_redacted: number | null;
    error_class: string | null;
    error_message: string | null;
    created_at: string;
    repository: Repository;
};

type LlmCall = {
    id: number;
    provider: string | null;
    model_id: string;
    role: string | null;
    input_tokens: number;
    cache_creation_input_tokens: number;
    cache_read_input_tokens: number;
    output_tokens: number;
    cost_usd: string;
    duration_ms: number | null;
    http_status: number | null;
    error_type: string | null;
};

type Comment = {
    id: number;
    file_path: string;
    line: number | null;
    bitbucket_comment_id: number | null;
    comment_type: string;
    posted_at: string | null;
    created_at: string;
};

type Workspace = {
    id: number;
    name: string;
    slug: string;
};

type Props = {
    workspace: Workspace;
    review: Review;
    comments: Comment[];
    llmCalls: LlmCall[];
};

const props = defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Workspaces', href: workspacesIndex.url() },
            { title: 'Workspace', href: '#' },
            { title: 'Reviews', href: '#' },
            { title: 'Review', href: '#' },
        ],
    },
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

function formatDateTime(dt: string | null): string {
    if (!dt) {
return '—';
}

    return new Date(dt).toLocaleString();
}

function duration(): string {
    if (!props.review.started_at || !props.review.finished_at) {
return '—';
}

    const ms =
        new Date(props.review.finished_at).getTime() -
        new Date(props.review.started_at).getTime();

    if (ms < 1000) {
return ms + 'ms';
}

    return (ms / 1000).toFixed(1) + 's';
}

function totalTokens(): number {
    return (props.review.prompt_tokens ?? 0) + (props.review.completion_tokens ?? 0);
}

function commentTypeBadge(type: string): 'default' | 'secondary' | 'outline' {
    if (type === 'summary') {
return 'default';
}

    if (type === 'inline') {
return 'secondary';
}

    return 'outline';
}


</script>

<template>
    <Head :title="`Review PR #${review.pull_request_number}`" />

    <div class="flex flex-col space-y-6 p-6">
        <div class="flex items-start justify-between gap-4">
            <div class="flex items-center gap-3">
                <Heading
                    :title="`PR #${review.pull_request_number}`"
                    :description="review.repository.name"
                />
                <a
                    :href="`https://bitbucket.org/${review.repository.full_name}/pull-requests/${review.pull_request_number}`"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="text-muted-foreground hover:text-foreground"
                >
                    <ExternalLink class="h-4 w-4" />
                </a>
            </div>
            <Badge :variant="statusVariant(review.status)" class="shrink-0 mt-1">
                {{ review.status }}
            </Badge>
        </div>

        <div v-if="review.status === 'failed'" class="rounded-lg border border-destructive/50 bg-destructive/10 p-4 text-sm text-destructive">
            <p class="font-semibold">Review failed</p>
            <p v-if="review.error_class" class="mt-1 font-mono text-xs">{{ review.error_class }}</p>
            <p v-if="review.error_message" class="mt-1">{{ review.error_message }}</p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle class="text-sm font-medium">Timeline</CardTitle>
                </CardHeader>
                <CardContent class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-muted-foreground">Started</span>
                        <span>{{ formatDateTime(review.started_at) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-muted-foreground">Finished</span>
                        <span>{{ formatDateTime(review.finished_at) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-muted-foreground">Duration</span>
                        <span>{{ duration() }}</span>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-sm font-medium">Cost &amp; Tokens</CardTitle>
                </CardHeader>
                <CardContent class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-muted-foreground">Prompt tokens</span>
                        <span>{{ review.prompt_tokens?.toLocaleString() ?? '—' }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-muted-foreground">Completion tokens</span>
                        <span>{{ review.completion_tokens?.toLocaleString() ?? '—' }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-muted-foreground">Total tokens</span>
                        <span>{{ totalTokens().toLocaleString() }}</span>
                    </div>
                    <div class="flex justify-between border-t pt-2">
                        <span class="text-muted-foreground">Cost</span>
                        <span class="font-medium">{{ review.cost_usd }}</span>
                    </div>
                    <div v-if="llmCalls.length > 0 && llmCalls[0].provider" class="flex justify-between">
                        <span class="text-muted-foreground">Provider</span>
                        <span class="font-mono text-xs">{{ llmCalls[0].provider }} · {{ llmCalls[0].model_id }}</span>
                    </div>
                    <div v-if="(review.secrets_redacted ?? 0) > 0" class="flex justify-between text-amber-600 dark:text-amber-400">
                        <span>Secrets redacted</span>
                        <span>{{ review.secrets_redacted }}</span>
                    </div>
                </CardContent>
            </Card>
        </div>

        <Card v-if="llmCalls.length > 0">
            <CardHeader>
                <CardTitle class="text-sm font-medium">LLM Calls</CardTitle>
            </CardHeader>
            <CardContent class="p-0">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-left text-muted-foreground">
                            <th class="px-4 py-3 font-medium">Provider</th>
                            <th class="px-4 py-3 font-medium">Model</th>
                            <th class="px-4 py-3 font-medium">Role</th>
                            <th class="px-4 py-3 font-medium">Input</th>
                            <th class="px-4 py-3 font-medium">Cache write</th>
                            <th class="px-4 py-3 font-medium">Cache read</th>
                            <th class="px-4 py-3 font-medium">Output</th>
                            <th class="px-4 py-3 font-medium">Duration</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="call in llmCalls"
                            :key="call.id"
                            class="border-b last:border-0"
                            :class="{ 'bg-destructive/5': call.error_type }"
                        >
                            <td class="px-4 py-3 text-muted-foreground">
                                <span class="font-mono text-xs">{{ call.provider ?? '—' }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="font-mono text-xs">{{ call.model_id }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <Badge variant="outline" class="capitalize">{{ call.role ?? '—' }}</Badge>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">{{ call.input_tokens.toLocaleString() }}</td>
                            <td class="px-4 py-3 text-muted-foreground">{{ call.cache_creation_input_tokens.toLocaleString() }}</td>
                            <td class="px-4 py-3 text-muted-foreground">{{ call.cache_read_input_tokens.toLocaleString() }}</td>
                            <td class="px-4 py-3 text-muted-foreground">{{ call.output_tokens.toLocaleString() }}</td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ call.duration_ms != null ? call.duration_ms + 'ms' : '—' }}
                            </td>
                            <td class="px-4 py-3">
                                <span v-if="call.error_type" class="text-xs text-destructive">{{ call.error_type }}</span>
                                <span v-else class="text-xs text-muted-foreground">{{ call.http_status ?? '—' }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>

        <Card v-if="comments.length > 0">
            <CardHeader>
                <CardTitle class="text-sm font-medium">Posted Comments</CardTitle>
            </CardHeader>
            <CardContent class="p-0">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-left text-muted-foreground">
                            <th class="px-4 py-3 font-medium">File</th>
                            <th class="px-4 py-3 font-medium">Line</th>
                            <th class="px-4 py-3 font-medium">Type</th>
                            <th class="px-4 py-3 font-medium">Bitbucket ID</th>
                            <th class="px-4 py-3 font-medium">Posted at</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="comment in comments"
                            :key="comment.id"
                            class="border-b last:border-0"
                        >
                            <td class="max-w-xs truncate px-4 py-3 font-mono text-xs">{{ comment.file_path }}</td>
                            <td class="px-4 py-3 text-muted-foreground">{{ comment.line ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <Badge :variant="commentTypeBadge(comment.comment_type)" class="capitalize">
                                    {{ comment.comment_type }}
                                </Badge>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                <a
                                    v-if="comment.bitbucket_comment_id"
                                    :href="`https://bitbucket.org/${review.repository.full_name}/pull-requests/${review.pull_request_number}/_/diff#comment-${comment.bitbucket_comment_id}`"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="flex items-center gap-1 hover:underline"
                                >
                                    {{ comment.bitbucket_comment_id }}
                                    <ExternalLink class="h-3 w-3" />
                                </a>
                                <span v-else>—</span>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ comment.posted_at ? new Date(comment.posted_at).toLocaleString() : '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>

        <div v-if="comments.length === 0 && llmCalls.length === 0" class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
            No LLM calls or comments recorded for this review yet.
        </div>
    </div>
</template>
