import * as React from "react"
import { useParams, Link } from "react-router"
import { getModerationCase, claimModerationCase, moderateContent, type GetModerationCaseResponses, type ModerateContentData } from "@family-places/api-client"
import { useSession } from "~/lib/session-context"
import { mapApiError } from "~/utils/error-mapper"
import { AppShell } from "~/components/layout/AppShell"
import { PageContainer } from "~/components/layout/PageContainer"
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "~/components/ui/card"
import { Button } from "~/components/ui/button"
import { Label } from "~/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "~/components/ui/select"
import { Shield, ArrowLeft, CheckCircle2, AlertTriangle, AlertCircle, Info, Check } from "lucide-react"

type CaseDetails = GetModerationCaseResponses[200]
type ModerationAction = NonNullable<ModerateContentData["body"]>["action"]

const ACTION_LABELS: Record<ModerationAction, string> = {
  HIDE: "Ukryj treść (HIDE)",
  REMOVE: "Usuń treść perm (REMOVE)",
  RESTORE: "Przywróć treść (RESTORE)",
  LOCK: "Zablokuj wątek (LOCK)",
  UNLOCK: "Odblokuj wątek (UNLOCK)",
  PIN: "Przypnij wątek (PIN)",
  UNPIN: "Odepnij wątek (UNPIN)",
  DISMISS_REPORT: "Odrzuć zgłoszenie (DISMISS)",
  RESOLVE_REPORT: "Oznacz jako rozwiązane (RESOLVE)",
}

