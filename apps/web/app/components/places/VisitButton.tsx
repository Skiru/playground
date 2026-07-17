import * as React from "react"
import { Compass, Sparkles, Check, Calendar, MessageSquare } from "lucide-react"
import { useSession } from "~/lib/session-context"
import { usePlaceStates } from "~/hooks/use-place-state"
import { Button } from "~/components/ui/button"
import { Input } from "~/components/ui/input"
import { Label } from "~/components/ui/label"
import { toast } from "sonner"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from "~/components/ui/dialog"
import { useRouteLoaderData } from "react-router"

export function VisitButton({ placeId }: { placeId: string }) {
  const { session, login } = useSession()
  const { states, addVisit } = usePlaceStates([placeId])
  const [isVisitOpen, setIsVisitOpen] = React.useState(false)
  const [isLoginOpen, setIsLoginOpen] = React.useState(false)
  const [isLoading, setIsLoading] = React.useState(false)
  const [errorMsg, setErrorMsg] = React.useState<string | null>(null)
  
  // Visit form state prefilled with today's date directly in initializer to avoid useEffect side effects
  const [visitedOn, setVisitedOn] = React.useState(() => new Date().toISOString().split("T")[0])
  const [note, setNote] = React.useState("")

  // Google Sign-In config from root loader
  const rootData = useRouteLoaderData("root") as {
    googleIdentityEnabled?: boolean
    publicGoogleClientId?: string
    devAuthEnabled?: boolean
  } | undefined

  const googleIdentityEnabled = rootData?.googleIdentityEnabled ?? false
  const publicGoogleClientId = rootData?.publicGoogleClientId ?? ""
  const devAuthEnabled = rootData?.devAuthEnabled ?? false

  const lastVisited = states[placeId]?.lastVisitedOn

  const initializeGoogleButton = React.useCallback(() => {
    if (typeof window === "undefined") return

    const globalWindow = window as unknown as {
      google?: {
        accounts: {
          id: {
            initialize: (config: { client_id: string; callback: (res: { credential: string }) => Promise<void> }) => void
            renderButton: (element: HTMLElement | null, options: { theme: string; size: string; width: number }) => void
          }
        }
      }
    }

    if (!globalWindow.google) return

    try {
      globalWindow.google.accounts.id.initialize({
        client_id: publicGoogleClientId,
        callback: async (response) => {
          setIsLoading(true)
          setErrorMsg(null)
          const res = await login(response.credential)
          setIsLoading(false)
          if (res.success) {
            setIsLoginOpen(false)
            setIsVisitOpen(true)
            toast.success("Zalogowano pomyślnie!")
          } else {
            setErrorMsg(res.error || "Wystąpił błąd podczas logowania.")
          }
        },
      })

      globalWindow.google.accounts.id.renderButton(
        document.getElementById(`google-btn-visit-${placeId}`),
        { theme: "outline", size: "large", width: 240 }
      )
    } catch {
      setErrorMsg("Nie udało się zainicjować logowania Google.")
    }
  }, [publicGoogleClientId, login, placeId])

  // Load Google script dynamically
  React.useEffect(() => {
    if (!googleIdentityEnabled || !isLoginOpen || session.authenticated) return

    const id = "google-gsi-client"
    if (document.getElementById(id)) {
      setTimeout(initializeGoogleButton, 0)
      return
    }

    const script = document.createElement("script")
    script.id = id
    script.src = "https://accounts.google.com/gsi/client"
    script.async = true
    script.defer = true
    script.onload = () => {
      setTimeout(initializeGoogleButton, 0)
    }
    document.body.appendChild(script)

    return () => {
      // Clean up
    }
  }, [googleIdentityEnabled, isLoginOpen, session.authenticated, initializeGoogleButton])

  const handleButtonClick = () => {
    if (session.authenticated) {
      setIsVisitOpen(true)
    } else {
      setIsLoginOpen(true)
    }
  }

  const handleSaveVisit = async () => {
    if (!visitedOn) {
      toast.error("Wybierz datę wizyty.")
      return
    }

    if (note && note.length > 1000) {
      toast.error("Notatka nie może przekraczać 1000 znaków.")
      return
    }

    setIsLoading(true)
    setErrorMsg(null)
    const res = await addVisit(placeId, visitedOn, note)
    setIsLoading(false)

    if (res.success) {
      setIsVisitOpen(false)
      setNote("")
      toast.success("Wizyta została zapisana w historii!")
    } else {
      setErrorMsg(res.error || "Nie udało się zapisać wizyty.")
    }
  }

  const handleDevLogin = async () => {
    setIsLoading(true)
    setErrorMsg(null)
    const fakeToken = "fake_google_token_dev-subject-123_dev-user@example.com_Developer%20User"
    const res = await login(fakeToken)
    setIsLoading(false)
    if (res.success) {
      setIsLoginOpen(false)
      setIsVisitOpen(true)
      toast.success("Zalogowano pomyślnie w trybie deweloperskim!")
    } else {
      setErrorMsg(res.error || "Wystąpił błąd podczas logowania deweloperskiego.")
    }
  }

  return (
    <>
      <Button
        variant={lastVisited ? "secondary" : "default"}
        size="sm"
        className="font-bold text-xs gap-1.5"
        onClick={handleButtonClick}
      >
        {lastVisited ? (
          <>
            <Check className="size-4 text-primary" />
            Byliśmy tutaj ({lastVisited})
          </>
        ) : (
          <>
            <Compass className="size-4" />
            Byliśmy tutaj
          </>
        )}
      </Button>

      {/* Visit Dialog */}
      <Dialog open={isVisitOpen} onOpenChange={setIsVisitOpen}>
        <DialogContent className="sm:max-w-[420px] p-6">
          <DialogHeader>
            <DialogTitle className="font-serif text-xl font-bold">Zapisz wizytę</DialogTitle>
            <DialogDescription className="text-xs text-muted-foreground">
              Dodaj to miejsce do historii odwiedzin Twojej rodziny.
            </DialogDescription>
          </DialogHeader>

          <div className="grid gap-4 py-4">
            <div className="grid gap-1.5">
              <Label htmlFor="visitDate" className="text-xs font-bold text-muted-foreground uppercase font-mono flex items-center gap-1">
                <Calendar className="size-3.5 text-primary" />
                Data wizyty
              </Label>
              <Input
                id="visitDate"
                type="date"
                value={visitedOn}
                onChange={(e) => setVisitedOn(e.target.value)}
                max={new Date().toISOString().split("T")[0]}
              />
            </div>
            <div className="grid gap-1.5">
              <Label htmlFor="visitNote" className="text-xs font-bold text-muted-foreground uppercase font-mono flex items-center gap-1">
                <MessageSquare className="size-3.5 text-primary" />
                Notatki z pobytu (opcjonalnie)
              </Label>
              <textarea
                id="visitNote"
                rows={4}
                value={note}
                onChange={(e) => setNote(e.target.value)}
                placeholder="Napisz, co najbardziej podobało się dzieciom, na co uważać..."
                className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-colors placeholder:text-muted-foreground/70 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
              />
              <span className="text-3xs text-right text-muted-foreground">
                Maksymalnie 1000 znaków
              </span>
            </div>
          </div>

          <div className="flex justify-end gap-2 border-t pt-4">
            <Button variant="outline" size="sm" className="font-bold" onClick={() => setIsVisitOpen(false)}>
              Anuluj
            </Button>
            <Button
              size="sm"
              className="font-bold bg-primary hover:bg-primary/95 text-white"
              onClick={handleSaveVisit}
              disabled={isLoading}
            >
              Zapisz wizytę
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Login Dialog for Visit */}
      <Dialog open={isLoginOpen} onOpenChange={(open) => {
        setIsLoginOpen(open)
        if (!open) {
          setErrorMsg(null)
        }
      }}>
        <DialogContent className="sm:max-w-[360px] p-6 text-center">
          <DialogHeader>
            <DialogTitle className="font-serif text-2xl font-medium tracking-tight mb-2">
              Logowanie wymagane
            </DialogTitle>
            <DialogDescription className="text-sm text-muted-foreground leading-relaxed">
              Zaloguj się, aby zapisać to miejsce w swojej historii wizyt.
            </DialogDescription>
          </DialogHeader>

          <div className="flex flex-col gap-4 items-center justify-center py-6">
            {isLoading && (
              <p className="text-sm text-muted-foreground animate-pulse">Łączenie z systemem...</p>
            )}

            {googleIdentityEnabled ? (
              <div id={`google-btn-visit-${placeId}`} className="min-h-[40px] flex items-center justify-center" />
            ) : (
              <p className="text-xs text-muted-foreground italic">
                Logowanie Google jest tymczasowo niedostępne.
              </p>
            )}

            {devAuthEnabled && (
              <div className="w-full flex flex-col gap-2 mt-4 border-t pt-4">
                <p className="text-3xs uppercase tracking-widest text-muted-foreground font-mono font-bold">
                  Tryb Deweloperski (E2E / Test)
                </p>
                <Button
                  variant="secondary"
                  className="w-full font-mono text-xs font-bold"
                  onClick={handleDevLogin}
                  disabled={isLoading}
                >
                  <Sparkles className="mr-1.5 size-3.5 text-accent" />
                  Bypass Login & Zapisz
                </Button>
              </div>
            )}

            {errorMsg && (
              <div className="text-xs font-semibold text-destructive mt-2 leading-relaxed">
                {errorMsg}
              </div>
            )}
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
