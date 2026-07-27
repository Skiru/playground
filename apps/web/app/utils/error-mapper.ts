export interface ProblemDetails {
  type?: string;
  title?: string;
  status?: number;
  detail?: string;
  code?: string;
  correlationId?: string;
  invalidParams?: Array<{ name: string; reason: string }>;
}

export function mapApiError(error: unknown): ProblemDetails {
  if (!error || typeof error !== "object") {
    return { detail: "Wystąpił nieoczekiwany błąd." };
  }
  const err = error as Record<string, unknown>;
  const stringValue = (value: unknown): string | undefined => typeof value === "string" ? value : undefined;
  const numberValue = (value: unknown): number | undefined => typeof value === "number" ? value : undefined;
  return {
    type: stringValue(err.type),
    title: stringValue(err.title),
    status: numberValue(err.status),
    detail: stringValue(err.detail) || stringValue(err.message) || "Wystąpił nieoczekiwany błąd.",
    code: stringValue(err.code),
    correlationId: stringValue(err.correlationId),
    invalidParams: Array.isArray(err.invalidParams) ? err.invalidParams as ProblemDetails["invalidParams"] : undefined,
  };
}
