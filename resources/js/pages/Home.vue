<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import IncidentHouseMap from '../components/IncidentHouseMap.vue';
import HouseMapSettings from '../components/HouseMapSettings.vue';
import HouseMemberAccess from '../components/HouseMemberAccess.vue';
import JoinHouse from '../components/JoinHouse.vue';
const invitationToken = ref('');

const props = defineProps({
    appName: { type: String, required: true },
    emergencyGuides: { type: Array, required: true },
    resident: { type: Object, default: null },
});

const activeView = ref('home');
const selectedGuideId = ref(null);
const draft = ref('');
const deliveryStatus = ref('');
const copyMessage = ref('');
const errors = ref({});
const isSubmitting = ref(false);
const currentResident = ref(props.resident);
const meters = ref([]);
const isLoadingMeters = ref(false);
const isSavingMeter = ref(false);
const meterMessage = ref('');
const meterError = ref('');
const readingValues = reactive({});
const readingErrors = reactive({});
const savingReadings = reactive({});
const meterForm = reactive({ name: '', service: 'cold_water', serialNumber: '' });
const houses = ref([]);
const houseIncidents = ref([]);
const selectedHouse = ref(null);
const showHouseSettings = ref(false);
const showHouseAccess = ref(false);
const isLoadingIncidents = ref(false);
const isSavingIncident = ref(false);
const incidentFeedback = ref('');
const incidentError = ref('');
const incidentErrors = ref({});
const incidentForm = reactive({ issueType: 'water', location: '', details: '', incidentId: null });
const incidentWork = reactive({});
const isSavingWorkflow = ref(false);
const form = reactive({
    issueType: 'water',
    address: '',
    apartment: '',
    residentName: '',
    details: '',
});

const selectedGuide = computed(() => props.emergencyGuides.find(
    (guide) => guide.id === selectedGuideId.value,
));

function showGuide(guideId) {
    selectedGuideId.value = guideId;
    activeView.value = 'guide';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function showRequestForm() {
    if (selectedGuide.value) {
        form.issueType = selectedGuide.value.id;
    }

    errors.value = {};
    draft.value = '';
    deliveryStatus.value = '';
    copyMessage.value = '';
    activeView.value = 'request';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function goHome() {
    activeView.value = 'home';
    selectedGuideId.value = null;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function establishMaxSession() {
    const initData = window.WebApp?.initData;

    if (typeof initData !== 'string' || initData === '') {
        return;
    }

    try {
        const response = await fetch('/auth/max', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
            },
            body: JSON.stringify({ initData }),
        });

        if (!response.ok) {
            return;
        }

        const result = await response.json();
        const csrfMeta = document.querySelector('meta[name="csrf-token"]');

        if (csrfMeta && typeof result.csrfToken === 'string') {
            csrfMeta.content = result.csrfToken;
        }

        currentResident.value = result.user;
        const startParam = window.WebApp?.initDataUnsafe?.start_param;
        if (typeof startParam === 'string' && /^invite_[a-f0-9]{64}$/.test(startParam)) {
            invitationToken.value = startParam.slice(7);
            await openIncidents();
        }
    } catch {
        currentResident.value = null;
    }
}

async function openMeters() {
    activeView.value = 'meters';
    meterMessage.value = '';
    meterError.value = '';

    if (!currentResident.value) {
        meterError.value = 'Чтобы вести показания, откройте приложение через MAX.';
        return;
    }

    isLoadingMeters.value = true;

    try {
        const response = await fetch('/meters', { headers: { Accept: 'application/json' } });
        const result = await response.json();

        if (!response.ok) {
            meterError.value = 'Не удалось загрузить счётчики. Попробуйте ещё раз.';
            return;
        }

        meters.value = result.meters;
    } catch {
        meterError.value = 'Нет подключения к сети. Проверьте интернет и повторите попытку.';
    } finally {
        isLoadingMeters.value = false;
    }
}

async function addMeter() {
    meterError.value = '';
    meterMessage.value = '';
    isSavingMeter.value = true;

    try {
        const response = await fetch('/meters', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
            },
            body: JSON.stringify(meterForm),
        });
        const result = await response.json();

        if (!response.ok) {
            meterError.value = result.message ?? 'Проверьте название и вид счётчика.';
            return;
        }

        meters.value.push(result.meter);
        meterForm.name = '';
        meterForm.serialNumber = '';
        meterMessage.value = 'Счётчик добавлен.';
    } catch {
        meterError.value = 'Не удалось добавить счётчик. Попробуйте ещё раз.';
    } finally {
        isSavingMeter.value = false;
    }
}

