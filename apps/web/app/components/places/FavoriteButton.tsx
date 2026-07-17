import * as React from "react"
import { Heart, Sparkles, ShieldAlert } from "lucide-react"
import { useSession } from "~/lib/session-context"
import { usePlaceStates } from "~/hooks/use-place-state"
import { Button } from "~/components/ui/button"
import { toast } from "sonner"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from "~/components/ui/dialog"
import { useRouteLoaderData } from "react-router"

export function FavoriteButton({ placeId }: { placeId: string }) {
  const { session, login } = useSession()
  const { states, toggleFavorite } = usePlaceStates([placeId])
  const [isLoginOpen, setIsLoginOpen] = React.useState(false)
  const [isLoading, setIsLoading] = React.useState(false)
  const [errorMsg, setErrorMsg] = React.useState<string | null>(null)
  const [isLinkRequired, setIsLinkRequired] = React.useState(false)

  const isFav = states[placeId]?.favorite ?? false

  // Read config from root loader for Google login
  const rootData = useRouteLoaderData("root") as {
    googleIdentityEnabled?: boolean
    publicGoogleClientId?: string
    devAuthEnabled?: boolean
  } | undefined

  const googleIdentityEnabled = rootData?.googleIdentityEnabled ?? false
  const publicGoogleClientId = rootData?.publicGoogleClientId ?? ""
  const devAuthEnabled = rootData?.devAuthEnabled ?? false

  const triggerToggle = React.useCallback(async () => {
    const res = await toggleFavorite(placeId)
    if (res && "error" in res && res.error === "AUTH_REQUIRED") {
      setIsLoginOpen(true)
    } else if (res && !res.success) {
      toast.error("Wystąpił problem przy aktualizacji ulubionych.")
    } else {
      toast.success(isFav ? "Usunięto z ulubionych." : "Dodano do ulubionych!")
    }
  }, [toggleFavorite, placeId, isFav])

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
            toast.success("Zalogowano pomyślnie!")
            // Directly toggle favorite now
            void triggerToggle()
          } else {
            if (res.code === "ACCOUNT_LINK_REQUIRED") {
              setIsLinkRequired(true)
            } else {
              setErrorMsg(res.error || "Wystąpił błąd podczas logowania.")
            }
          }
        },
      })

      globalWindow.google.accounts.id.renderButton(
        document.getElementById(`google-btn-${placeId}`),
        { theme: "outline", size: "large", width: 240 }
      )
    } catch {
      setErrorMsg("Nie udało się zainicjować logowania Google.")
    }
  }, [publicGoogleClientId, login, placeId, triggerToggle])

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

  const handleDevLogin = async () => {
    setIsLoading(true)
    setErrorMsg(null)
    const fakeToken = "fake_google_token_dev-subject-123_dev-user@example.com_Developer%20User"
    const res = await login(fakeToken)
    setIsLoading(false)
    if (res.success) {
      setIsLoginOpen(false)
      toast.success("Zalogowano pomyślnie w trybie deweloperskim!")
      // Directly toggle favorite now
      void triggerToggle()
    } else {
      setErrorMsg(res.error || "Wystąpił błąd podczas logowania deweloperskiego.")
    }
  }

  return (
    <>
      <Button
        variant="ghost"
        size="icon"
        className={`h-9 w-9 rounded-full ${isFav ? "text-accent bg-accent/5 hover:bg-accent/10" : "text-muted-foreground hover:bg-muted"}`}
        aria-pressed={isFav}
        onClick={(e) => {
          e.preventDefault()
          e.stopPropagation()
          void triggerToggle()
        }}
        title={isFav ? "Usuń z ulubionych" : "Dodaj do ulubionych"}
      >
        <Heart className={`h-5 w-5 ${isFav ? "fill-current" : ""}`} />
        <span className="sr-only">Dodaj do ulubionych</span>
      </Button>

      {/* Login Dialog */}
      <Dialog open={isLoginOpen} onOpenChange={(open) => {
        setIsLoginOpen(open)
        if (!open) {
          setIsLinkRequired(false)
          setErrorMsg(null)
        }
      }}>
        <DialogContent className="sm:max-w-[360px] p-6 text-center">
          <DialogHeader>
            <DialogTitle className="font-serif text-2xl font-medium tracking-tight mb-2">
              Logowanie wymagane
            </DialogTitle>
            <DialogDescription className="text-sm text-muted-foreground leading-relaxed">
              Zaloguj się, aby dodać to miejsce do swojej listy ulubionych.
            </DialogDescription>
          </DialogHeader>

          <div className="flex flex-col gap-4 items-center justify-center py-6">
            {isLoading && (
              <p className="text-sm text-muted-foreground animate-pulse">Łączenie z systemem...</p>
            )}

            {isLinkRequired ? (
              <div className="flex flex-col gap-3 items-center text-center p-3 bg-amber-50 border border-amber-200 rounded-lg">
                <ShieldAlert className="size-8 text-amber-600" />
                <p className="text-xs font-semibold text-amber-900 leading-normal">
                  Konto o podanym adresie e-mail już istnieje w systemie, ale nie zostało połączone z kontem Google. Zaloguj się tradycyjnym hasłem w panelu administracyjnym.
                </p>
              </div>
            ) : (
              <>
                {googleIdentityEnabled ? (
                  <div id={`google-btn-${placeId}`} className="min-h-[40px] flex items-center justify-center" />
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
                      Bypass Login & Dodaj
                    </Button>
                  </div>
                )}
              </>
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
