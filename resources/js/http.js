// The Control Panel's own axios instance, with the CSRF token already set.
export function http() {
    return Statamic.$app.config.globalProperties.$axios;
}
