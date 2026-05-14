<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{
    workspace: { id: number; name: string; slug: string };
    reviews: object;
    filters: object;
}>();
</script>

<template>
    <Head title="Reviews" />
    <div>
        <h1>Reviews</h1>
    </div>
</template>
