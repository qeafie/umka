import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, expect, it, vi } from 'vitest';
import HouseInvitations from './HouseInvitations.vue';
import JoinHouse from './JoinHouse.vue';

const response = (data, ok = true) => ({ ok, status: ok ? 200 : 422, json: async () => data });
afterEach(() => vi.unstubAllGlobals());

it('creates an invitation with an explicit location and supports revocation', async () => {
    const invitation = { id: 8, apartment: '24', entrance: 2, floor: 4, status: 'pending', expiresAt: '2026-10-03T10:00:00Z' };
    vi.stubGlobal('fetch', vi.fn(async (url, options = {}) => {
        if (options.method === 'POST') return response({ invitation, token: 'a'.repeat(64), url: null });
        if (options.method === 'DELETE') return { ok: true, status: 204 };
        return response({ invitations: [] });
    }));
    const wrapper = mount(HouseInvitations, { props: { house: { id: 4, layout: [{ entrance: 2, floors: 5 }] } } });
    await flushPromises();
    await wrapper.get('[name="apartment"]').setValue('24');
    await wrapper.get('[name="entrance"]').setValue('2');
    await wrapper.get('[name="floor"]').setValue('4');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(fetch).toHaveBeenCalledWith('/admin/houses/4/invitations', expect.objectContaining({ method: 'POST', body: JSON.stringify({ apartment: '24', entrance: 2, floor: 4 }) }));
    expect(wrapper.get('[data-test="invitation-secret"]').element.value).toBe('a'.repeat(64));
    await wrapper.get('[data-test="revoke-invitation"]').trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('Отозвано');
    expect(wrapper.find('[data-test="invitation-secret"]').exists()).toBe(false);
});

it('requires login and explicit confirmation after showing the destination house', async () => {
    vi.stubGlobal('fetch', vi.fn(async (url) => response(url.endsWith('preview') ? { invitation: { house: { name: 'Дом 12', address: 'Лесная 12' }, apartment: '24' } } : { house: { id: 4 } })));
    const wrapper = mount(JoinHouse, { props: { authenticated: false, initialToken: 'b'.repeat(64) } });
    expect(wrapper.text()).toContain('MAX');
    expect(fetch).not.toHaveBeenCalled();
    await wrapper.setProps({ authenticated: true });
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.text()).toContain('Лесная 12');
    expect(fetch).toHaveBeenCalledTimes(1);
    await wrapper.get('[data-test="accept-invitation"]').trigger('click');
    await flushPromises();
    expect(wrapper.emitted('joined')[0][0]).toEqual({ id: 4 });
});

it('keeps the code and explains an expired invitation without claiming success', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => response({ errors: { token: ['Приглашение истекло.'] } }, false)));
    const wrapper = mount(JoinHouse, { props: { authenticated: true, initialToken: 'b'.repeat(64) } });
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toContain('истекло');
    expect(wrapper.get('input').element.value).toBe('b'.repeat(64));
    expect(wrapper.emitted('joined')).toBeUndefined();
});
