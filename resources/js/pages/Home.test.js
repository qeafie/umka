import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Home from './Home.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: { render: () => null },
}));

describe('Home', () => {
    const emergencyGuides = [
        { id: 'water', title: 'Прорвало воду', summary: 'Что сделать в первую очередь' },
        { id: 'electricity', title: 'Нет света или искрит', summary: 'Как действовать безопасно' },
        { id: 'gas', title: 'Пахнет газом', summary: 'Сначала выйдите в безопасное место' },
        { id: 'heating', title: 'Нет отопления', summary: 'Куда сообщить о проблеме' },
    ];

    beforeEach(() => {
        vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
    });

    afterEach(() => {
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
    });

    it('shows the application name and the selected emergency guides', () => {
        const wrapper = mount(Home, {
            props: { appName: 'Умка', emergencyGuides },
        });

        expect(wrapper.get('.brand span:last-child').text()).toBe('Умка');
        expect(wrapper.get('.brand-mark').element.tagName).toBe('IMG');
        expect(wrapper.get('.page-intro .eyebrow').text()).toBe('Удобный мобильный коммунальный ассистент');
        expect(wrapper.text()).toContain('Прорвало воду');
        expect(wrapper.text()).toContain('Нет света или искрит');
        expect(wrapper.text()).toContain('Пахнет газом');
        expect(wrapper.text()).toContain('Нет отопления');
    });

    it('opens the request form for drafting a message to housing services', async () => {
        const wrapper = mount(Home, {
            props: { appName: 'Умка', emergencyGuides },
        });

        await wrapper.get('[data-test="open-request-form"]').trigger('click');

        expect(wrapper.text()).toContain('Составить обращение');
        expect(wrapper.get('textarea[name="details"]')).toBeTruthy();
    });

    it('shows a step-by-step guide when a resident chooses an emergency', async () => {
        const wrapper = mount(Home, {
            props: {
                appName: 'Умка',
                emergencyGuides: [
                    {
                        ...emergencyGuides[0],
                        alert: 'Сначала отойдите в сухое безопасное место.',
                        steps: [{ title: 'Уведите людей от воды', description: 'Не заходите к мокрым электроприборам.' }],
                    },
                ],
            },
        });

        await wrapper.get('[data-test="guide-water"]').trigger('click');

        expect(wrapper.text()).toContain('Сначала отойдите в сухое безопасное место.');
        expect(wrapper.text()).toContain('Уведите людей от воды');
    });

    it('shows the generated draft after a resident submits the request form', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                draft: 'Обращение по теме: Прорвало воду',
                deliveryStatus: 'draft_only',
            }),
        }));
        const wrapper = mount(Home, {
            props: { appName: 'Умка', emergencyGuides },
        });

        await wrapper.get('[data-test="open-request-form"]').trigger('click');
        await wrapper.get('[name="address"]').setValue('Казань, улица Примерная, дом 10');
        await wrapper.get('[name="details"]').setValue('В ванной комнате протекает труба под раковиной.');
        await wrapper.get('form').trigger('submit');

        expect(fetch).toHaveBeenCalledWith('/appeals/preview', expect.objectContaining({ method: 'POST' }));
        expect(wrapper.get('[data-test="draft-result"]').text()).toContain('Обращение по теме: Прорвало воду');
        expect(wrapper.get('[data-test="delivery-status"]').text()).toContain('привычный канал связи');
    });

    it('shows a loading status and draft skeleton while preparing the request', async () => {
        let finishRequest;
        vi.stubGlobal('fetch', vi.fn(() => new Promise((resolve) => { finishRequest = resolve; })));
        const wrapper = mount(Home, {
            props: { appName: 'Умка', emergencyGuides },
        });

        await wrapper.get('[data-test="open-request-form"]').trigger('click');
        await wrapper.get('[name="address"]').setValue('Казань, улица Примерная, дом 10');
        await wrapper.get('[name="details"]').setValue('Протекает труба под раковиной.');
        const submission = wrapper.get('form').trigger('submit');
        await wrapper.vm.$nextTick();

        expect(wrapper.get('[data-test="submit-status"]').attributes('role')).toBe('status');
        expect(wrapper.get('[data-test="submit-request"]').element.disabled).toBe(true);
        expect(wrapper.get('[data-test="draft-skeleton"]').attributes('aria-busy')).toBe('true');

        finishRequest({ ok: true, json: async () => ({ draft: 'Готово', deliveryStatus: 'draft_only' }) });
        await submission;
    });

    it('explains when emergency guides are not available', () => {
        const wrapper = mount(Home, {
            props: { appName: 'Умка', emergencyGuides: [] },
        });

        expect(wrapper.get('[data-test="guides-empty"]').text()).toContain('Инструкции временно недоступны');
    });

    it('establishes a resident session from signed MAX launch data', async () => {
        const csrfMeta = document.createElement('meta');
        csrfMeta.name = 'csrf-token';
        csrfMeta.content = 'initial-token';
        document.head.append(csrfMeta);
        vi.stubGlobal('WebApp', { initData: 'signed-max-launch-data' });
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ csrfToken: 'rotated-token', user: { name: 'Анна Иванова', role: 'resident' } }),
        }));

        const wrapper = mount(Home, {
            props: { appName: 'Умка', emergencyGuides },
        });
        await flushPromises();

        expect(fetch).toHaveBeenCalledWith('/auth/max', expect.objectContaining({ method: 'POST' }));
        expect(wrapper.get('[data-test="resident-name"]').text()).toBe('Анна Иванова');
        expect(csrfMeta.content).toBe('rotated-token');
        csrfMeta.remove();
    });

    it('lets an authenticated resident add a meter and save a reading locally', async () => {
        const meter = {
            id: 7,
            name: 'Холодная вода, ванная',
            service: 'cold_water',
            serviceLabel: 'Холодная вода',
            unit: 'м³',
            serialNumber: 'ХВ-2048',
            readings: [],
        };
        vi.stubGlobal('fetch', vi.fn(async (url, options = {}) => {
            if (url === '/meters' && options.method === 'POST') {
                return { ok: true, status: 201, json: async () => ({ meter }) };
            }

            if (url === '/meters/7/readings') {
                return {
                    ok: true,
                    status: 201,
                    json: async () => ({ reading: { value: '124.375', recordedAt: '2026-09-27T10:00:00Z', status: 'saved_locally' } }),
                };
            }

            return { ok: true, status: 200, json: async () => ({ meters: [] }) };
        }));
        const wrapper = mount(Home, {
            props: { appName: 'Умка', emergencyGuides, resident: { name: 'Анна Иванова', role: 'resident' } },
        });

        await wrapper.get('[data-test="open-meters"]').trigger('click');
        await flushPromises();
        expect(wrapper.get('[data-test="meters-empty"]').exists()).toBe(true);

        await wrapper.get('[name="meter-name"]').setValue('Холодная вода, ванная');
        await wrapper.get('[name="meter-service"]').setValue('cold_water');
        await wrapper.get('[data-test="add-meter"]').trigger('submit');
        await flushPromises();
        expect(wrapper.get('[data-test="meter-7"]').text()).toContain('Холодная вода, ванная');

        await wrapper.get('[name="reading-7"]').setValue('124.375');
        await wrapper.get('.reading-form').trigger('submit');
        await flushPromises();

        expect(fetch).toHaveBeenCalledWith('/meters/7/readings', expect.objectContaining({ method: 'POST' }));
        expect(wrapper.get('[data-test="meter-7"]').text()).toContain('124.375');
        expect(wrapper.text()).toContain('Сохранено в приложении');
    });

    it('explains that meter history requires a MAX resident session', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(Home, {
            props: { appName: 'Умка', emergencyGuides },
        });

        await wrapper.get('[data-test="open-meters"]').trigger('click');

        expect(wrapper.get('[data-test="meter-error"]').text()).toContain('откройте приложение через MAX');
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('lets a resident open the housing incidents screen and see a house problem', async () => {
        vi.stubGlobal('fetch', vi.fn(async (url) => {
            if (url === '/my/houses') {
                return { ok: true, json: async () => ({ houses: [{ id: 4, name: 'Дом на Примерной', address: 'ул. Примерная, 10', role: 'resident' }] }) };
            }

            return {
                ok: true,
                json: async () => ({ incidents: [{ id: 9, issueType: 'water', location: 'Подъезд 2', status: 'reported', reportCount: 3, updatedAt: '2026-09-27T10:00:00Z' }] }),
            };
        }));
        const wrapper = mount(Home, {
            props: { appName: 'Умка', emergencyGuides, resident: { name: 'Анна Иванова', role: 'resident' } },
        });

        await wrapper.get('[data-test="open-incidents"]').trigger('click');
        await flushPromises();

        expect(fetch).toHaveBeenCalledWith('/my/houses', expect.any(Object));
        expect(fetch).toHaveBeenCalledWith('/houses/4/incidents', expect.any(Object));
        expect(wrapper.get('[data-test="incidents-house-title"]').text()).toContain('Дом на Примерной');
        expect(wrapper.get('[data-test="incident-9"]').text()).toContain('3 сообщения');
    });

    it('submits a report about a problem from the incident screen', async () => {
        vi.stubGlobal('fetch', vi.fn(async (url, options = {}) => {
            if (url === '/my/houses') {
                return { ok: true, json: async () => ({ houses: [{ id: 4, name: 'Дом на Примерной', address: 'ул. Примерная, 10', role: 'resident' }] }) };
            }

            if (url === '/houses/4/incidents' && options.method === 'POST') {
                return { ok: true, status: 201, json: async () => ({ incident: { id: 9 } }) };
            }

            return { ok: true, json: async () => ({ incidents: [] }) };
        }));
        const wrapper = mount(Home, {
            props: { appName: 'Умка', emergencyGuides, resident: { name: 'Анна Иванова', role: 'resident' } },
        });

        await wrapper.get('[data-test="open-incidents"]').trigger('click');
        await flushPromises();
        await wrapper.get('[name="incident-location"]').setValue('Подъезд 2');
        await wrapper.get('[name="incident-details"]').setValue('Нет горячей воды с утра.');
        await wrapper.get('[data-test="submit-incident"]').trigger('submit');
        await flushPromises();

        expect(fetch).toHaveBeenCalledWith('/houses/4/incidents', expect.objectContaining({ method: 'POST' }));
        expect(wrapper.get('[data-test="incident-feedback"]').text()).toContain('Сообщение сохранено');
    });

    it('lets a dispatcher update work details from an incident card', async () => {
        const incident = { id: 9, issueType: 'water', location: 'Подъезд 2', status: 'in_progress', reportCount: 2, reports: [], recovery: null };
        vi.stubGlobal('fetch', vi.fn(async (url, options = {}) => {
            if (url === '/my/houses') return { ok: true, json: async () => ({ houses: [{ id: 4, name: 'Дом', address: 'ул. Тестовая, 1', role: 'dispatcher' }] }) };
            if (url === '/houses/4/incidents' && options.method === 'PATCH') return { ok: true, json: async () => ({ incident }) };
            return { ok: true, json: async () => ({ incidents: [incident] }) };
        }));
        const wrapper = mount(Home, { props: { appName: 'Умка', emergencyGuides, resident: { name: 'Диспетчер', role: 'dispatcher' } } });

        await wrapper.get('[data-test="open-incidents"]').trigger('click');
        await flushPromises();
        await wrapper.get('[data-test="dispatcher-controls-9"] [name="assignedTo"]').setValue('Аварийная служба');
        await wrapper.get('[data-test="dispatcher-controls-9"] [name="nextAction"]').setValue('Проверить узел подачи воды');
        await wrapper.get('[data-test="dispatcher-controls-9"] [name="nextUpdateAt"]').setValue('2026-09-27T18:00');
        await wrapper.get('[data-test="save-work-9"]').trigger('submit');
        await flushPromises();

        expect(fetch).toHaveBeenCalledWith('/houses/4/incidents/9', expect.objectContaining({ method: 'PATCH' }));
        expect(wrapper.get('[data-test="incident-feedback"]').text()).toContain('Обновление сохранено');
    });

    it('shows scope answers to the dispatcher alongside the work form', async () => {
        const incident = {
            id: 9, issueType: 'water', location: 'Подъезд 2', status: 'in_progress', reportCount: 2,
            scopeResponses: { problemPresent: 2, serviceWorking: 3, cannotCheck: 1, total: 6, staleResponses: 4 },
        };
        vi.stubGlobal('fetch', vi.fn(async (url) => ({
            ok: true,
            json: async () => url === '/my/houses'
                ? { houses: [{ id: 4, name: 'Дом', address: 'ул. Тестовая, 1', role: 'dispatcher' }] }
                : { incidents: [incident] },
        })));
        const wrapper = mount(Home, { props: { appName: 'Умка', emergencyGuides, resident: { name: 'Диспетчер', role: 'dispatcher' } } });

        await wrapper.get('[data-test="open-incidents"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-test="dispatcher-controls-9"]').exists()).toBe(true);
        const summary = wrapper.get('[data-test="scope-check-9"]');
        expect(summary.text()).toContain('Проблема есть: 2');
        expect(summary.text()).toContain('Услуга работает: 3');
        expect(summary.text()).toContain('Не могут проверить: 1');
        expect(summary.text()).toContain('устарели и требуют проверки: 4');
        expect(summary.findAll('button')).toHaveLength(0);
    });

    it.each(['resident', 'dispatcher', 'moderator', 'house_admin'])('shows the house map and limits settings to house administrators (%s)', async (role) => {
        const houseMap = {
            stage: 'scope',
            areas: [{ entrance: 2, floor: 3, participants: 1, problem: 1, working: 0, cannotCheck: 0, noResponse: 0, stale: 0, status: 'problem' }],
            unlocated: { participants: 0 },
        };
        vi.stubGlobal('fetch', vi.fn(async (url) => ({
            ok: true,
            json: async () => url === '/my/houses'
                ? { houses: [{ id: 4, name: 'Дом', address: 'ул. Тестовая, 1', role, layout: [{ entrance: 2, floors: 3 }] }] }
                : { incidents: [{ id: 9, issueType: 'water', location: 'Подъезд 2', status: 'in_progress', reportCount: 1, houseMap }] },
        })));
        const wrapper = mount(Home, { props: { appName: 'Умка', emergencyGuides, resident: { name: 'Участник', role } } });

        await wrapper.get('[data-test="open-incidents"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-test="floor-2-3"]').text()).toContain('Есть проблема');
        expect(wrapper.find('[data-test="configure-house-map"]').exists()).toBe(role === 'house_admin');
        expect(wrapper.find('[data-test="manage-house-members"]').exists()).toBe(role === 'house_admin');
    });

    it('opens member management for the house administrator and refreshes incidents after a role change', async () => {
        vi.stubGlobal('fetch', vi.fn(async (url, options = {}) => ({
            ok: true,
            json: async () => {
                if (url === '/my/houses') return { houses: [{ id: 4, name: 'Дом', role: 'house_admin' }] };
                if (options.method === 'PUT') return { member: { id: 7, role: 'dispatcher' } };
                if (url === '/admin/houses/4/members') return { members: [{ id: 7, name: 'Анна', role: 'resident', isCurrentUser: false }] };
                return { incidents: [] };
            },
        })));
        const wrapper = mount(Home, { props: { appName: 'Умка', emergencyGuides, resident: { name: 'Администратор' } } });
        await wrapper.get('[data-test="open-incidents"]').trigger('click');
        await flushPromises();
        await wrapper.get('[data-test="manage-house-members"]').trigger('click');
        await flushPromises();

        await wrapper.get('[data-test="member-access-7"] select').setValue('dispatcher');
        await wrapper.get('[data-test="member-access-7"] form').trigger('submit');
        await flushPromises();

        expect(fetch.mock.calls.filter(([url]) => url === '/houses/4/incidents')).toHaveLength(2);
        expect(wrapper.text()).toContain('Роль сохранена');
    });

    it('shows recovery answers and missing confirmations to the dispatcher', async () => {
        const incident = {
            id: 9, issueType: 'water', location: 'Подъезд 2', status: 'work_completed', reportCount: 10,
            recovery: { round: 1, totalReports: 10, restored: 6, problemRemains: 2, cannotCheck: 1, noResponse: 1, staleResponses: 1 },
            canConfirmRecovery: false,
        };
        vi.stubGlobal('fetch', vi.fn(async (url) => ({
            ok: true,
            json: async () => url === '/my/houses'
                ? { houses: [{ id: 4, name: 'Дом', address: 'ул. Тестовая, 1', role: 'dispatcher' }] }
                : { incidents: [incident] },
        })));
        const wrapper = mount(Home, { props: { appName: 'Умка', emergencyGuides, resident: { name: 'Диспетчер', role: 'dispatcher' } } });

        await wrapper.get('[data-test="open-incidents"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-test="dispatcher-controls-9"]').exists()).toBe(true);
        const summary = wrapper.get('[data-test="recovery-check-9"]');
        expect(summary.text()).toContain('Свежие подтверждения: 6');
        expect(summary.text()).toContain('проблема сохраняется: 2');
        expect(summary.text()).toContain('Не могут проверить: 1');
        expect(summary.text()).toContain('ждём свежих ответов: 1');
        expect(summary.findAll('button')).toHaveLength(0);
    });

    it('lets a resident answer a service check and recovery check', async () => {
        const incident = {
            id: 9, issueType: 'water', location: 'Подъезд 2', status: 'work_completed', reportCount: 1,
            recovery: { round: 1, totalReports: 1, restored: 0, problemRemains: 0, noResponse: 1 },
            canConfirmRecovery: true,
        };
        vi.stubGlobal('fetch', vi.fn(async (url, options = {}) => {
            if (url === '/my/houses') return { ok: true, json: async () => ({ houses: [{ id: 4, name: 'Дом', address: 'ул. Тестовая, 1', role: 'resident' }] }) };
            if (url.endsWith('/responses')) return { ok: true, status: 201, json: async () => ({ response: { stage: 'recovery', answer: 'restored' } }) };
            return { ok: true, json: async () => ({ incidents: [incident] }) };
        }));
        const wrapper = mount(Home, { props: { appName: 'Умка', emergencyGuides, resident: { name: 'Житель', role: 'resident' } } });

        await wrapper.get('[data-test="open-incidents"]').trigger('click');
        await flushPromises();
        expect(wrapper.get('[data-test="recovery-check-9"]').text()).toContain('Работы завершены');
        await wrapper.get('[data-test="confirm-restored-9"]').trigger('click');
        await flushPromises();

        expect(fetch).toHaveBeenCalledWith('/houses/4/incidents/9/responses', expect.objectContaining({ method: 'POST' }));
        expect(wrapper.get('[data-test="incident-feedback"]').text()).toContain('Ответ сохранён');
    });

    it('distinguishes fresh resident checks from stale answers', async () => {
        const incident = {
            id: 9, issueType: 'water', location: 'Подъезд 2', status: 'in_progress', reportCount: 2,
            scopeResponses: { total: 1, staleResponses: 1 },
            myScopeResponse: 'problem_present', myScopeResponseIsFresh: false,
        };
        vi.stubGlobal('fetch', vi.fn(async (url) => {
            if (url === '/my/houses') return { ok: true, json: async () => ({ houses: [{ id: 4, name: 'Дом', address: 'ул. Тестовая, 1', role: 'resident' }] }) };
            return { ok: true, json: async () => ({ incidents: [incident] }) };
        }));
        const wrapper = mount(Home, { props: { appName: 'Умка', emergencyGuides, resident: { name: 'Житель', role: 'resident' } } });

        await wrapper.get('[data-test="open-incidents"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-test="scope-check-9"]').text()).toContain('Свежих ответов: 1');
        expect(wrapper.get('[data-test="scope-check-9"]').text()).toContain('устарели и требуют проверки: 1');
        expect(wrapper.get('[data-test="scope-check-9"]').text()).toContain('обновите ответ');
    });
});
