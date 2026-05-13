<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { CheckCircle, ChevronRight } from 'lucide-vue-next';
import { ref } from 'vue';
import ConnectController from '@/actions/App/Http/Controllers/Workspaces/ConnectController';
import { index, show } from '@/actions/App/Http/Controllers/Workspaces/WorkspaceController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type WorkspaceProps = {
    id: number;
    name: string;
    slug: string;
    bitbucket_workspace_slug: string | null;
    bitbucket_service_account: string | null;
};

type Props = {
    workspace: WorkspaceProps;
    isConnected: boolean;
};

const props = defineProps<Props>();

const step = ref(props.isConnected ? 3 : 1);

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
            {
                title: 'Connect Bitbucket',
                href: '#',
            },
        ],
    },
});
</script>

<template>
    <Head title="Connect Bitbucket" />

    <div class="flex flex-col space-y-6 p-6">
        <Heading title="Connect Bitbucket" description="Link your Bitbucket workspace to enable automated code reviews." />

        <!-- Step indicators -->
        <div class="flex items-center gap-2 text-sm">
            <span
                :class="[
                    'flex h-7 w-7 items-center justify-center rounded-full text-xs font-semibold',
                    step >= 1 ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground',
                ]"
            >1</span>
            <span :class="step >= 1 ? 'font-medium' : 'text-muted-foreground'">Overview</span>
            <ChevronRight class="h-4 w-4 text-muted-foreground" />
            <span
                :class="[
                    'flex h-7 w-7 items-center justify-center rounded-full text-xs font-semibold',
                    step >= 2 ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground',
                ]"
            >2</span>
            <span :class="step >= 2 ? 'font-medium' : 'text-muted-foreground'">Credentials</span>
            <ChevronRight class="h-4 w-4 text-muted-foreground" />
            <span
                :class="[
                    'flex h-7 w-7 items-center justify-center rounded-full text-xs font-semibold',
                    step >= 3 ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground',
                ]"
            >3</span>
            <span :class="step >= 3 ? 'font-medium' : 'text-muted-foreground'">Done</span>
        </div>

        <!-- Step 1: Overview -->
        <div v-if="step === 1" class="max-w-lg space-y-4">
            <p class="text-sm text-muted-foreground">
                To connect cdv-rabbit to your Bitbucket workspace, you'll need:
            </p>
            <ul class="space-y-2 text-sm text-muted-foreground">
                <li class="flex items-start gap-2">
                    <span class="mt-0.5 text-primary">•</span>
                    <span><strong>Workspace slug</strong> — the identifier in your Bitbucket URL (e.g. <code class="rounded bg-muted px-1">my-team</code>)</span>
                </li>
                <li class="flex items-start gap-2">
                    <span class="mt-0.5 text-primary">•</span>
                    <span>
                        <strong>API token</strong> — an
                        <a
                            href="https://support.atlassian.com/bitbucket-cloud/docs/create-an-app-password/"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="text-primary underline"
                        >App Password</a>
                        with scopes: <code class="rounded bg-muted px-1">pullrequest:read/write</code>, <code class="rounded bg-muted px-1">repository:read/write</code>, <code class="rounded bg-muted px-1">webhook</code>, <code class="rounded bg-muted px-1">account:read</code>
                    </span>
                </li>
                <li class="flex items-start gap-2">
                    <span class="mt-0.5 text-primary">•</span>
                    <span><strong>Service account username</strong> — the Bitbucket username that owns the token</span>
                </li>
            </ul>
            <Button @click="step = 2">
                Continue
                <ChevronRight class="ml-2 h-4 w-4" />
            </Button>
        </div>

        <!-- Step 2: Credentials form -->
        <div v-else-if="step === 2" class="max-w-md">
            <Form
                v-bind="ConnectController.update.form(workspace.slug)"
                class="space-y-5"
                v-slot="{ errors, processing }"
                @success="step = 3"
            >
                <div class="grid gap-2">
                    <Label for="bitbucket_workspace_slug">Bitbucket workspace slug</Label>
                    <Input
                        id="bitbucket_workspace_slug"
                        name="bitbucket_workspace_slug"
                        :default-value="workspace.bitbucket_workspace_slug ?? ''"
                        placeholder="my-team"
                        required
                    />
                    <InputError :message="errors.bitbucket_workspace_slug" />
                </div>

                <div class="grid gap-2">
                    <Label for="bitbucket_token">API token</Label>
                    <Input
                        id="bitbucket_token"
                        name="bitbucket_token"
                        type="password"
                        placeholder="App password"
                        autocomplete="new-password"
                        required
                    />
                    <InputError :message="errors.bitbucket_token" />
                </div>

                <div class="grid gap-2">
                    <Label for="bitbucket_service_account">Service account username</Label>
                    <Input
                        id="bitbucket_service_account"
                        name="bitbucket_service_account"
                        :default-value="workspace.bitbucket_service_account ?? ''"
                        placeholder="svc-cdv-rabbit"
                        required
                    />
                    <InputError :message="errors.bitbucket_service_account" />
                </div>

                <div class="flex gap-3">
                    <Button type="button" variant="outline" @click="step = 1">Back</Button>
                    <Button type="submit" :disabled="processing">
                        {{ processing ? 'Validating…' : 'Connect workspace' }}
                    </Button>
                </div>
            </Form>
        </div>

        <!-- Step 3: Success -->
        <div v-else class="max-w-md space-y-4">
            <div class="flex items-center gap-3 rounded-lg border border-green-200 bg-green-50 p-4 dark:border-green-800 dark:bg-green-950">
                <CheckCircle class="h-5 w-5 text-green-600 dark:text-green-400" />
                <p class="text-sm font-medium text-green-800 dark:text-green-200">
                    Bitbucket workspace connected successfully.
                </p>
            </div>

            <p class="text-sm text-muted-foreground">
                Next step: sync your repositories and enable the ones you want cdv-rabbit to review.
            </p>

            <div class="flex gap-3">
                <Button as-child variant="default">
                    <Link :href="show.url(workspace.id)">View workspace</Link>
                </Button>
                <Button type="button" variant="outline" @click="step = 2">Update credentials</Button>
            </div>
        </div>

        <!-- Revoke section (when already connected) -->
        <div v-if="isConnected" class="border-t pt-6">
            <Heading variant="small" title="Revoke token" description="Clear the stored Bitbucket token. You will need to re-enter credentials." />
            <Form
                v-bind="ConnectController.destroy.form(workspace.slug)"
                class="mt-4"
                v-slot="{ processing }"
            >
                <Button type="submit" variant="destructive" :disabled="processing" size="sm">
                    {{ processing ? 'Revoking…' : 'Revoke token' }}
                </Button>
            </Form>
        </div>
    </div>
</template>