async function saveReading(meter) {
    if (savingReadings[meter.id]) {
        return;
    }

    meterError.value = '';
    meterMessage.value = '';
    readingErrors[meter.id] = '';
    savingReadings[meter.id] = true;

    try {
        const response = await fetch(`/meters/${meter.id}/readings`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
            },
            body: JSON.stringify({ reading: readingValues[meter.id] ?? '' }),
        });
        const result = await response.json();

        if (!response.ok) {
            readingErrors[meter.id] = result.errors?.reading?.[0] ?? 'Проверьте введённое показание.';
            return;
        }

        meter.readings.unshift(result.reading);
        readingValues[meter.id] = '';
        meterMessage.value = 'Сохранено в приложении. Поставщику показание не отправлено.';
    } catch {
        readingErrors[meter.id] = 'Не удалось сохранить показание. Проверьте интернет.';
    } finally {
        savingReadings[meter.id] = false;
    }
}

async function openIncidents(preferredHouse = null) {
    activeView.value = 'incidents';
    showHouseSettings.value = false;
    showHouseAccess.value = false;
    incidentFeedback.value = '';
    incidentError.value = '';
    incidentErrors.value = {};

    if (!currentResident.value) {
        incidentError.value = 'Чтобы сообщить о проблеме, откройте приложение через MAX.';
        return;
    }

    isLoadingIncidents.value = true;

    try {
        const response = await fetch('/my/houses', { headers: { Accept: 'application/json' } });
        const result = await response.json();

        if (!response.ok) {
            incidentError.value = 'Не удалось загрузить ваши дома. Попробуйте ещё раз.';
            return;
        }

        houses.value = result.houses;
        selectedHouse.value = houses.value.find((house) => house.id === preferredHouse?.id) ?? houses.value.find((house) => house.id === selectedHouse.value?.id) ?? houses.value[0] ?? null;

        if (!selectedHouse.value) {
            incidentError.value = 'Дом пока не привязан. Попросите представителя УК или администратора добавить вас в дом.';
            return;
        }

        await loadHouseIncidents();
    } catch {
        incidentError.value = 'Нет подключения к сети. Проверьте интернет и повторите попытку.';
    } finally {
        isLoadingIncidents.value = false;
    }
}

async function selectHouse(event) {
    selectedHouse.value = houses.value.find((house) => house.id === Number(event.target.value)) ?? null;
    showHouseSettings.value = false;
    showHouseAccess.value = false;
    houseIncidents.value = [];
    Object.assign(incidentForm, { issueType: 'water', location: '', details: '', incidentId: null });
    await loadHouseIncidents();
}

async function loadHouseIncidents() {
    if (!selectedHouse.value) {
        return;
    }

    isLoadingIncidents.value = true;

    try {
        const response = await fetch(`/houses/${selectedHouse.value.id}/incidents`, {
            headers: { Accept: 'application/json' },
        });
        const result = await response.json();

        if (!response.ok) {
            incidentError.value = 'Не удалось загрузить сообщения по дому.';
            return;
        }

        houseIncidents.value = result.incidents;
        for (const incident of result.incidents) {
            incidentWork[incident.id] = {
                status: incident.status === 'reported' ? 'in_progress' : incident.status,
                assignedTo: incident.assignedTo ?? '',
                nextAction: incident.nextAction ?? '',
                nextUpdateAt: incident.nextUpdateAt ? new Date(incident.nextUpdateAt).toISOString().slice(0, 16) : '',
            };
        }
    } catch {
        incidentError.value = 'Нет подключения к сети. Проверьте интернет и повторите попытку.';
    } finally {
        isLoadingIncidents.value = false;
    }
}

async function updateHouseLayout(layout) {
    selectedHouse.value.layout = layout;
    await loadHouseIncidents();
}

async function saveIncidentWork(incident) {
    if (!selectedHouse.value || isSavingWorkflow.value) return;
    isSavingWorkflow.value = true;
    incidentError.value = '';
    incidentFeedback.value = '';

    try {
        const response = await fetch(`/houses/${selectedHouse.value.id}/incidents/${incident.id}`, {
            method: 'PATCH',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
            body: JSON.stringify(incidentWork[incident.id]),
        });
        const result = await response.json();
        if (!response.ok) {
            incidentError.value = result.message ?? 'Не удалось сохранить план работ. Проверьте поля.';
            return;
        }
        incidentFeedback.value = 'Обновление сохранено.';
        await loadHouseIncidents();
    } catch {
        incidentError.value = 'Не удалось сохранить план работ. Проверьте интернет.';
    } finally {
        isSavingWorkflow.value = false;
    }
}

async function answerIncident(incident, stage, answer) {
    if (!selectedHouse.value) return;
    incidentError.value = '';
    incidentFeedback.value = '';
    try {
        const response = await fetch(`/houses/${selectedHouse.value.id}/incidents/${incident.id}/responses`, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
            body: JSON.stringify({ stage, answer }),
        });
        const result = await response.json();
        if (!response.ok) {
            incidentError.value = result.message ?? 'Не удалось сохранить ответ. Попробуйте ещё раз.';
            return;
        }
        incidentFeedback.value = 'Ответ сохранён.';
        await loadHouseIncidents();
    } catch {
        incidentError.value = 'Не удалось сохранить ответ. Проверьте интернет.';
    }
}

