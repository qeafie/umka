<script setup>
import { onMounted, ref } from 'vue';

const props = defineProps({ house: { type: Object, required: true } });
const emit = defineEmits(['updated']);
const layout = ref((props.house.layout ?? []).map((entrance) => ({ ...entrance })));
const savedLayout = ref((props.house.layout ?? []).map((entrance) => ({ ...entrance })));
const members = ref([]);
const loading = ref(true);
const saving = ref(false);
const error = ref('');
const message = ref('');

async function request(path, body) {
    const response = await fetch(path, body === undefined ? { headers: { Accept: 'application/json' } } : {
        method: 'PUT',
        headers: {
            Accept: 'application/json', 'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify(body),
    });
    const result = await response.json();
    if (!response.ok) throw new Error(Object.values(result.errors ?? {}).flat()[0] ?? result.message ?? 'Не удалось сохранить изменения.');
    return result;
}

async function loadMembers() {
    loading.value = true;
    error.value = '';
    try {
        const result = await request(`/admin/houses/${props.house.id}/members`);
        members.value = result.members.map((member) => ({ ...member }));
    } catch (failure) {
        error.value = failure.message || 'Не удалось загрузить участников. Проверьте подключение.';
    } finally {
        loading.value = false;
    }
}

function addEntrance() {
    const number = Math.max(0, ...layout.value.map((entrance) => Number(entrance.entrance) || 0)) + 1;
    layout.value.push({ entrance: number, floors: 1 });
}

async function saveLayout() {
    if (saving.value) return;
    saving.value = true;
    error.value = '';
    message.value = '';
    try {
        const result = await request(`/admin/houses/${props.house.id}/layout`, { layout: layout.value });
        savedLayout.value = result.layout.map((entrance) => ({ ...entrance }));
        layout.value = result.layout.map((entrance) => ({ ...entrance }));
        message.value = 'Схема дома сохранена.';
        emit('updated', result.layout);
    } catch (failure) {
        error.value = failure.message || 'Не удалось сохранить схему.';
    } finally {
        saving.value = false;
    }
}

async function saveLocation(member) {
    if (saving.value) return;
    saving.value = true;
    error.value = '';
    message.value = '';
    try {
        const entrance = member.entrance === '' || member.entrance === null ? null : Number(member.entrance);
        const floor = entrance === null || member.floor === '' || member.floor === null ? null : Number(member.floor);
        const result = await request(`/admin/houses/${props.house.id}/members/${member.id}/location`, { entrance, floor });
        Object.assign(member, result.member);
        message.value = 'Привязка сохранена.';
        emit('updated', savedLayout.value);
    } catch (failure) {
        error.value = failure.message || 'Не удалось сохранить привязку.';
    } finally {
        saving.value = false;
    }
}

function floorsFor(member) {
    return savedLayout.value.find((entrance) => entrance.entrance === Number(member.entrance))?.floors ?? 0;
}

onMounted(loadMembers);
</script>

<template>
    <section class="house-settings" aria-label="Настройка схемы дома">
        <h2>Схема дома и участники</h2>
        <p class="form-note">Укажите фактические подъезды и этажи. Привязки жителей используются для сводки; номера квартир в ней не показываются.</p>
        <p v-if="error" class="meter-feedback meter-feedback-error" role="alert">{{ error }}</p>
        <p v-if="message" class="meter-feedback" role="status">{{ message }}</p>
        <form data-test="layout-form" class="house-layout-form" @submit.prevent="saveLayout">
            <fieldset :disabled="saving">
                <legend>Подъезды</legend>
                <div v-for="(entrance, index) in layout" :key="index" class="house-layout-row">
                    <label class="field">Номер подъезда<input v-model.number="entrance.entrance" :name="`entrance-${index}`" type="number" min="1" max="99" required></label>
                    <label class="field">Этажей<input v-model.number="entrance.floors" :name="`floors-${index}`" type="number" min="1" max="60" required></label>
                    <button type="button" class="text-button" :aria-label="`Убрать подъезд ${entrance.entrance}`" @click="layout.splice(index, 1)">Убрать</button>
                </div>
                <div class="form-footer">
                    <button type="button" class="button button-secondary" :disabled="layout.length >= 20" @click="addEntrance">Добавить подъезд</button>
                    <button type="submit" class="button">Сохранить схему</button>
                </div>
            </fieldset>
        </form>
        <h3>Привязка участников</h3>
        <p v-if="loading" role="status">Загружаем участников…</p>
        <button v-else-if="error && !members.length" class="text-button" type="button" @click="loadMembers">Повторить загрузку</button>
        <p v-else-if="!members.length" class="form-note">Участников пока нет.</p>
        <form v-for="member in members" :key="member.id" :data-test="`member-location-${member.id}`" class="member-location-form" @submit.prevent="saveLocation(member)">
            <fieldset :disabled="saving">
                <legend>{{ member.name }}</legend>
                <div class="house-layout-row">
                    <label class="field">Подъезд
                        <select v-model="member.entrance" :name="`member-entrance-${member.id}`" @change="member.floor = null">
                            <option :value="null">Не указан</option>
                            <option v-for="entrance in savedLayout" :key="entrance.entrance" :value="entrance.entrance">{{ entrance.entrance }}</option>
                        </select>
                    </label>
                    <label class="field">Этаж
                        <select v-model="member.floor" :name="`member-floor-${member.id}`" :disabled="!member.entrance" :required="Boolean(member.entrance)">
                            <option :value="null">Не указан</option>
                            <option v-for="floor in floorsFor(member)" :key="floor" :value="floor">{{ floor }}</option>
                        </select>
                    </label>
                    <button type="submit" class="button button-secondary">Сохранить привязку</button>
                </div>
            </fieldset>
        </form>
    </section>
</template>
