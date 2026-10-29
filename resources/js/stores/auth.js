import { defineStore } from 'pinia';
import { api, setCsrfToken } from '../lib/api';

/**
 * The signed-in user and what the login page offers (registration, invite code).
 */
export const useAuth = defineStore('auth', {
    state: () => ({
        loaded: false,
        user: null,
        registration: false,
        inviteRequired: false,
    }),

    actions: {
        apply(res) {
            this.user = res.user;
            this.registration = res.registration;
            this.inviteRequired = res.inviteRequired;
            this.loaded = true;
            setCsrfToken(res.csrfToken);
        },

        async load() {
            if (!this.loaded) this.apply(await api.auth());
        },

        async login(credentials) {
            this.apply(await api.login(credentials));
        },

        async register(data) {
            this.apply(await api.register(data));
        },

        async logout() {
            this.apply(await api.logout());
        },
    },
});
