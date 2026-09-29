import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import HouseMemberAccess from './HouseMemberAccess.vue';

const house = { id: 4, name: 'Тестовый дом' };
const members = [
    { id: 1, name: 'Администратор', role: 'house_admin', isCurrentUser: true },
    { id: 7, name: 'Анна', role: 'resident', isCurrentUser: false },
];
const response = (body, ok = true) => ({ ok, status: ok ? 200 : 422, json: async () => body });
const memberRow = (wrapper) => wrapper.get('[data-test="member-access-7"]');

afterEach(() => vi.unstubAllGlobals());

describe('HouseMemberAccess', () => {
    it('shows current roles and prevents changing or removing the current administrator', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => response({ members })));
        const wrapper = mount(HouseMemberAccess, { props: { house } });
        await flushPromises();

        expect(memberRow(wrapper).text()).toContain('Текущая роль: Житель');
        const current = wrapper.get('[data-test="member-access-1"]');
        expect(current.text()).toContain('Это вы');
        expect(current.find('form').exists()).toBe(false);
        expect(current.find('button').exists()).toBe(false);
        expect(memberRow(wrapper).findAll('option').map((option) => option.element.value))
            .toEqual(['resident', 'dispatcher', 'moderator']);
    });

    it('saves the selected role with CSRF protection and refreshes the parent', async () => {
        const meta = document.createElement('meta');
        meta.name = 'csrf-token';
        meta.content = 'test-token';
        document.head.append(meta);
        vi.stubGlobal('fetch', vi.fn(async (url, options = {}) => response(options.method === 'PUT'
            ? { member: { id: 7, role: 'dispatcher' } } : { members })));
        const wrapper = mount(HouseMemberAccess, { props: { house } });
        await flushPromises();

        await memberRow(wrapper).get('select').setValue('dispatcher');
        await memberRow(wrapper).get('form').trigger('submit');
        await flushPromises();

        expect(fetch).toHaveBeenCalledWith('/admin/houses/4/members/7', expect.objectContaining({
            method: 'PUT', body: JSON.stringify({ role: 'dispatcher' }),
            headers: expect.objectContaining({ 'X-CSRF-TOKEN': 'test-token' }),
        }));
        expect(memberRow(wrapper).text()).toContain('Текущая роль: Диспетчер');
        expect(wrapper.get('[role="status"]').text()).toContain('Роль сохранена');
        expect(wrapper.emitted('updated')).toHaveLength(1);
        meta.remove();
    });

    it('requires explicit confirmation, supports cancellation, and accepts an empty deletion response', async () => {
        const readDeletedBody = vi.fn(() => { throw new Error('No response body'); });
        vi.stubGlobal('fetch', vi.fn(async (url, options = {}) => options.method === 'DELETE'
            ? { ok: true, status: 204, json: readDeletedBody } : response({ members })));
        const wrapper = mount(HouseMemberAccess, { props: { house } });
        await flushPromises();

        await memberRow(wrapper).get('[data-test="revoke-access"]').trigger('click');
        expect(fetch).toHaveBeenCalledTimes(1);
        expect(memberRow(wrapper).get('[data-test="revoke-confirmation"]').text()).toContain('Анна');
        await memberRow(wrapper).get('[data-test="cancel-revoke"]').trigger('click');
        expect(memberRow(wrapper).find('[data-test="revoke-confirmation"]').exists()).toBe(false);
        expect(fetch).toHaveBeenCalledTimes(1);

        await memberRow(wrapper).get('[data-test="revoke-access"]').trigger('click');
        await memberRow(wrapper).get('[data-test="confirm-revoke"]').trigger('click');
        await flushPromises();

        expect(fetch).toHaveBeenCalledWith('/admin/houses/4/members/7', expect.objectContaining({ method: 'DELETE' }));
        expect(readDeletedBody).not.toHaveBeenCalled();
        expect(wrapper.find('[data-test="member-access-7"]').exists()).toBe(false);
        expect(wrapper.get('[role="status"]').text()).toContain('Доступ отозван');
        expect(wrapper.emitted('updated')).toHaveLength(1);
    });

    it('keeps the saved role and the draft when the server rejects the change', async () => {
        vi.stubGlobal('fetch', vi.fn(async (url, options = {}) => options.method === 'PUT'
            ? response({ errors: { member: ['Недостаточно прав для изменения роли.'] } }, false)
            : response({ members })));
        const wrapper = mount(HouseMemberAccess, { props: { house } });
        await flushPromises();

        await memberRow(wrapper).get('select').setValue('moderator');
        await memberRow(wrapper).get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toBe('Недостаточно прав для изменения роли.');
        expect(memberRow(wrapper).text()).toContain('Текущая роль: Житель');
        expect(memberRow(wrapper).get('select').element.value).toBe('moderator');
        expect(wrapper.emitted('updated')).toBeUndefined();
    });

    it('keeps the member and confirmation when revoking access fails', async () => {
        vi.stubGlobal('fetch', vi.fn(async (url, options = {}) => {
            if (options.method === 'DELETE') throw new TypeError('Failed to fetch');
            return response({ members });
        }));
        const wrapper = mount(HouseMemberAccess, { props: { house } });
        await flushPromises();

        await memberRow(wrapper).get('[data-test="revoke-access"]').trigger('click');
        await memberRow(wrapper).get('[data-test="confirm-revoke"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain('Проверьте подключение');
        expect(memberRow(wrapper).find('[data-test="revoke-confirmation"]').exists()).toBe(true);
        expect(wrapper.emitted('updated')).toBeUndefined();
    });

    it.each([
        [401, 'Сессия истекла'],
        [419, 'Сессия истекла'],
        [403, 'больше нет прав'],
        [404, 'Участник уже недоступен'],
    ])('explains a rejected action with status %s without changing the saved role', async (status, expectedMessage) => {
        vi.stubGlobal('fetch', vi.fn(async (url, options = {}) => options.method === 'PUT'
            ? { ok: false, status, json: async () => ({}) } : response({ members })));
        const wrapper = mount(HouseMemberAccess, { props: { house } });
        await flushPromises();

        await memberRow(wrapper).get('select').setValue('moderator');
        await memberRow(wrapper).get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain(expectedMessage);
        expect(memberRow(wrapper).text()).toContain('Текущая роль: Житель');
        expect(wrapper.emitted('updated')).toBeUndefined();
    });

    it('allows retrying a failed load and shows an empty state', async () => {
        const fetchMembers = vi.fn().mockRejectedValueOnce(new TypeError('Failed to fetch'))
            .mockResolvedValueOnce(response({ members: [] }));
        vi.stubGlobal('fetch', fetchMembers);
        const wrapper = mount(HouseMemberAccess, { props: { house } });
        expect(wrapper.get('[role="status"]').text()).toContain('Загружаем участников');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain('Проверьте подключение');
        await wrapper.get('[data-test="retry-members"]').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Участников пока нет');
        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
    });

    it('blocks duplicate changes while saving and leaves other member controls disabled', async () => {
        let finishSave;
        vi.stubGlobal('fetch', vi.fn(async (url, options = {}) => options.method === 'PUT'
            ? new Promise((resolve) => { finishSave = resolve; }) : response({ members })));
        const wrapper = mount(HouseMemberAccess, { props: { house } });
        await flushPromises();

        await memberRow(wrapper).get('select').setValue('dispatcher');
        await memberRow(wrapper).get('form').trigger('submit');
        await memberRow(wrapper).get('form').trigger('submit');
        expect(fetch).toHaveBeenCalledTimes(2);
        expect(memberRow(wrapper).get('fieldset').element.disabled).toBe(true);

        finishSave(response({ member: { id: 7, role: 'dispatcher' } }));
        await flushPromises();
        expect(memberRow(wrapper).get('fieldset').element.disabled).toBe(false);
    });

    it('renders names as text rather than HTML', async () => {
        const name = '<img src=x onerror=alert(1)>';
        vi.stubGlobal('fetch', vi.fn(async () => response({ members: [{ ...members[1], name }] })));
        const wrapper = mount(HouseMemberAccess, { props: { house } });
        await flushPromises();

        expect(memberRow(wrapper).text()).toContain(name);
        expect(memberRow(wrapper).find('img').exists()).toBe(false);
    });
});
