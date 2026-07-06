export function currentDateTimeLocal(date = new Date()) {
    const offsetMs = date.getTimezoneOffset() * 60000;

    return new Date(date.getTime() - offsetMs).toISOString().slice(0, 16);
}
