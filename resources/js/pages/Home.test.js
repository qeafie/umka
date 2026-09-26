import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import Home from './Home.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: { render: () => null },
}));

describe('Home', () => {
    it('shows the application name supplied by Laravel', () => {
        const wrapper = mount(Home, {
            props: { appName: 'Тестовый дом' },
        });

        expect(wrapper.get('h1').text()).toBe('Тестовый дом');
    });

    it('makes clear that the application is still in development', () => {
        const wrapper = mount(Home, {
            props: { appName: 'Пульс дома' },
        });

        expect(wrapper.text()).toContain('Приложение в разработке');
    });
});
