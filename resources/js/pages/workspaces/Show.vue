<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ExternalLink } from 'lucide-vue-next';
import ConnectController from '@/actions/App/Http/Controllers/Workspaces/ConnectController';
import { index } from '@/actions/App/Http/Controllers/Workspaces/WorkspaceController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

type Repository = {
    id: number;
    name: string;
    full_slug: string;
    default_branch: string;
    enabled: boolean;
    last_synced_at: string | null;
};

type Workspace = {
    id: number;
    name: string;
    slug: string;
    health: string;
    kill_switch_enabled: boolean;
    bitbucket_workspace_slug: string | null;
    bitbucket_service_account: string | null;
};

type Props = {
    workspace: Workspace;
    repositories: Repository[];
};

defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Workspaces',
                href: index.url(),
            },
            {
                title: 'Workspace',
                href: '#',
            },
        ],
    },
});
</script>

<template>
    <Head :title="workspace.name" />

    <div class="flex flex-col space-y-6 p-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <Heading :title="workspace.name" :description="workspace.slug" />
                <Badge v-if="workspace.kill_switch_enabled" variant="destructive">Kill switch ON</Badge>
                <Badge v-else-if="workspace.health === 'healthy'" variant="secondary">Healthy</Badge>
                <Badge v-else variant="destructive">{{ workspace.health }}</Badge>
            </div>

            <Button v-if="!workspace.bitbucket_workspace_slug" as-child variant="default">
                <Link :href="ConnectController.edit.url(workspace.slug)">Connect Bitbucket</Link>
            </Button>
            <Button v-else as-child variant="outline" size="sm">
                <Link :href="ConnectController.edit.url(workspace.slug)">Manage connection</Link>
            </Button>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Repositories</CardTitle>
            </CardHeader>
            <CardContent>
                <div v-if="repositories.length === 0" class="py-8 text-center text-sm text-muted-foreground">
                    <p v-if="!workspace.bitbucket_workspace_slug">
                        Connect your Bitbucket workspace first to discover repositories.
                    </p>
                    <p v-else>No repositories found. Sync your repositories from the connection settings.</p>
                </div>

                <table v-else class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-left text-muted-foreground">
                            <th class="pb-3 pr-4 font-medium">Repository</th>
                            <th class="pb-3 pr-4 font-medium">Default branch</th>
                            <th class="pb-3 pr-4 font-medium">Status</th>
                            <th class="pb-3 font-medium">Last synced</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="repo in repositories" :key="repo.id" class="border-b last:border-0">
                            <td class="py-3 pr-4 font-medium">
                                <a
                                    :href="`https://bitbucket.org/${repo.full_slug}`"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="flex items-center gap-1 hover:underline"
                                >
                                    {{ repo.name }}
                                    <ExternalLink class="h-3 w-3 text-muted-foreground" />
                                </a>
                            </td>
                            <td class="py-3 pr-4 text-muted-foreground">{{ repo.default_branch }}</td>
                            <td class="py-3 pr-4">
                                <Badge v-if="repo.enabled" variant="secondary">Enabled</Badge>
                                <Badge v-else variant="outline">Disabled</Badge>
                            </td>
                            <td class="py-3 text-muted-foreground">
                                {{ repo.last_synced_at ?? 'Never' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>Recent reviews</CardTitle>
            </CardHeader>
            <CardContent>
                <p class="py-4 text-center text-sm text-muted-foreground">
                    Reviews will appear here once the review pipeline is active.
                </p>
            </CardContent>
        </Card>
    </div>
</template>
