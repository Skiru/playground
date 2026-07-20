import * as React from "react"
import { listModerationQueue } from "@family-places/api-client"
import { useSession } from "~/lib/session-context"
import { AppShell } from "~/components/layout/AppShell"
import { PageContainer } from "~/components/layout/PageContainer"
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "~/components/ui/card"
import { Button } from "~/components/ui/button"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "~/components/ui/select"
import { Shield, Eye, Calendar, User, MessageSquare } from "lucide-react"
import { Link } from "react-router"

interface Report {
  id: string
  reporterId: string
  reporter: { id: string; displayName: string; initials: string }
  targetType: string
  targetId: string
  reason: string
  details?: string | null
  status: string
  createdAt: string
  evidence: string
  author?: { id: string; displayName: string; initials: string } | null
}

export default function ModeratorQueuePage() {
  const { session } = useSession()
  const [reports, setReports] = React.useState<Report[]>([])
  const [loading, setLoading] = React.useState(true)
  const [statusFilter, setStatusFilter] = React.useState<string>("OPEN")
  const [error, setError] = React.useState<string | null>(null)

  const isModerator = session.authenticated && (
    session.user?.roles.includes("ROLE_MODERATOR") || session.user?.roles.includes("ROLE_ADMIN")
  )

  const loadQueue = React.useCallback(async () => {
    if (!isModerator) return
    setLoading(true)
    setError(null)
    try {
      const res = await listModerationQueue({
        query: {
          status: statusFilter === "ALL" ? undefined : statusFilter,
          page: 1,
          pageSize: 50,
        },
      })
      if (res.data) {
        setReports((res.data.items || []) as Report[])
      } else {
        setError("Nie udało się pobrać kolejki moderatora.")
      }
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Wystąpił błąd.")
    } finally {
      setLoading(false)
    }
  }, [isModerator, statusFilter])

  React.useEffect(() => {
    loadQueue()
  }, [loadQueue])

  if (!isModerator) {
    return (
      <AppShell>
        <PageContainer>
          <div className="mx-auto max-w-md py-16 text-center space-y-4">
            <Shield className="h-16 w-12 text-destructive mx-auto" />
            <h1 className="text-2xl font-bold">Brak uprawnień</h1>
            <p className="text-muted-foreground">Ta sekcja jest dostępna wyłącznie dla zalogowanych moderatorów i administratorów.</p>
            <Button asChild className="mt-4">
              <Link to="/">Powrót do strony głównej</Link>
            </Button>
          </div>
        </PageContainer>
      </AppShell>
    )
  }

  const getReasonLabel = (reason: string) => {
    switch (reason) {
      case "SPAM":
        return "Spam / reklama"
      case "HARASSMENT":
        return "Nękanie / nienawiść"
      case "INAPPROPRIATE":
        return "Nieodpowiednie treści"
      case "MISINFORMATION":
        return "Dezinformacja"
      case "PRIVACY_CONCERN":
        return "Prywatność"
      default:
        return reason
    }
  }

  return (
    <AppShell>
      <PageContainer>
        <div className="mx-auto max-w-5xl py-8">
          <div className="flex flex-col gap-6 md:flex-row md:items-center md:justify-between border-b pb-6 mb-8">
            <div>
              <h1 className="text-3xl font-extrabold tracking-tight flex items-center gap-2">
                <Shield className="h-8 w-8 text-primary" />
                <span>Panel Moderatorów</span>
              </h1>
              <p className="text-muted-foreground mt-1">Obsługuj zgłoszenia, zarządzaj treściami i przeglądaj naruszenia.</p>
            </div>

            <div className="flex items-center gap-3">
              <Select value={statusFilter} onValueChange={setStatusFilter}>
                <SelectTrigger className="w-[180px]">
                  <SelectValue placeholder="Status zgłoszenia" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="ALL">Wszystkie statusy</SelectItem>
                  <SelectItem value="OPEN">Otwarte</SelectItem>
                  <SelectItem value="IN_REVIEW">W toku (Claimed)</SelectItem>
                  <SelectItem value="RESOLVED">Rozwiązane</SelectItem>
                  <SelectItem value="DISMISSED">Odrzucone</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>

          {error && (
            <div className="bg-destructive/10 text-destructive p-4 rounded-lg mb-6 text-sm" role="alert">
              {error}
            </div>
          )}

          {loading ? (
            <div className="space-y-4">
              {[1, 2, 3].map((n) => (
                <Card key={n} className="animate-pulse h-24 bg-muted" />
              ))}
            </div>
          ) : reports.length === 0 ? (
            <Card className="text-center py-12">
              <CardContent className="space-y-4">
                <Shield className="h-12 w-12 text-muted-foreground mx-auto" />
                <h3 className="text-lg font-semibold">Brak zgłoszeń</h3>
                <p className="text-muted-foreground">Kolejka jest pusta. Wszystkie zgłoszenia zostały przetworzone!</p>
              </CardContent>
            </Card>
          ) : (
            <div className="border rounded-md divide-y overflow-hidden bg-card">
              {reports.map((rep) => (
                <div key={rep.id} className="p-5 flex flex-col md:flex-row md:items-center justify-between gap-4 hover:bg-muted/50 transition-colors">
                  <div className="space-y-2 flex-1 min-w-0">
                    <div className="flex items-center gap-2.5 flex-wrap">
                      <span className={`text-xs px-2.5 py-0.5 rounded-full font-semibold ${
                        rep.status === "OPEN" ? "bg-amber-100 text-amber-800" :
                        rep.status === "IN_REVIEW" ? "bg-blue-100 text-blue-800" :
                        rep.status === "RESOLVED" ? "bg-emerald-100 text-emerald-800" :
                        "bg-muted text-muted-foreground"
                      }`}>
                        {rep.status}
                      </span>
                      <span className="text-xs font-bold text-destructive bg-destructive/5 px-2 py-0.5 rounded border border-destructive/10">
                        {getReasonLabel(rep.reason)}
                      </span>
                      <span className="text-xs text-muted-foreground">Zgłoszenie: {rep.id.slice(0, 8)}...</span>
                    </div>

                    <p className="text-sm font-medium text-foreground truncate max-w-2xl">
                      Przedmiot ({rep.targetType}): <span className="italic text-muted-foreground">"{rep.evidence}"</span>
                    </p>

                    <div className="flex items-center gap-4 text-xs text-muted-foreground flex-wrap">
                      <div className="flex items-center gap-1">
                        <User className="h-3.5 w-3.5" />
                        <span>Reporter: {rep.reporter.displayName}</span>
                      </div>
                      <div className="flex items-center gap-1">
                        <Calendar className="h-3.5 w-3.5" />
                        <span>Dodano: {new Date(rep.createdAt).toLocaleString("pl-PL")}</span>
                      </div>
                    </div>
                  </div>

                  <div>
                    <Button asChild size="sm" className="flex items-center gap-1.5 min-w-[120px]">
                      <Link to={`/moderator/case/${rep.id}`}>
                        <Eye className="h-4 w-4" />
                        <span>Szczegóły</span>
                      </Link>
                    </Button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </PageContainer>
    </AppShell>
  )
}
