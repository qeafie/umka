export async function houseRequest(path, method = 'GET', body) {
    let response;
    try {
        response = await fetch(path, {
            method,
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
            ...(body === undefined ? {} : { body: JSON.stringify(body) }),
        });
    } catch {
        throw new Error('Нет связи с сервером. Проверьте подключение и повторите попытку.');
    }
    if (response.ok && response.status === 204) return null;
    const result = await response.json().catch(() => ({}));
    if (!response.ok) {
        if ([401, 419].includes(response.status)) throw new Error('Сессия истекла. Откройте приложение заново через MAX.');
        if (response.status === 403) throw new Error('Недостаточно прав для этого действия.');
        throw new Error(Object.values(result.errors ?? {}).flat()[0] ?? result.message ?? 'Не удалось выполнить действие. Повторите попытку.');
    }
    return result;
}
