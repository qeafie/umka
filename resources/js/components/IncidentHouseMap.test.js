import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import IncidentHouseMap from './IncidentHouseMap.vue';

const area = (changes = {}) => ({
    entrance: 1, floor: 1, participants: 2, problem: 1, working: 0,
    cannotCheck: 0, noResponse: 1, stale: 0, lastCheckedAt: '2026-09-29T12:00:00Z', status: 'problem', ...changes,
});
const map = (areas) => ({ stage: 'scope', areas, unlocated: area({ entrance: null, floor: null, participants: 0 }) });

describe('IncidentHouseMap', () => {
    it('shows entrances and unknown floors and opens aggregate details on selection', async () => {
        const wrapper = mount(IncidentHouseMap, { props: { map: map([
            area(), area({ floor: 2, problem: 0, working: 1, noResponse: 1, stale: 1, status: 'partial' }),
            area({ entrance: 2, participants: 0, problem: 0, noResponse: 0, lastCheckedAt: null, status: 'unknown' }),
        ]) } });

        expect(wrapper.text()).toContain('Подъезд 1');
        expect(wrapper.text()).toContain('Подъезд 2');
        expect(wrapper.get('[data-test="floor-2-1"]').text()).toContain('Нет свежих подтверждений');
        await wrapper.get('[data-test="floor-1-2"]').trigger('click');

        expect(wrapper.get('[data-test="floor-1-2"]').attributes('aria-pressed')).toBe('true');
        const details = wrapper.get('[data-test="area-details"]');
        expect(details.text()).toContain('Подъезд 1 · Этаж 2');
        expect(details.text()).toContain('Услуга работает: 1');
        expect(details.text()).toContain('Ждём свежих ответов: 1');
        expect(details.text()).toContain('Устаревших ответов: 1');
    });

    it('updates selected floor details when new responses arrive', async () => {
        const wrapper = mount(IncidentHouseMap, { props: { map: map([area()]) } });
        await wrapper.get('[data-test="floor-1-1"]').trigger('click');

        await wrapper.setProps({ map: map([area({ problem: 0, working: 2, noResponse: 0, status: 'confirmed' })]) });

        expect(wrapper.get('[data-test="area-details"]').text()).toContain('Услуга работает: 2');
        expect(wrapper.get('[data-test="floor-1-1"]').text()).toContain('Участники подтвердили');
        expect(wrapper.text()).toContain('не означают, что проверены все квартиры');
    });

    it('shows unlocated answers and explains a missing house layout', async () => {
        const wrapper = mount(IncidentHouseMap, { props: { map: {
            stage: 'recovery', areas: [], unlocated: area({ entrance: null, floor: null, participants: 1, problem: 1, noResponse: 0 }),
        } } });

        expect(wrapper.text()).toContain('Схема дома ещё не настроена');
        expect(wrapper.text()).toContain('Проверка после завершения работ');
        await wrapper.get('[data-test="unlocated-answers"]').trigger('click');
        expect(wrapper.get('[data-test="area-details"]').text()).toContain('Без привязки к этажу');
        expect(wrapper.get('[data-test="area-details"]').text()).toContain('Проблема сохраняется: 1');
    });
});