function createIdempotencyKey(): string {
  if (typeof globalThis.crypto?.randomUUID === "function") {
    return globalThis.crypto.randomUUID()
  }

  const bytes = new Uint8Array(16)
  if (typeof globalThis.crypto?.getRandomValues === "function") {
    globalThis.crypto.getRandomValues(bytes)
  } else {
    for (let index = 0; index < bytes.length; index += 1) {
      bytes[index] = Math.floor(Math.random() * 256)
    }
  }

  bytes[6] = (bytes[6] & 0x0f) | 0x40
  bytes[8] = (bytes[8] & 0x3f) | 0x80

  const hex = Array.from(bytes, (value) => value.toString(16).padStart(2, "0")).join("")
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

export default function ModeratorCasePage() {
  const { reportId } = useParams()
  const { session } = useSession()
  const [caseData, setCaseData] = React.useState<CaseDetails | null>(null)
  const [loading, setLoading] = React.useState(true)
  const [error, setError] = React.useState<string | null>(null)

  // Action Form state
  const [action, setAction] = React.useState<ModerationAction | "">("")
  const [reason, setReason] = React.useState("")
  const [actionError, setActionError] = React.useState<string | null>(null)
  const [submittingAction, setSubmittingAction] = React.useState(false)
  const [actionSuccess, setActionSuccess] = React.useState(false)
  const idempotencyKeyRef = React.useRef<string | null>(null)

  const isModerator = session.authenticated && (
    session.user?.roles.includes("ROLE_MODERATOR") || session.user?.roles.includes("ROLE_ADMIN")
  )

  const loadCase = React.useCallback(async () => {
    if (!reportId || !isModerator) return
    setError(null)
    try {
      const res = await getModerationCase({
        path: { reportId },
      })
      if (res.data) {
        setCaseData(res.data)
      } else {
        setError("Zgłoszenie nie istnieje lub zostało usunięte.")
      }
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Wystąpił błąd.")
    } finally {
      setLoading(false)
    }
  }, [reportId, isModerator])

  React.useEffect(() => {
    if (!reportId || !isModerator) return
    let ignore = false

    async function init() {
      setError(null)
      try {
        const res = await getModerationCase({ path: { reportId: reportId! } })
        if (!ignore && res.data) {
          setCaseData(res.data)
        } else if (!ignore) {
          setError("Zgłoszenie nie istnieje lub zostało usunięte.")
        }
      } catch (err: unknown) {
        if (!ignore) {
          setError(err instanceof Error ? err.message : "Wystąpił błąd.")
        }
      } finally {
        if (!ignore) {
          setLoading(false)
        }
      }
    }

    void init()
    return () => {
      ignore = true
    }
  }, [reportId, isModerator])

  const handleClaim = async () => {
    if (!reportId) return
    setActionError(null)
    try {
      const res = await claimModerationCase({
        path: { reportId },
        headers: {
          "X-CSRF-Token": session.csrfToken || "",
        }
      })
      if (res.response?.status === 200) {
        await loadCase()
      } else {
        const errorData = mapApiError(res.error)
        setActionError(errorData.detail || "Nie udało się przypisać zgłoszenia.")
      }
    } catch (err: unknown) {
      setActionError(err instanceof Error ? err.message : "Wystąpił błąd.")
    }
  }

  const handleAction = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!reportId) {
      setActionError("Brak identyfikatora zgłoszenia.")
      return
    }
    if (!action) {
      setActionError("Wybierz decyzję moderacyjną.")
      return
    }
    if (!reason.trim()) {
      setActionError("Uzasadnienie decyzji jest wymagane.")
      return
    }

    setActionError(null)
    setSubmittingAction(true)

    try {
      const idempotencyKey = idempotencyKeyRef.current ?? createIdempotencyKey()
      idempotencyKeyRef.current = idempotencyKey
      const res = await moderateContent({
        body: {
          reportId,
          action: action as ModerationAction,
          reason: reason.trim(),
        },
        headers: {
          "X-CSRF-Token": session.csrfToken || "",
          "Idempotency-Key": idempotencyKey,
        },
      })

      if (res.response?.status === 200) {
        idempotencyKeyRef.current = null
        setActionSuccess(true)
        setTimeout(() => {
          setActionSuccess(false)
          setAction("")
          setReason("")
          loadCase()
        }, 2000)
      } else if (!res.response) {
        setActionError("Wystąpił błąd.")
      } else {
        const errorData = mapApiError(res.error)
        if (res.response.status >= 500) {
          setActionError("Wystąpił błąd.")
          return
        }

        idempotencyKeyRef.current = null
        setActionError(errorData.detail || "Wystąpił błąd podczas zapisywania decyzji.")
      }
    } catch {
      setActionError("Wystąpił błąd.")
    } finally {
      setSubmittingAction(false)
    }
  }

  const availableActions = caseData?.allowedActions ?? []

  if (!isModerator) {
    return (
      <AppShell>
        <PageContainer>
          <div className="mx-auto max-w-md py-16 text-center space-y-4">
            <Shield className="h-16 w-12 text-destructive mx-auto" />
            <h1 className="text-2xl font-bold">Brak uprawnień</h1>
            <p className="text-muted-foreground">Ta sekcja jest dostępna wyłącznie dla zalogowanych moderatorów.</p>
          </div>
        </PageContainer>
      </AppShell>
    )
  }

  const getReasonLabel = (reason: string) => {
    switch (reason) {
      case "SPAM":
        return "Spam lub reklama"
      case "HARASSMENT":
        return "Nękanie lub nienawiść"
      case "INAPPROPRIATE":
        return "Nieodpowiednie treści dla dzieci"
      case "MISINFORMATION":
        return "Dezinformacja"
      case "PRIVACY_CONCERN":
        return "Naruszenie prywatności"
      default:
        return reason
    }
  }

  return (
    <AppShell>
      <PageContainer>
        <div className="mx-auto max-w-4xl py-8">
          <div className="mb-6">
            <Button asChild variant="ghost" size="sm" className="flex items-center gap-1.5 text-muted-foreground hover:text-foreground">
              <Link to="/moderator/queue">
                <ArrowLeft className="h-4 w-4" />
                <span>Kolejka zgłoszeń</span>
              </Link>
            </Button>
          </div>

          {error && (
            <div className="bg-destructive/10 text-destructive p-4 rounded-lg mb-6 text-sm flex items-center gap-2" role="alert">
              <AlertCircle className="h-5 w-5" />
              <span>{error}</span>
            </div>
          )}

          {loading ? (
            <div className="h-64 bg-muted animate-pulse rounded" />
          ) : !caseData ? (
            <Card className="text-center py-12 border-dashed">
              <CardContent className="space-y-4">
                <AlertTriangle className="h-12 w-12 text-destructive mx-auto" />
                <h3 className="text-lg font-semibold">Nie znaleziono sprawy</h3>
                <p className="text-muted-foreground">Podane zgłoszenie nie istnieje w bazie danych.</p>
              </CardContent>
            </Card>
          ) : (
            <div className="grid gap-6 md:grid-cols-3">
              {/* Report and Target Details */}
              <div className="md:col-span-2 space-y-6">
                <Card>
                  <CardHeader className="border-b pb-4">
                    <div className="flex items-center justify-between flex-wrap gap-2 mb-1.5">
                      <span className={`text-xs px-2.5 py-0.5 rounded-full font-semibold ${
                        caseData.status === "OPEN" ? "bg-amber-100 text-amber-800" :
                        caseData.status === "IN_REVIEW" ? "bg-blue-100 text-blue-800" :
                        caseData.status === "RESOLVED" ? "bg-emerald-100 text-emerald-800" :
                        "bg-muted text-muted-foreground"
                      }`}>
                        {caseData.status}
                      </span>
                      <span className="text-xs text-muted-foreground">Utworzono: {new Date(caseData.createdAt).toLocaleString("pl-PL")}</span>
                    </div>
                    <h1 className="text-xl font-bold flex items-center gap-2">
                      <span>Zgłoszenie naruszenia:</span>
                      <span className="text-destructive font-extrabold">{getReasonLabel(caseData.reason)}</span>
                    </h1>
                    {caseData.details && (
                      <CardDescription className="bg-muted/40 p-3 rounded text-sm italic mt-2">
                        "{caseData.details}"
                      </CardDescription>
                    )}
                  </CardHeader>

                  <CardContent className="py-6 space-y-4">
                    <div>
                      <h4 className="text-sm font-bold text-muted-foreground uppercase tracking-wider mb-2">Zgłoszona treść ({caseData.targetType})</h4>
                      {caseData.targetPreview ? (
                        <div className="border p-4 rounded-lg bg-muted/10 space-y-2">
                          {caseData.targetPreview.title && (
                            <h5 className="font-bold text-base">{caseData.targetPreview.title}</h5>
                          )}
                          <p className="text-sm leading-relaxed whitespace-pre-line text-foreground">
                            {caseData.targetPreview.body}
                          </p>
                          <div className="flex items-center justify-between text-xs text-muted-foreground pt-2 border-t border-muted/50">
                            {caseData.targetPreview.rating && (
                              <span>Ocena: {caseData.targetPreview.rating} / 5</span>
                            )}
                            <span>Status w systemie: <span className="font-semibold text-primary">{caseData.targetPreview.status}</span></span>
                          </div>
                        </div>
                      ) : (
                        <div className="flex items-center gap-2 bg-destructive/5 text-destructive border border-destructive/10 p-3 rounded-lg text-sm">
                          <AlertTriangle className="h-5 w-5 shrink-0" />
                          <span>Oryginalna treść została usunięta lub nie istnieje.</span>
                        </div>
                      )}
                    </div>
                  </CardContent>
                </Card>
              </div>

              {/* Moderation Actions panel */}
              <div className="space-y-6">
                <Card className="h-full flex flex-col">
                  <CardHeader className="border-b">
                    <CardTitle className="text-lg font-bold flex items-center gap-1.5">
                      <Shield className="h-5 w-5 text-primary" />
                      <span>Decyzja</span>
                    </CardTitle>
                    <CardDescription>Podejmij działania jako moderator.</CardDescription>
                  </CardHeader>

                  <CardContent className="flex-1 py-6 space-y-4">
                    {actionError && (
                      <div className="bg-destructive/10 text-destructive p-2.5 rounded text-xs leading-relaxed flex gap-1.5" role="alert">
                        <AlertCircle className="h-4 w-4 shrink-0" />
                        <span>{actionError}</span>
                      </div>
                    )}
                    {actionSuccess ? (
                      <div className="flex flex-col items-center justify-center py-8 text-center space-y-2" role="alert">
                        <CheckCircle2 className="h-12 w-12 text-emerald-500 animate-bounce" />
                        <h4 className="font-bold">Decyzja zapisana</h4>
                        <p className="text-xs text-muted-foreground">Kolejka została zaktualizowana.</p>
                      </div>
                    ) : caseData.status === "OPEN" ? (
                      <div className="flex flex-col items-center justify-center text-center py-6 space-y-3">
                        <Info className="h-8 w-8 text-blue-500" />
                        <p className="text-xs text-muted-foreground">Musisz przypisać tę sprawę do siebie przed podjęciem decyzji.</p>
                        <Button onClick={handleClaim} className="w-full">
                          Rozpocznij analizę (Claim)
                        </Button>
                      </div>
                    ) : caseData.status === "RESOLVED" || caseData.status === "DISMISSED" ? (
                      <div className="bg-muted p-4 rounded text-center text-xs text-muted-foreground space-y-2">
                        <Check className="h-8 w-8 text-emerald-500 mx-auto" />
                        <p className="font-semibold">Sprawa została już zamknięta.</p>
                        <p>Zakończono przez: {caseData.resolvedBy || "System"}</p>
                      </div>
                    ) : caseData.claimedBy !== session.user?.id ? (
                      <div className="bg-destructive/5 text-destructive border border-destructive/10 p-4 rounded text-center text-xs">
                        Ta sprawa jest przypisana do innego moderatora.
                      </div>
                    ) : (
                      <form onSubmit={handleAction} className="space-y-4">
                        {caseData.targetType === "FORUM_POST" && caseData.targetPreview?.isInitial && (
                          <div className="bg-muted p-3 rounded text-xs text-muted-foreground">
                            To zgłoszenie dotyczy posta początkowego. Dla zachowania spójności wątku można tutaj jedynie zamknąć lub odrzucić zgłoszenie, a moderację treści należy prowadzić na poziomie wątku.
                          </div>
                        )}
                        <div className="space-y-1.5">
                          <Label htmlFor="moderator-action-select">Wybierz akcję</Label>
                          <Select value={action} onValueChange={(val) => setAction(val as ModerationAction | "")} required>
                            <SelectTrigger id="moderator-action-select">
                              <SelectValue placeholder="Wybierz akcję..." />
                            </SelectTrigger>
                            <SelectContent>
                              {availableActions.map((allowedAction) => (
                                <SelectItem key={allowedAction} value={allowedAction}>{ACTION_LABELS[allowedAction]}</SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                        </div>

                        <div className="space-y-1.5">
                          <Label htmlFor="moderator-reason-textarea">Uzasadnienie decyzji</Label>
                          <textarea
                            id="moderator-reason-textarea"
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            placeholder="Wpisz oficjalne uzasadnienie swojej decyzji (zostanie zapisane w audycie)..."
                            className="flex min-h-[100px] w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm"
                            required
                          />
                        </div>

                        <Button type="submit" disabled={submittingAction} className="w-full">
                          {submittingAction ? "Zapisywanie..." : "Zatwierdź decyzję"}
                        </Button>
                      </form>
                    )}
                  </CardContent>
                </Card>
              </div>
            </div>
          )}
        </div>
      </PageContainer>
    </AppShell>
  )
}
