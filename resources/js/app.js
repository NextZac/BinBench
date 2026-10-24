import { createApp } from 'vue';
import { createPinia } from 'pinia';
import { createRouter, createWebHistory } from 'vue-router';
import App from './App.vue';
import FilesPage from './pages/FilesPage.vue';
import EditorPage from './pages/EditorPage.vue';

const router = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/', name: 'files', component: FilesPage },
        { path: '/files/:id', name: 'editor', component: EditorPage, props: true },
    ],
});

createApp(App).use(createPinia()).use(router).mount('#app');
