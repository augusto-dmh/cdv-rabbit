<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';

type Workspace = {
    id: number;
    name: string;
    slug: string;
    kill_switch_enabled: boolean;
};

const props = defineProps<{
    workspace: Workspace;
    globalKilled: boolean;
}>();

const open = ref(false);
const reason = ref('');

function killSwitchUpdateUrl(): string {
    return `/workspaces/${props.workspace.slug}/admin/kill-switch`;
}
</script>

<template>
    <Head title="Kill Switch" />

    <div class="mx-auto max-w-2xl space-y-8 p-8">
        <div>
            <h1 class="text-2xl font-bold">AI Review Kill Switch</h1>
            <p class="text-muted-foreground mt-1 text-sm">
                Workspace: <strong>{{ workspace.name }}</strong>
            </p>
        </div>

        <div
            class="rounded-lg border p-6"
            :class="workspace.kill_switch_enabled ? 'border-red-300 bg-red-50' : 'border-green-300 bg-green-50'"
        >
            <p class="text-lg font-semibold">
                AI reviews are currently
                <span
                    :class="workspace.kill_switch_enabled ? 'text-red-700' : 'text-green-700'"
                >
                    {{ workspace.kill_switch_enabled ? 'PAUSED' : 'ENABLED' }}
                </span>
                for {{ workspace.name }}.
            </p>
            <p v-if="globalKilled" class="mt-2 text-sm font-medium text-red-700">
                Global kill switch is also active (operator-level). Reviews are paused system-wide.
            </p>
        </div>

        <Dialog v-model:open="open">
            <DialogTrigger as-child>
                <Button
                    class="w-full px-8 py-4 text-xl font-bold"
                    :class="workspace.kill_switch_enabled
                        ? 'bg-green-600 hover:bg-green-700 text-white'
                        : 'bg-red-600 hover:bg-red-700 text-white'"
                >
                    {{ workspace.kill_switch_enabled ? 'Resume AI Reviews' : 'Pause AI Reviews' }}
                </Button>
            </DialogTrigger>

            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {{ workspace.kill_switch_enabled ? 'Resume AI reviews?' : 'Pause AI reviews?' }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            workspace.kill_switch_enabled
                                ? 'This will re-enable AI reviews for all new pull requests in this workspace.'
                                : 'This will immediately stop AI reviews for all new pull requests in this workspace. In-flight jobs will check this flag within seconds.'
                        }}
                    </DialogDescription>
                </DialogHeader>

                <div class="mt-2">
                    <label class="mb-1 block text-sm font-medium">Reason (optional)</label>
                    <textarea
                        v-model="reason"
                        class="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                        rows="3"
                        placeholder="Describe why you are toggling the kill switch..."
                        maxlength="500"
                    />
                </div>

                <DialogFooter>
                    <Button variant="outline" @click="open = false">Cancel</Button>

                    <Form
                        :action="killSwitchUpdateUrl()"
                        method="put"
                        #default="{ processing }"
                    >
                        <input type="hidden" name="kill_switch_enabled" :value="workspace.kill_switch_enabled ? '0' : '1'" />
                        <input type="hidden" name="reason" :value="reason" />
                        <Button
                            type="submit"
                            :disabled="processing"
                            :class="workspace.kill_switch_enabled
                                ? 'bg-green-600 hover:bg-green-700 text-white'
                                : 'bg-red-600 hover:bg-red-700 text-white'"
                        >
                            {{ processing ? 'Saving...' : (workspace.kill_switch_enabled ? 'Resume Reviews' : 'Pause Reviews') }}
                        </Button>
                    </Form>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <div class="text-muted-foreground rounded-lg border p-4 text-sm">
            <p class="font-medium">Audit log</p>
            <p class="mt-1">Audit log available in v0.2.</p>
        </div>
    </div>
</template>
