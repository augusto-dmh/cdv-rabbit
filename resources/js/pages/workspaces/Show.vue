<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { ExternalLink, RefreshCw } from 'lucide-vue-next';
import ConnectController from '@/actions/App/Http/Controllers/Workspaces/ConnectController';
import RepositoryController from '@/actions/App/Http/Controllers/Workspaces/RepositoryController';
import { index, update } from '@/actions/App/Http/Controllers/Workspaces/WorkspaceController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

type Repository = {
    id: number;
    name: string;
    full_name: string;
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
    llm_provider: string;
    scm_provider: 'bitbucket_cloud' | 'github_cloud';
    scm_owner_slug: string | null;
    github_installation_id: string | null;
    bitbucket_service_account: string | null;
};

type Props = {
    workspace: Workspace;
    repositories: Repository[];
    isAdmin: boolean;
};

const props = defineProps<Props>();

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

function toggleEnabled(repo: Repository): void {
    router.patch(
        RepositoryController.update.url({ workspace: props.workspace.slug, repository: repo.id }),
        { enabled: !repo.enabled },
        { preserveScroll: true },
    );
}

function updateProvider(provider: string): void {
    router.patch(
        update.url(props.workspace.slug),
        { llm_provider: provider },
        { preserveScroll: true },
    );
}
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

            <template v-if="workspace.scm_provider === 'bitbucket_cloud'">
                <Button v-if="!workspace.scm_owner_slug" as-child variant="default">
                    <Link :href="ConnectController.edit.url(workspace.slug)">Connect Bitbucket</Link>
                </Button>
                <Button v-else as-child variant="outline" size="sm">
                    <Link :href="ConnectController.edit.url(workspace.slug)">Manage connection</Link>
                </Button>
            </template>
            <template v-else>
                <Button v-if="!workspace.github_installation_id" as-child variant="default">
                    <Link :href="`/workspaces/${workspace.slug}/connect-github`">Connect GitHub</Link>
                </Button>
                <Button v-else as-child variant="outline" size="sm">
                    <Link :href="`/workspaces/${workspace.slug}/connect-github`">Manage installation</Link>
                </Button>
            </template>
        </div>

        <Card v-if="isAdmin">
            <CardHeader>
                <CardTitle class="text-sm font-medium">AI Provider</CardTitle>
            </CardHeader>
            <CardContent class="space-y-2">
                <p class="text-sm text-muted-foreground">Select the LLM provider for new reviews. Applies to new reviews only; in-flight reviews complete on the previous provider.</p>
                <Select :default-value="workspace.llm_provider" @update:model-value="updateProvider">
                    <SelectTrigger class="w-56">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="anthropic">Anthropic Claude</SelectItem>
                        <SelectItem value="openai">OpenAI GPT</SelectItem>
                    </SelectContent>
                </Select>
            </CardContent>
        </Card>

        <Card>
            <CardHeader class="flex flex-row items-center justify-between space-y-0">
                <CardTitle>Repositories</CardTitle>
                <Form
                    v-if="workspace.scm_owner_slug"
                    v-bind="RepositoryController.sync.form(workspace.slug)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" size="sm" variant="outline" :disabled="processing">
                        <RefreshCw class="mr-2 h-3.5 w-3.5" :class="{ 'animate-spin': processing }" />
                        Sync repositories
                    </Button>
                </Form>
            </CardHeader>
            <CardContent>
                <div v-if="repositories.length === 0" class="py-8 text-center text-sm text-muted-foreground">
                    <p v-if="!workspace.scm_owner_slug">
                        Connect your Bitbucket workspace first to discover repositories.
                    </p>
                    <p v-else>No repositories found. Click "Sync repositories" to import from Bitbucket.</p>
                </div>

                <table v-else class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-left text-muted-foreground">
                            <th class="pb-3 pr-4 font-medium">Repository</th>
                            <th class="pb-3 pr-4 font-medium">Default branch</th>
                            <th class="pb-3 pr-4 font-medium">Reviews</th>
                            <th class="pb-3 pr-4 font-medium">Last synced</th>
                            <th class="pb-3 font-medium">Enabled</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="repo in repositories" :key="repo.id" class="border-b last:border-0">
                            <td class="py-3 pr-4 font-medium">
                                <a
                                    :href="`https://bitbucket.org/${repo.full_name}`"
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
                                <Badge v-if="repo.enabled" variant="secondary">Active</Badge>
                                <Badge v-else variant="outline">Inactive</Badge>
                            </td>
                            <td class="py-3 pr-4 text-muted-foreground">
                                {{ repo.last_synced_at ?? 'Never' }}
                            </td>
                            <td class="py-3">
                                <Button
                                    size="sm"
                                    :variant="repo.enabled ? 'destructive' : 'default'"
                                    @click="toggleEnabled(repo)"
                                >
                                    {{ repo.enabled ? 'Disable' : 'Enable' }}
                                </Button>
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
