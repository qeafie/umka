import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, expect, it, vi } from 'vitest';
import NotificationSettings from './NotificationSettings.vue';
import IncidentSurvey from './IncidentSurvey.vue';
const response = (body, ok = true) => ({ ok, status: ok ? 200 : 422, json: async () => body });
afterEach(() => vi.unstubAllGlobals());
it('does not subscribe without consent and preserves the previous preference on failure', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => response({ message: 'Не удалось сохранить' }, false)));
    const wrapper = mount(NotificationSettings, { props: { house: { id: 1, notificationsEnabled: false } } });
    expect(fetch).not.toHaveBeenCalled();
    await wrapper.get('input').setValue(true);
    await flushPromises();
    expect(fetch).toHaveBeenCalledWith('/houses/1/notifications', expect.objectContaining({ method: 'PUT', body: JSON.stringify({ enabled: true }) }));
    expect(wrapper.get('input').element.checked).toBe(false);
    expect(wrapper.get('[role="alert"]').text()).toContain('Не удалось');
});
it('persists opting out and explains that questions stop', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => response({ enabled: false })));
    const wrapper = mount(NotificationSettings, { props: { house: { id: 1, notificationsEnabled: true } } });
    await wrapper.get('input').setValue(false);
    await flushPromises();
    expect(wrapper.emitted('updated')[0]).toEqual([false]);
    expect(wrapper.get('[role="status"]').text()).toContain('отключены');
});
it('queues the chosen area and distinguishes queued from sent', async () => {
    vi.stubGlobal('fetch', vi.fn(async (url, options) => response(options.method === 'POST' ? { queued: 3 } : { counts: { pending: 3, sent: 0 } })));
    const wrapper = mount(IncidentSurvey, { props: { house: { id: 4, layout: [{ entrance: 2, floors: 5 }] }, incident: { id: 8, status: 'in_progress' } } });
    await wrapper.get('[data-test="toggle-survey"]').trigger('click');
    await flushPromises();
    await wrapper.get('[name="entrance"]').setValue('2');
    await wrapper.get('[name="floorFrom"]').setValue('3');
    await wrapper.get('[name="floorTo"]').setValue('5');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(fetch).toHaveBeenCalledWith('/houses/4/incidents/8/surveys', expect.objectContaining({ method: 'POST', body: JSON.stringify({ entrance: 2, floorFrom: 3, floorTo: 5 }) }));
    expect(wrapper.text()).toContain('В очередь добавлено: 3');
    expect(wrapper.text()).toContain('Отправлено: 0');
});
it('explains missing layout and forbids starting a scope survey after completion', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => response({ counts: {} })));
    const wrapper = mount(IncidentSurvey, { props: { house: { id: 4, layout: [] }, incident: { id: 8, status: 'work_completed' } } });
    await wrapper.get('[data-test="toggle-survey"]').trigger('click');
    await flushPromises();
    expect(wrapper.find('form').exists()).toBe(false);
    expect(wrapper.text()).toContain('проверки восстановления');
});
