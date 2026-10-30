const DATE_PATTERN = /^(\d{4})-(\d{2})-(\d{2})/;

function isValidDate(year: number, month: number, day: number): boolean {
  const date = new Date(Date.UTC(year, month - 1, day));

  return date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day;
}

export function formatDate(value: string | null | undefined): string {
  if (!value) return "—";

  const match = DATE_PATTERN.exec(value);
  if (match) {
    const [, year, month, day] = match;
    if (isValidDate(Number(year), Number(month), Number(day))) {
      return `${day}-${month}-${year}`;
    }

    return "—";
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "—";

  const parts = new Intl.DateTimeFormat("en-GB", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
  }).formatToParts(date);
  const part = (type: Intl.DateTimeFormatPartTypes) => parts.find((item) => item.type === type)?.value ?? "";

  return `${part("day")}-${part("month")}-${part("year")}`;
}

export function formatDateTime(value: string | null | undefined): string {
  if (!value) return "—";

  const timestamp = value.includes("T") ? value : value.replace(" ", "T");
  const normalizedTimestamp = timestamp.replace(/(\.\d{3})\d+/, "$1");
  const date = new Date(normalizedTimestamp);
  if (Number.isNaN(date.getTime())) return "—";

  const parts = new Intl.DateTimeFormat("en-GB", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
    hourCycle: "h23",
  }).formatToParts(date);
  const part = (type: Intl.DateTimeFormatPartTypes) => parts.find((item) => item.type === type)?.value ?? "";

  return `${part("day")}-${part("month")}-${part("year")} ${part("hour")}:${part("minute")}`;
}