function incidentStatusLabel(status) {
    return ({ reported: 'Сообщено', in_progress: 'В работе', work_completed: 'Работы завершены' })[status] ?? status;
}

function joinIncident(incident) {
    incidentForm.incidentId = incident.id;
    incidentForm.issueType = incident.issueType;
    incidentForm.location = incident.location;
    document.querySelector('[name="incident-details"]')?.focus();
}

async function submitIncident() {
    if (!selectedHouse.value || isSavingIncident.value) {
        return;
    }

    isSavingIncident.value = true;
    incidentFeedback.value = '';
    incidentError.value = '';
    incidentErrors.value = {};

    try {
        const response = await fetch(`/houses/${selectedHouse.value.id}/incidents`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
            },
            body: JSON.stringify(incidentForm),
        });
        const result = await response.json();

        if (!response.ok) {
            incidentErrors.value = result.errors ?? {};
            incidentError.value = result.message ?? 'Проверьте заполненные поля и повторите попытку.';
            return;
        }

        incidentFeedback.value = incidentForm.incidentId
            ? 'Сообщение добавлено к проблеме в этом доме.'
            : 'Сообщение сохранено. Оно доступно диспетчеру этого дома.';
        incidentForm.details = '';
        incidentForm.incidentId = null;
        await loadHouseIncidents();
    } catch {
        incidentError.value = 'Не удалось сохранить сообщение. Проверьте интернет и попробуйте ещё раз.';
    } finally {
        isSavingIncident.value = false;
    }
}

function issueLabel(issueType) {
    return props.emergencyGuides.find((guide) => guide.id === issueType)?.title ?? 'Коммунальная проблема';
}

function formatReadingDate(date) {
    return new Intl.DateTimeFormat('ru-RU', { dateStyle: 'medium' }).format(new Date(date));
}

async function generateDraft() {
    errors.value = {};
    draft.value = '';
    copyMessage.value = '';
    isSubmitting.value = true;

    try {
        const response = await fetch('/appeals/preview', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
            },
            body: JSON.stringify(form),
        });
        const result = await response.json();

        if (!response.ok) {
            errors.value = result.errors ?? {};
            return;
        }

        draft.value = result.draft;
        deliveryStatus.value = result.deliveryStatus;
    } catch {
        copyMessage.value = 'Не удалось подготовить текст. Проверьте интернет и попробуйте ещё раз.';
    } finally {
        isSubmitting.value = false;
    }
}

async function copyDraft() {
    try {
        await navigator.clipboard.writeText(draft.value);
        copyMessage.value = 'Текст скопирован. Теперь его можно отправить в УК или ТСЖ.';
    } catch {
        copyMessage.value = 'Скопируйте текст обращения вручную.';
    }
}

function handleMaxBack() {
    if (activeView.value !== 'home') {
        goHome();
    }
}

let maxBackButton;

watch(activeView, (view) => {
    if (!maxBackButton) {
        return;
    }

    if (view === 'home') {
        maxBackButton.hide();
    } else {
        maxBackButton.show();
    }
}, { immediate: true });

onMounted(() => {
    maxBackButton = window.WebApp?.BackButton;
    establishMaxSession();

    if (maxBackButton) {
        maxBackButton.onClick(handleMaxBack);

        if (activeView.value !== 'home') {
            maxBackButton.show();
        }
    }
});

onBeforeUnmount(() => {
    maxBackButton?.offClick(handleMaxBack);
    maxBackButton?.hide();
});
</script>

