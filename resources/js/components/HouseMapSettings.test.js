import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import HouseMapSettings from './HouseMapSettings.vue';

const house = { id: 4, layout: [{ entrance: 1, floors: 3 }] };
const members = [{ id: 7, name: 'Анна', role: 'resident', entrance: null, floor: null }];

afterEach(() => vi.unstubAllGlobals());

describe('HouseMapSettings', () => {
    it('lets the administrator save a layout and assign a resident to a floor', async () => {
        vi.stubGlobal('fetch', vi.fn(async (url, options = {}) => ({
            ok: true,
            json: async () => options.method === 'PUT'
                ? { layout: [{ entrance: 1, floors: 5 }], member: { id: 7, entrance: 1, floor: 4 } }
                : { members },
        })));
        const wrapper = mount(HouseMapSettings, { props: { house } });
        await flushPromises();

        await wrapper.get('[name="floors-0"]').setValue(5);
        await wrapper.get('[data-test="layout-form"]').trigger('submit');
        await flushPromises();
        expect(fetch).toHaveBeenCalledWith('/admin/houses/4/layout', expect.objectContaining({
            method: 'PUT', body: JSON.stringify({ layout: [{ entrance: 1, floors: 5 }] }),
        }));
        expect(wrapper.emitted('updated')[0][0]).toEqual([{ entrance: 1, floors: 5 }]);

        await wrapper.get('[name="member-entrance-7"]').setValue('1');
        await wrapper.get('[name="member-floor-7"]').setValue('4');
        await wrapper.get('[data-test="member-location-7"]').trigger('submit');
        await flushPromises();
        expect(fetch).toHaveBeenCalledWith('/admin/houses/4/members/7/location', expect.objectContaining({
            method: 'PUT', body: JSON.stringify({ entrance: 1, floor: 4 }),
        }));
        expect(wrapper.text()).toContain('Привязка сохранена');
    });

    it('preserves the draft and reports a rejected layout without emitting success', async () => {
        vi.stubGlobal('fetch', vi.fn(async (url, options = {}) => ({
            ok: options.method !== 'PUT',
            json: async () => options.method === 'PUT'
                ? { message: 'Этаж используется жителями', errors: { layout: ['Сначала измените привязку жителей.'] } }
                : { members },
        })));
        const wrapper = mount(HouseMapSettings, { props: { house } });
        await flushPromises();

        await wrapper.get('[name="floors-0"]').setValue(1);
        await wrapper.get('[data-test="layout-form"]').trigger('submit');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain('Сначала измените привязку жителей.');
        expect(wrapper.get('[name="floors-0"]').element.value).toBe('1');
        expect(wrapper.emitted('updated')).toBeUndefined();
    });
});
