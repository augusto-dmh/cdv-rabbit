<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import ConnectController from '@/actions/App/Http/Controllers/Workspaces/ConnectController';
import WorkspaceController, { index } from '@/actions/App/Http/Controllers/Workspaces/WorkspaceController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Workspace = {
    id: number;
    name: string;
    slug: string;
    kill_switch_enabled: boolean;
    health: string;
};

type Props = {
    workspaces: Workspace[];
};

defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Workspaces',
                href: index.url(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Workspaces" />

    <div class="flex flex-col space-y-6 p-6">
        <div class="flex items-center justify-between">
            <Heading title="Workspaces" description="Manage your Bitbucket workspace connections." />
        </div>

        <div v-if="workspaces.length === 0" class="rounded-xl border border-dashed p-12 text-center text-muted-foreground">
            <p class="text-sm">No workspaces yet. Create your first workspace to get started.</p>
        </div>

        <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Card v-for="workspace in workspaces" :key="workspace.id">
                <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                    <CardTitle class="text-base font-semibold">{{ workspace.name }}</CardTitle>
                    <Badge v-if="workspace.kill_switch_enabled" variant="destructive">Kill switch ON</Badge>
                    <Badge v-else variant="secondary">{{ workspace.health }}</Badge>
                </CardHeader>
                <CardContent class="space-y-3">
                    <p class="text-sm text-muted-foreground">{{ workspace.slug }}</p>
                    <div class="flex gap-2">
                        <Button as-child size="sm" variant="default">
                            <Link :href="WorkspaceController.show.url(workspace.slug)">Open</Link>
                        </Button>
                        <Button as-child size="sm" variant="outline">
                            <Link :href="ConnectController.edit.url(workspace.slug)">Connect</Link>
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>

        <div class="border-t pt-6">
            <Heading variant="small" title="New workspace" description="Create a workspace to connect a Bitbucket account." />

            <Form
                v-bind="WorkspaceController.store.form()"
                class="mt-4 max-w-md space-y-4"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-2">
                    <Label for="name">Workspace name</Label>
                    <Input id="name" name="name" placeholder="My Team" required />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="slug">Slug</Label>
                    <Input id="slug" name="slug" placeholder="my-team" pattern="[a-z0-9-]+" required />
                    <InputError :message="errors.slug" />
                </div>

                <Button type="submit" :disabled="processing">
                    <Plus class="mr-2 h-4 w-4" />
                    Create workspace
                </Button>
            </Form>
        </div>
    </div>
</template>
