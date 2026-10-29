import { createApp } from 'vue';
import { createPinia } from 'pinia';
import { createRouter, createWebHistory } from 'vue-router';
import App from './App.vue';
import FilesPage from './pages/FilesPage.vue';
import EditorPage from './pages/EditorPage.vue';
import LoginPage from './pages/LoginPage.vue';
import { useAuth } from './stores/auth';

const router = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/login', name: 'login', component: LoginPage, meta: { guest: true } },
        { path: '/', name: 'files', component: FilesPage },
        { path: '/files/:id', name: 'editor', component: EditorPage, props: true },
    ],
});

const pinia = createPinia();

router.beforeEach(async (to) => {
    const auth = useAuth(pinia);
    await auth.load();
    if (!auth.user && !to.meta.guest) {
        return { name: 'login', query: to.fullPath === '/' ? {} : { redirect: to.fullPath } };
    }
    if (auth.user && to.meta.guest) {
        return { name: 'files' };
    }
    return true;
});

// any API call that finds the session gone sends the user back to the login page
window.addEventListener('binbench:unauthenticated', () => {
    const auth = useAuth(pinia);
    auth.user = null;
    if (router.currentRoute.value.name !== 'login') {
        router.push({ name: 'login', query: { redirect: router.currentRoute.value.fullPath } });
    }
});

createApp(App).use(pinia).use(router).mount('#app');
