<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { BookOpen, FolderGit2, GitPullRequestArrow, LayoutGrid, Layers } from 'lucide-vue-next';
import { computed } from 'vue';
import { index as reviewsIndex } from '@/actions/App/Http/Controllers/Reviews/ReviewController';
import { index as workspacesIndex } from '@/actions/App/Http/Controllers/Workspaces/WorkspaceController';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const page = usePage<{ currentWorkspace: { id: number; name: string; slug: string } | null }>();
const currentWorkspace = computed(() => page.props.currentWorkspace);

const baseNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Workspaces',
        href: workspacesIndex.url(),
        icon: Layers,
    },
];

const mainNavItems = computed<NavItem[]>(() => {
    if (!currentWorkspace.value) {
        return baseNavItems;
    }

    return [
        ...baseNavItems,
        {
            title: 'Reviews',
            href: reviewsIndex.url(currentWorkspace.value),
            icon: GitPullRequestArrow,
        },
    ];
});

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/vue-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#vue',
        icon: BookOpen,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>


        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
