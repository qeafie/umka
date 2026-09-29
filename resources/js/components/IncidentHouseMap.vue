<script setup>
import { computed, ref } from 'vue';

const props = defineProps({ map: { type: Object, required: true } });
const selectedKey = ref(null);
const entrances = computed(() => {
    const groups = new Map();
    for (const area of props.map.areas) {
        if (!groups.has(area.entrance)) groups.set(area.entrance, []);
        groups.get(area.entrance).push(area);
    }
    return [...groups.entries()].sort(([a], [b]) => a - b).map(([number, floors]) => ({
        number, floors: [...floors].sort((a, b) => b.floor - a.floor),
    }));
});
const selectedArea = computed(() => selectedKey.value === 'unlocated'
    ? props.map.unlocated
    : props.map.areas.find((area) => `${area.entrance}:${area.floor}` === selectedKey.value));
const statusLabels = {
    problem: 'Есть проблема', mixed: 'Ответы различаются', confirmed: 'Участники подтвердили',
    partial: 'Подтверждено частично', unknown: 'Нет свежих подтверждений',
};
</script>

<template>
    <section class="house-map" aria-label="Состояние по подъездам и этажам">
        <div class="house-map-heading">
            <h4>Состояние по этажам</h4>
            <p>{{ map.stage === 'recovery' ? 'Проверка после завершения работ' : 'Текущие ответы об этой проблеме' }}</p>
        </div>
        <p class="form-note">Ответы подключённых участников не означают, что проверены все квартиры. Нажмите на этаж, чтобы увидеть подробности.</p>
        <p v-if="!entrances.length" class="form-note">Схема дома ещё не настроена. Администратор может добавить подъезды и этажи.</p>
        <div v-else class="house-map-entrances">
            <div v-for="entrance in entrances" :key="entrance.number" class="house-map-entrance">
                <h5>Подъезд {{ entrance.number }}</h5>
                <button
                    v-for="area in entrance.floors" :key="area.floor" type="button"
                    class="house-map-floor" :class="`map-${area.status}`"
                    :data-test="`floor-${area.entrance}-${area.floor}`"
                    :aria-pressed="selectedKey === `${area.entrance}:${area.floor}`"
                    @click="selectedKey = `${area.entrance}:${area.floor}`"
                >
                    <strong>Этаж {{ area.floor }}</strong>
                    <span>{{ statusLabels[area.status] ?? statusLabels.unknown }}</span>
                </button>
            </div>
        </div>
        <button v-if="map.unlocated?.participants" type="button" class="text-button" data-test="unlocated-answers" :aria-pressed="selectedKey === 'unlocated'" @click="selectedKey = 'unlocated'">
            Без привязки к этажу · участников: {{ map.unlocated.participants }}
        </button>
        <div v-if="selectedArea" class="house-map-details" data-test="area-details" aria-live="polite">
            <h5>{{ selectedArea.entrance === null ? 'Без привязки к этажу' : `Подъезд ${selectedArea.entrance} · Этаж ${selectedArea.floor}` }}</h5>
            <dl>
                <div><dt>Участников:</dt> <dd>{{ selectedArea.participants }}</dd></div>
                <div><dt>Проблема сохраняется:</dt> <dd>{{ selectedArea.problem }}</dd></div>
                <div><dt>Услуга работает:</dt> <dd>{{ selectedArea.working }}</dd></div>
                <div><dt>Не могут проверить:</dt> <dd>{{ selectedArea.cannotCheck }}</dd></div>
                <div><dt>Ждём свежих ответов:</dt> <dd>{{ selectedArea.noResponse }}</dd></div>
                <div><dt>Устаревших ответов:</dt> <dd>{{ selectedArea.stale }}</dd></div>
            </dl>
            <p class="form-note">{{ selectedArea.lastCheckedAt ? `Последний ответ: ${new Date(selectedArea.lastCheckedAt).toLocaleString('ru-RU')}` : 'Ответов пока нет.' }}</p>
        </div>
    </section>
</template>
