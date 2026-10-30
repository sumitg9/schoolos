const apiUrl = (process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000").replace(/\/$/, "");

export class ApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly errors?: Record<string, string[]>,
  ) {
    super(message);
    this.name = "ApiError";
  }
}

function xsrfToken(): string | null {
  const entry = document.cookie
    .split("; ")
    .find((cookie) => cookie.startsWith("XSRF-TOKEN="));

  return entry ? decodeURIComponent(entry.slice("XSRF-TOKEN=".length)) : null;
}

async function prepareCsrf(): Promise<void> {
  const response = await fetch(`${apiUrl}/sanctum/csrf-cookie`, {
    credentials: "include",
    headers: { Accept: "application/json" },
  });

  if (!response.ok) {
    throw new ApiError("Could not initialize a secure session. Check the API connection.", response.status);
  }
}

type RequestOptions = Omit<RequestInit, "body"> & { body?: unknown };

export async function apiRequest<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const method = (options.method ?? "GET").toUpperCase();
  const headers = new Headers(options.headers);
  const isFormData = typeof FormData !== "undefined" && options.body instanceof FormData;
  headers.set("Accept", "application/json");

  if (options.body !== undefined && !isFormData) {
    headers.set("Content-Type", "application/json");
  }

  if (["POST", "PUT", "PATCH", "DELETE"].includes(method)) {
    await prepareCsrf();
    const token = xsrfToken();
    if (!token) {
      throw new ApiError("The secure session cookie is missing. Check the API and frontend host settings.", 0);
    }
    headers.set("X-XSRF-TOKEN", token);
  }

  const response = await fetch(`${apiUrl}/api${path}`, {
    ...options,
    method,
    headers,
    credentials: "include",
    body: options.body === undefined ? undefined : isFormData ? options.body as FormData : JSON.stringify(options.body),
  });

  if (response.status === 204) {
    return undefined as T;
  }

  let payload: unknown;
  try {
    payload = await response.json();
  } catch {
    throw new ApiError(`The API returned an unreadable response (${response.status}).`, response.status);
  }

  if (!response.ok) {
    const body = payload as { message?: unknown; errors?: unknown };
    const message = typeof body.message === "string" ? body.message : `Request failed (${response.status}).`;
    const errors = body.errors && typeof body.errors === "object"
      ? body.errors as Record<string, string[]>
      : undefined;
    throw new ApiError(message, response.status, errors);
  }

  return payload as T;
}

export async function downloadFile(path: string, filename: string): Promise<void> {
  const response = await fetch(`${apiUrl}/api${path}`, {
    credentials: "include",
    headers: { Accept: "text/csv, application/json" },
  });
  if (!response.ok) {
    let message = `Download failed (${response.status}).`;
    try {
      const payload = await response.json() as { message?: unknown; errors?: Record<string, string[]> };
      message = Object.values(payload.errors ?? {}).flat()[0]
        ?? (typeof payload.message === "string" ? payload.message : message);
    } catch {
      throw new ApiError(message, response.status);
    }
    throw new ApiError(message, response.status);
  }
  const url = URL.createObjectURL(await response.blob());
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download = filename;
  anchor.click();
  window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}

export function errorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    const validationMessages = error.errors ? Object.values(error.errors).flat() : [];
    return validationMessages[0] ?? error.message;
  }

  return error instanceof Error ? error.message : "An unexpected error occurred.";
}
