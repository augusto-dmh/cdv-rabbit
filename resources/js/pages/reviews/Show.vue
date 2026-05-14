<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{
    workspace: { id: number; name: string; slug: string };
    review: object;
    comments: object[];
    llmCalls: object[];
}>();
</script>

<template>
    <Head title="Review" />
    <div>
        <h1>Review</h1>
    </div>
</template>