<template>
    <main class="app-shell">
        <Head :title="appName" />
        <header class="topbar">
            <button class="brand" type="button" aria-label="На главную страницу" @click="goHome">
                <img class="brand-mark" src="/brand/umka-mark.svg" alt="" aria-hidden="true">
                <span>{{ appName }}</span>
            </button>
            <span v-if="currentResident" class="topbar-label" data-test="resident-name">{{ currentResident.name }}</span>
            <span v-else class="topbar-label">Помощь по дому</span>
        </header>

        <template v-if="activeView === 'home'">
            <section class="page-intro" aria-labelledby="home-title">
                <p class="eyebrow">Удобный мобильный коммунальный ассистент</p>
                <h1 id="home-title">Что случилось дома?</h1>
                <p class="intro-copy">
                    Выберите ситуацию — подскажем, что сделать и куда обратиться.
                </p>
            </section>

            <div class="urgent-banner" role="note">
                <span class="urgent-mark" aria-hidden="true">!</span>
                <p>Если кому-то угрожает опасность, выйдите в безопасное место и позвоните 112.</p>
            </div>

            <section aria-labelledby="guides-title">
                <div class="section-heading">
                    <h2 id="guides-title">Быстрые инструкции</h2>
                    <p>Коротко и по шагам</p>
                </div>

                <div class="guide-grid">
                    <button
                        v-for="(guide, index) in emergencyGuides"
                        :key="guide.id"
                        class="guide-card"
                        :data-test="`guide-${guide.id}`"
                        type="button"
                        @click="showGuide(guide.id)"
                    >
                        <span class="guide-kicker">
                            <span class="guide-dot" aria-hidden="true"></span>
                            Ситуация {{ index + 1 }}
                        </span>
                        <h3>{{ guide.title }}</h3>
                        <p>{{ guide.summary }}</p>
                    </button>
                </div>
                <div v-if="emergencyGuides.length === 0" class="empty-state" data-test="guides-empty">
                    <span class="empty-state-mark" aria-hidden="true">i</span>
                    <div>
                        <h3>Инструкции временно недоступны</h3>
                        <p>Попробуйте зайти чуть позже. Если ситуация опасная, позвоните 112.</p>
                    </div>
                </div>
            </section>

            <section class="request-card" aria-labelledby="request-title">
                <div>
                    <h2 id="request-title">Нужно сообщить в УК или ТСЖ?</h2>
                    <p>Ответьте на несколько вопросов — подготовим обращение, которое можно скопировать.</p>
                </div>
                <button
                    class="button"
                    data-test="open-request-form"
                    type="button"
                    @click="showRequestForm"
                >
                    Составить обращение
                </button>
            </section>

            <section class="meters-card incidents-entry" aria-labelledby="incidents-entry-title">
                <div>
                    <p class="eyebrow">Общая проблема</p>
                    <h2 id="incidents-entry-title">Сообщить о проблеме в доме</h2>
                    <p>Сообщение увидит диспетчер. Можно присоединиться к уже известной проблеме.</p>
                </div>
                <button class="button button-secondary" data-test="open-incidents" type="button" @click="openIncidents">
                    Открыть проблемы дома
                </button>
            </section>

            <section class="meters-card" aria-labelledby="meters-title">
                <div>
                    <p class="eyebrow">Учёт дома</p>
                    <h2 id="meters-title">Счётчики и показания</h2>
                    <p>Добавьте приборы и ведите историю показаний в одном месте.</p>
                </div>
                <button class="button button-secondary" data-test="open-meters" type="button" @click="openMeters">
                    Открыть счётчики
                </button>
            </section>

            <p class="page-footnote">
                Инструкции помогают сориентироваться и не заменяют вызов аварийной службы.
                Номер аварийной службы дома обычно указан в квитанции или на стенде у подъезда.
            </p>
        </template>

        <template v-else-if="activeView === 'incidents'">
            <JoinHouse :authenticated="!!currentResident" :initial-token="invitationToken" @joined="openIncidents" />
            <button class="back-button" type="button" @click="goHome">← На главную</button>

            <section class="form-panel incident-panel" aria-labelledby="incidents-page-title">
                <p class="eyebrow">Общая картина по дому</p>
                <h1 id="incidents-page-title">Проблемы в доме</h1>
                <label v-if="houses.length > 1" class="field">Ваш дом<select data-test="select-house" :value="selectedHouse?.id" :disabled="isLoadingIncidents" @change="selectHouse"><option v-for="house in houses" :key="house.id" :value="house.id">{{ house.address }}</option></select></label>
                <div v-if="selectedHouse" class="incident-house" data-test="incidents-house-title">
                    <strong>{{ selectedHouse.name }}</strong>
                    <span>{{ selectedHouse.address }}</span>
                </div>

                <template v-if="selectedHouse?.role === 'house_admin'">
                    <div class="house-admin-actions">
                        <button class="text-button" type="button" data-test="configure-house-map" :aria-expanded="showHouseSettings" @click="showHouseSettings = !showHouseSettings; showHouseAccess = false">
                            {{ showHouseSettings ? 'Скрыть настройки схемы' : 'Настроить схему дома' }}
                        </button>
                        <button class="text-button" type="button" data-test="manage-house-members" :aria-expanded="showHouseAccess" @click="showHouseAccess = !showHouseAccess; showHouseSettings = false">
                            {{ showHouseAccess ? 'Скрыть участников' : 'Управлять участниками' }}
                        </button>
                    </div>
                    <HouseMapSettings v-if="showHouseSettings" :key="selectedHouse.id" :house="selectedHouse" @updated="updateHouseLayout" />
                    <HouseMemberAccess v-if="showHouseAccess" :key="selectedHouse.id" :house="selectedHouse" @updated="loadHouseIncidents" />
                </template>

                <p v-if="incidentError" class="meter-feedback meter-feedback-error" role="alert" data-test="incident-error">
                    {{ incidentError }}
                </p>
                <p v-if="incidentFeedback" class="meter-feedback" role="status" data-test="incident-feedback">
                    {{ incidentFeedback }}
                </p>

                <div v-if="isLoadingIncidents" class="incident-loading" role="status" aria-busy="true" data-test="incidents-loading">
                    <span class="spinner" aria-hidden="true"></span>
                    <span>Загружаем данные дома…</span>
                </div>

                <template v-if="selectedHouse && !isLoadingIncidents">
                    <section class="incident-list" aria-labelledby="active-incidents-title">
                        <div class="section-heading">
                            <h2 id="active-incidents-title">Сообщения жителей</h2>
                            <p>Отсутствие ответа не означает, что проблема устранена</p>
                        </div>
                        <article
                            v-for="incident in houseIncidents"
                            :key="incident.id"
                            class="incident-card"
                            :data-test="`incident-${incident.id}`"
                        >
                            <div class="incident-card-heading">
                                <div>
                                    <p class="eyebrow">{{ issueLabel(incident.issueType) }}</p>
                                    <h3>{{ incident.location }}</h3>
                                </div>
                                <span class="incident-status">{{ incidentStatusLabel(incident.status) }}</span>
                            </div>
                            <p class="incident-summary">{{ incident.reportCount }} {{ incident.reportCount === 1 ? 'сообщение' : (incident.reportCount < 5 ? 'сообщения' : 'сообщений') }}</p>
                            <p v-if="incident.nextAction" class="incident-next-action"><strong>Следующий шаг:</strong> {{ incident.nextAction }}<span v-if="incident.nextUpdateAt">Обновление до {{ new Date(incident.nextUpdateAt).toLocaleString('ru-RU') }}</span></p>
                            <div v-if="incident.reports?.length" class="incident-reports">
                                <p v-for="(report, index) in incident.reports" :key="index">{{ report.details }}</p>
                            </div>
                            <form v-if="selectedHouse.role === 'dispatcher'" class="incident-work-form" :data-test="`dispatcher-controls-${incident.id}`" @submit.prevent="saveIncidentWork(incident)">
                                <h4>План работ</h4>
                                <div class="field"><label :for="`assigned-${incident.id}`">Исполнитель</label><input :id="`assigned-${incident.id}`" v-model.trim="incidentWork[incident.id].assignedTo" name="assignedTo" required maxlength="120" placeholder="Аварийная служба"></div>
                                <div class="field"><label :for="`next-action-${incident.id}`">Следующий шаг</label><input :id="`next-action-${incident.id}`" v-model.trim="incidentWork[incident.id].nextAction" name="nextAction" required minlength="5" maxlength="500" placeholder="Что будет сделано"></div>
                                <div class="field"><label :for="`next-update-${incident.id}`">Когда сообщить о ходе работ</label><input :id="`next-update-${incident.id}`" v-model="incidentWork[incident.id].nextUpdateAt" name="nextUpdateAt" type="datetime-local" required></div>
                                <div class="field"><label :for="`work-status-${incident.id}`">Статус</label><select :id="`work-status-${incident.id}`" v-model="incidentWork[incident.id].status" name="status"><option value="in_progress">В работе</option><option value="work_completed">Работы завершены</option></select></div>
                                <button class="button button-secondary" :data-test="`save-work-${incident.id}`" type="submit" :disabled="isSavingWorkflow"><span v-if="isSavingWorkflow" class="spinner" aria-hidden="true"></span>{{ isSavingWorkflow ? 'Сохраняем…' : 'Сохранить план' }}</button>
                            </form>
                            <div v-if="incident.status === 'reported' || incident.status === 'in_progress'" class="incident-vote" :data-test="`scope-check-${incident.id}`">
                                <strong>Проверка состояния услуги</strong>
                                <span>Свежих ответов: {{ incident.scopeResponses?.total ?? 0 }} · устарели и требуют проверки: {{ incident.scopeResponses?.staleResponses ?? 0 }}</span>
                                <span>Проблема есть: {{ incident.scopeResponses?.problemPresent ?? 0 }} · Услуга работает: {{ incident.scopeResponses?.serviceWorking ?? 0 }} · Не могут проверить: {{ incident.scopeResponses?.cannotCheck ?? 0 }}</span>
                                <span v-if="incident.myScopeResponse">Ваш ответ: {{ incident.myScopeResponse === 'problem_present' ? 'проблема есть' : incident.myScopeResponse === 'service_working' ? 'услуга работает' : 'не удалось проверить' }}<template v-if="!incident.myScopeResponseIsFresh"> · обновите ответ, чтобы он учитывался как текущий</template></span>
                                <template v-if="selectedHouse.role === 'resident'">
                                    <button type="button" class="text-button" @click="answerIncident(incident, 'scope', 'problem_present')">Да, проблема есть</button>
                                    <button type="button" class="text-button" @click="answerIncident(incident, 'scope', 'service_working')">У меня всё работает</button>
                                    <button type="button" class="text-button" @click="answerIncident(incident, 'scope', 'cannot_check')">Не могу проверить</button>
                                </template>
                                <small>Ответы показываются диспетчеру только общим числом.</small>
                            </div>
                            <div v-else-if="incident.status === 'work_completed'" class="incident-vote" :data-test="`recovery-check-${incident.id}`">
                                <strong>Работы завершены. Проверка восстановления</strong>
                                <span>Свежие подтверждения: {{ incident.recovery?.restored ?? 0 }} · проблема сохраняется: {{ incident.recovery?.problemRemains ?? 0 }} · ждём свежих ответов: {{ incident.recovery?.noResponse ?? 0 }} · устарели: {{ incident.recovery?.staleResponses ?? 0 }}</span>
                                <span>Не могут проверить: {{ incident.recovery?.cannotCheck ?? 0 }}</span>
                                <template v-if="selectedHouse.role === 'resident' && incident.canConfirmRecovery">
                                    <span v-if="incident.myRecoveryResponse">Ваш ответ {{ incident.myRecoveryResponseIsFresh ? 'учитывается' : 'устарел — подтвердите состояние снова' }}</span>
                                    <button class="text-button" type="button" :data-test="`confirm-restored-${incident.id}`" @click="answerIncident(incident, 'recovery', 'restored')">Да, всё восстановилось</button>
                                    <button class="text-button" type="button" @click="answerIncident(incident, 'recovery', 'problem_remains')">Нет, проблема сохраняется</button>
                                    <button class="text-button" type="button" @click="answerIncident(incident, 'recovery', 'cannot_check')">Не могу проверить</button>
                                </template>
                                <small v-else>Подтвердить восстановление могут жители, сообщившие об этой проблеме.</small>
                            </div>
                            <IncidentHouseMap v-if="incident.houseMap" :map="incident.houseMap" />
                            <button
                                v-if="incident.status === 'reported' || incident.status === 'in_progress'"
                                class="text-button"
                                type="button"
                                @click="joinIncident(incident)"
                            >
                                У меня такая же проблема
                            </button>
                        </article>
                        <div v-if="houseIncidents.length === 0" class="empty-state" data-test="incidents-empty">
                            <span class="empty-state-mark" aria-hidden="true">i</span>
                            <div>
                                <h3>Пока нет сообщений о проблемах</h3>
                                <p>Если вы заметили неисправность, опишите её ниже. Сообщение сразу сохранится для диспетчера.</p>
                            </div>
                        </div>
                    </section>

                    <form class="form-grid incident-form" @submit.prevent="submitIncident">
                        <h2 class="field-full">{{ incidentForm.incidentId ? 'Добавить своё сообщение к проблеме' : 'Сообщить о проблеме' }}</h2>
                        <p v-if="incidentForm.incidentId" class="field-full form-note">
                            Вы присоединяете сообщение к выбранной проблеме. Вид проблемы и участок уже заданы.
                            <button class="text-button" type="button" @click="incidentForm.incidentId = null">Создать отдельное сообщение</button>
                        </p>
                        <div class="field">
                            <label for="incident-type">Вид проблемы</label>
                            <select id="incident-type" v-model="incidentForm.issueType" name="incident-type" :disabled="Boolean(incidentForm.incidentId)">
                                <option v-for="guide in emergencyGuides" :key="guide.id" :value="guide.id">{{ guide.title }}</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="incident-location">Где именно</label>
                            <input id="incident-location" v-model.trim="incidentForm.location" name="incident-location" placeholder="Например, подъезд 2" required :disabled="Boolean(incidentForm.incidentId)">
                            <span v-if="incidentErrors.location" class="field-error">{{ incidentErrors.location[0] }}</span>
                        </div>
                        <div class="field field-full">
                            <label for="incident-details">Что происходит</label>
                            <textarea id="incident-details" v-model.trim="incidentForm.details" name="incident-details" placeholder="Опишите, что заметили и когда" required></textarea>
                            <span v-if="incidentErrors.details" class="field-error">{{ incidentErrors.details[0] }}</span>
                            <span v-if="incidentErrors.incidentId" class="field-error">{{ incidentErrors.incidentId[0] }}</span>
                        </div>
                        <div class="field-full form-footer">
                            <button class="button" data-test="submit-incident" type="submit" :disabled="isSavingIncident || isLoadingIncidents" :aria-busy="isSavingIncident">
                                <span v-if="isSavingIncident" class="spinner" aria-hidden="true"></span>
                                {{ isSavingIncident ? 'Сохраняем…' : 'Сохранить сообщение' }}
                            </button>
                            <p class="form-note">Сообщение сохранится в «Умке» для участников этого дома. Оно не отправляется во внешнюю организацию.</p>
                        </div>
                    </form>
                </template>
            </section>
        </template>

        <template v-else-if="activeView === 'guide' && selectedGuide">
            <button class="back-button" type="button" @click="goHome">← Все ситуации</button>

            <article class="detail-panel">
                <p class="eyebrow">План действий</p>
                <h1>{{ selectedGuide.title }}</h1>
                <p class="detail-summary">{{ selectedGuide.summary }}</p>

                <div class="detail-alert" role="note">{{ selectedGuide.alert }}</div>

                <ol class="steps-list">
                    <li v-for="(step, index) in selectedGuide.steps" :key="step.title" class="step-item">
                        <span class="step-number" aria-hidden="true">{{ index + 1 }}</span>
                        <div>
                            <h2>{{ step.title }}</h2>
                            <p>{{ step.description }}</p>
                        </div>
                    </li>
                </ol>

                <div class="detail-actions">
                    <a
                        v-if="selectedGuide.urgentPhone"
                        class="button button-danger"
                        :href="`tel:${selectedGuide.urgentPhone}`"
                    >
                        Позвонить {{ selectedGuide.urgentPhone }}
                    </a>
                    <button class="button button-secondary" type="button" @click="showRequestForm">
                        Составить обращение в УК/ТСЖ
                    </button>
                </div>

                <a
                    v-if="selectedGuide.source"
                    class="source-link"
                    :href="selectedGuide.source.url"
                    target="_blank"
                    rel="noreferrer"
                >
                    {{ selectedGuide.source.label }}
                </a>
            </article>
        </template>

        <template v-else-if="activeView === 'meters'">
            <button class="back-button" type="button" @click="goHome">← На главную</button>

            <section class="form-panel meters-panel" aria-labelledby="meters-page-title">
                <p class="eyebrow">Личный журнал</p>
                <h1 id="meters-page-title">Мои счётчики</h1>
                <p class="form-intro">Записывайте показания и проверяйте историю. Сейчас данные сохраняются в приложении и не передаются поставщику.</p>

                <p v-if="meterError" class="meter-feedback meter-feedback-error" role="alert" data-test="meter-error">{{ meterError }}</p>
                <p v-if="meterMessage" class="meter-feedback" role="status">{{ meterMessage }}</p>

                <template v-if="currentResident">
                    <form class="meter-create-form" data-test="add-meter" @submit.prevent="addMeter">
                        <h2>Добавить счётчик</h2>
                        <div class="form-grid">
                            <div class="field">
                                <label for="meter-name">Название</label>
                                <input id="meter-name" v-model.trim="meterForm.name" name="meter-name" placeholder="Например, холодная вода" required maxlength="80">
                            </div>
                            <div class="field">
                                <label for="meter-service">Услуга</label>
                                <select id="meter-service" v-model="meterForm.service" name="meter-service">
                                    <option value="cold_water">Холодная вода</option>
                                    <option value="hot_water">Горячая вода</option>
                                    <option value="electricity">Электричество</option>
                                    <option value="gas">Газ</option>
                                </select>
                            </div>
                            <div class="field field-full">
                                <label for="meter-serial">Номер счётчика <span class="form-note">(необязательно)</span></label>
                                <input id="meter-serial" v-model.trim="meterForm.serialNumber" name="meter-serial" placeholder="Можно найти на корпусе прибора" maxlength="40">
                            </div>
                        </div>
                        <button class="button" type="submit" :disabled="isSavingMeter">
                            <span v-if="isSavingMeter" class="spinner" aria-hidden="true"></span>
                            {{ isSavingMeter ? 'Сохраняем…' : 'Добавить счётчик' }}
                        </button>
                    </form>

                    <div v-if="isLoadingMeters" class="meter-loading" aria-busy="true" data-test="meters-loading">
                        <span v-for="index in 2" :key="index" class="skeleton skeleton-meter"></span>
                        <span class="sr-only">Загружаем счётчики</span>
                    </div>
                    <div v-else-if="meters.length === 0" class="empty-state" data-test="meters-empty">
                        <span class="empty-state-mark" aria-hidden="true">i</span>
                        <div>
                            <h3>Пока нет счётчиков</h3>
                            <p>Добавьте первый прибор, чтобы сохранить его показания и видеть изменения.</p>
                        </div>
                    </div>

                    <section v-else class="meter-list" aria-label="Список счётчиков">
                        <article v-for="meter in meters" :key="meter.id" class="meter-card" :data-test="`meter-${meter.id}`">
                            <div class="meter-heading">
                                <div>
                                    <p class="meter-service">{{ meter.serviceLabel }}</p>
                                    <h2>{{ meter.name }}</h2>
                                </div>
                                <span class="meter-unit">{{ meter.unit }}</span>
                            </div>
                            <p v-if="meter.serialNumber" class="meter-serial">№ {{ meter.serialNumber }}</p>
                            <p v-if="meter.readings.length" class="meter-last-reading">
                                Последнее показание: <strong>{{ meter.readings[0].value }} {{ meter.unit }}</strong>
                                <span>· {{ formatReadingDate(meter.readings[0].recordedAt) }}</span>
                            </p>
                            <p v-else class="meter-last-reading">Показаний пока нет</p>
                            <form class="reading-form" @submit.prevent="saveReading(meter)">
                                <div class="field">
                                    <label :for="`reading-${meter.id}`">Новое показание, {{ meter.unit }}</label>
                                    <input :id="`reading-${meter.id}`" v-model="readingValues[meter.id]" :name="`reading-${meter.id}`" inputmode="decimal" placeholder="0,000" required>
                                    <span v-if="readingErrors[meter.id]" class="field-error">{{ readingErrors[meter.id] }}</span>
                                </div>
                                <button class="button" :data-test="`save-reading-${meter.id}`" type="submit" :disabled="savingReadings[meter.id]">
                                    <span v-if="savingReadings[meter.id]" class="spinner" aria-hidden="true"></span>
                                    {{ savingReadings[meter.id] ? 'Сохраняем…' : 'Сохранить показание' }}
                                </button>
                            </form>
                            <ol v-if="meter.readings.length > 1" class="reading-history" aria-label="История показаний">
                                <li v-for="reading in meter.readings.slice(1, 5)" :key="reading.id ?? reading.recordedAt">
                                    <span>{{ formatReadingDate(reading.recordedAt) }}</span>
                                    <strong>{{ reading.value }} {{ meter.unit }}</strong>
                                </li>
                            </ol>
                        </article>
                    </section>
                </template>
            </section>
        </template>

        <template v-else>
            <button class="back-button" type="button" @click="goHome">← На главную</button>

            <section class="form-panel" aria-labelledby="form-title">
                <p class="eyebrow">Сообщение в УК или ТСЖ</p>
                <h1 id="form-title">Составить обращение</h1>
                <p class="form-intro">
                    Укажите, что произошло. Мы подготовим понятный текст, который вы сможете проверить и скопировать.
                </p>

                <form class="form-grid" @submit.prevent="generateDraft">
                    <div class="field field-full">
                        <label for="issue-type">О чём обращение</label>
                        <select id="issue-type" v-model="form.issueType" name="issueType">
                            <option v-for="guide in emergencyGuides" :key="guide.id" :value="guide.id">
                                {{ guide.title }}
                            </option>
                        </select>
                        <span v-if="errors.issueType" class="field-error">{{ errors.issueType[0] }}</span>
                    </div>

                    <div class="field field-full">
                        <label for="address">Адрес дома</label>
                        <input
                            id="address"
                            v-model.trim="form.address"
                            autocomplete="street-address"
                            name="address"
                            placeholder="Город, улица, дом"
                            required
                        >
                        <span v-if="errors.address" class="field-error">{{ errors.address[0] }}</span>
                    </div>

                    <div class="field">
                        <label for="apartment">Квартира <span class="form-note">(необязательно)</span></label>
                        <input
                            id="apartment"
                            v-model.trim="form.apartment"
                            autocomplete="address-line2"
                            name="apartment"
                            placeholder="Например, 24"
                        >
                        <span v-if="errors.apartment" class="field-error">{{ errors.apartment[0] }}</span>
                    </div>

                    <div class="field">
                        <label for="resident-name">Ваше имя <span class="form-note">(необязательно)</span></label>
                        <input
                            id="resident-name"
                            v-model.trim="form.residentName"
                            autocomplete="name"
                            name="residentName"
                            placeholder="Как к вам обращаться"
                        >
                        <span v-if="errors.residentName" class="field-error">{{ errors.residentName[0] }}</span>
                    </div>

                    <div class="field field-full">
                        <label for="details">Что произошло</label>
                        <textarea
                            id="details"
                            v-model.trim="form.details"
                            name="details"
                            placeholder="Опишите, где и когда вы заметили проблему"
                            required
                        ></textarea>
                        <span v-if="errors.details" class="field-error">{{ errors.details[0] }}</span>
                    </div>

                    <div class="field-full form-footer">
                        <button class="button" data-test="submit-request" type="submit" :disabled="isSubmitting" :aria-busy="isSubmitting">
                            <span v-if="isSubmitting" class="spinner" aria-hidden="true"></span>
                            {{ isSubmitting ? 'Готовим текст…' : 'Подготовить текст' }}
                        </button>
                        <p v-if="isSubmitting" class="loading-message" role="status" data-test="submit-status">Готовим обращение. Обычно это занимает несколько секунд.</p>
                        <p class="form-note">Обращение не отправляется автоматически.</p>
                    </div>
                </form>
            </section>

            <section v-if="isSubmitting" class="draft-panel draft-loading" aria-busy="true" data-test="draft-skeleton">
                <span class="skeleton skeleton-heading"></span>
                <span class="skeleton skeleton-line"></span>
                <span class="skeleton skeleton-line skeleton-short"></span>
                <span class="skeleton skeleton-block"></span>
                <span class="sr-only">Готовим текст обращения</span>
            </section>

            <section v-else-if="draft" class="draft-panel" aria-live="polite" data-test="draft-result">
                <h2>Проверьте черновик</h2>
                <p class="draft-status">
                    Текст подготовлен. Он сохранён только на этом экране и не отправлен в УК или ТСЖ.
                </p>
                <pre class="draft-text">{{ draft }}</pre>
                <div class="form-footer">
                    <button class="button" type="button" @click="copyDraft">Скопировать обращение</button>
                    <p v-if="deliveryStatus === 'draft_only'" class="form-note" data-test="delivery-status">
                        Можно вставить текст в привычный канал связи с УК или ТСЖ.
                    </p>
                </div>
                <p v-if="copyMessage" class="form-note" role="status">{{ copyMessage }}</p>
            </section>

            <p v-else-if="copyMessage" class="form-note" role="status">{{ copyMessage }}</p>
        </template>
    </main>
</template>
