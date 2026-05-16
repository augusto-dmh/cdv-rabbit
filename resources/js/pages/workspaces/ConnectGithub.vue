<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CheckCircle, ChevronRight } from 'lucide-vue-next';
import { index, show } from '@/actions/App/Http/Controllers/Workspaces/WorkspaceController';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';

type WorkspaceProps = {
    id: number;
    name: string;
    slug: string;
    github_installation_id: string | null;
};

type Props = {
    workspace: WorkspaceProps;
};

const props = defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Workspaces', href: index.url() },
            { title: 'Workspace', href: '#' },
            { title: 'Connect GitHub', href: '#' },
        ],
    },
});

const isConnected = Boolean(props.workspace.github_installation_id);
</script>

<template>
    <Head title="Connect GitHub" />

    <div class="flex flex-col space-y-6 p-6">
        <Heading
            title="Connect GitHub"
            description="Install the cdv-rabbit GitHub App on the organization or account that owns your repositories."
        />

        <div v-if="isConnected" class="max-w-md space-y-4">
            <div class="flex items-center gap-3 rounded-lg border border-green-200 bg-green-50 p-4 dark:border-green-800 dark:bg-green-950">
                <CheckCircle class="h-5 w-5 text-green-600 dark:text-green-400" />
                <div class="space-y-1">
                    <p class="text-sm font-medium text-green-800 dark:text-green-200">GitHub installation connected.</p>
                    <p class="text-xs text-muted-foreground">
                        Installation ID: <code class="rounded bg-muted px-1">{{ workspace.github_installation_id }}</code>
                    </p>
                </div>
            </div>

            <p class="text-sm text-muted-foreground">
                To revoke access, uninstall the cdv-rabbit App from your GitHub organization settings. cdv-rabbit will
                clear this workspace's installation automatically on the
                <code class="rounded bg-muted px-1">installation.deleted</code> webhook.
            </p>

            <div class="flex gap-3">
                <Button as-child variant="default">
                    <Link :href="show.url(workspace.slug)">View workspace</Link>
                </Button>
            </div>
        </div>

        <div v-else class="max-w-lg space-y-4">
            <p class="text-sm text-muted-foreground">When you click the button below, you'll be redirected to GitHub to:</p>
            <ul class="space-y-2 text-sm text-muted-foreground">
                <li class="flex items-start gap-2">
                    <span class="mt-0.5 text-primary">•</span>
                    <span>Choose the organization or user account that owns the repositories you want reviewed.</span>
                </li>
                <li class="flex items-start gap-2">
                    <span class="mt-0.5 text-primary">•</span>
                    <span>Pick the specific repositories to grant the App access to.</span>
                </li>
                <li class="flex items-start gap-2">
                    <span class="mt-0.5 text-primary">•</span>
                    <span>Confirm the install. GitHub will redirect you back to cdv-rabbit and the workspace will be marked connected.</span>
                </li>
            </ul>

            <p class="text-xs text-muted-foreground">
                The link is signed with a single-use state token that expires in 10 minutes — refresh this page if you wait too long.
            </p>

            <Link
                :href="`/scm/github/install/start/${workspace.slug}`"
                method="post"
                as="button"
                class="inline-flex h-9 items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"
            >
                Install on GitHub
                <ChevronRight class="ml-2 h-4 w-4" />
            </Link>
        </div>
    </div>
</template>
