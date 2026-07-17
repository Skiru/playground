import * as React from "react"
import { useSession } from "~/lib/session-context"
import { useRouteLoaderData, Link, useNavigate } from "react-router"
import { Button } from "~/components/ui/button"
import { Avatar, AvatarFallback } from "~/components/ui/avatar"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogTrigger,
} from "~/components/ui/dialog"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "~/components/ui/dropdown-menu"
import { toast } from "sonner"
import { Sparkles, ShieldAlert, LogOut, User as UserIcon, Heart, Compass } from "lucide-react"

export function UserArea() {
  const { session, login, logout } = useSession()
  const [isOpen, setIsOpen] = React.useState(false)
  const [isLoading, setIsLoading] = React.useState(false)
  const [errorMsg, setErrorMsg] = React.useState<string | null>(null)
  const [isLinkRequired, setIsLinkRequired] = React.useState(false)
  const navigate = useNavigate()

  // Read config from root loader
  const rootData = useRouteLoaderData("root") as {
    googleIdentityEnabled?: boolean
    publicGoogleClientId?: string
    devAuthEnabled?: boolean
  } | undefined

  const googleIdentityEnabled = rootData?.googleIdentityEnabled ?? false
  const publicGoogleClientId = rootData?.publicGoogleClientId ?? ""
  const devAuthEnabled = rootData?.devAuthEnabled ?? false

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
            setIsOpen(false)
            toast.success("Zalogowano pomyślnie!")
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
        document.getElementById("google-signin-button"),
        { theme: "outline", size: "large", width: 280 }
      )
    } catch {
      setErrorMsg("Nie udało się zainicjować logowania Google.")
    }
  }, [publicGoogleClientId, login])

  // Load Google script dynamically
  React.useEffect(() => {
    if (!googleIdentityEnabled || !isOpen || session.authenticated) return

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
  }, [googleIdentityEnabled, isOpen, session.authenticated, initializeGoogleButton])

  const handleDevLogin = async () => {
    setIsLoading(true)
    setErrorMsg(null)
    const fakeToken = "fake_google_token_dev-subject-123_dev-user@example.com_Developer%20User"
    const res = await login(fakeToken)
    setIsLoading(false)
    if (res.success) {
      setIsOpen(false)
      toast.success("Zalogowano pomyślnie w trybie deweloperskim!")
    } else {
      setErrorMsg(res.error || "Wystąpił błąd podczas logowania deweloperskiego.")
    }
  }

  const handleLogout = async () => {
    await logout()
    toast.info("Wylogowano pomyślnie.")
    navigate("/")
  }

  if (session.authenticated && session.user) {
    return (
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="ghost" className="relative h-10 w-10 rounded-full ring-offset-background transition-all hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
            <Avatar className="h-10 w-10 border bg-primary/5">
              <AvatarFallback className="text-sm font-bold text-primary">
                {session.user.initials}
              </AvatarFallback>
            </Avatar>
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent className="w-56" align="end" forceMount>
          <DropdownMenuLabel className="font-normal">
            <div className="flex flex-col space-y-1">
              <p className="text-sm font-semibold leading-none text-foreground">{session.user.displayName}</p>
              <p className="text-xs leading-none text-muted-foreground">{session.user.roles.includes("ROLE_ADMIN") ? "Administrator" : "Użytkownik"}</p>
            </div>
          </DropdownMenuLabel>
          <DropdownMenuSeparator />
          <DropdownMenuItem asChild className="cursor-pointer font-semibold text-sm">
            <Link to="/konto" className="flex items-center gap-2">
              <UserIcon className="size-4 text-primary" />
              Moje konto
            </Link>
          </DropdownMenuItem>
          <DropdownMenuItem asChild className="cursor-pointer font-semibold text-sm">
            <Link to="/konto/ulubione" className="flex items-center gap-2">
              <Heart className="size-4 text-accent" />
              Ulubione miejsca
            </Link>
          </DropdownMenuItem>
          <DropdownMenuItem asChild className="cursor-pointer font-semibold text-sm">
            <Link to="/konto/odwiedzone" className="flex items-center gap-2">
              <Compass className="size-4 text-primary" />
              Historia wizyt
            </Link>
          </DropdownMenuItem>
          <DropdownMenuSeparator />
          <DropdownMenuItem onClick={handleLogout} className="cursor-pointer font-semibold text-sm text-destructive focus:bg-destructive/10 focus:text-destructive">
            <LogOut className="size-4 mr-2" />
            Wyloguj się
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    )
  }

  return (
    <Dialog open={isOpen} onOpenChange={(open) => {
      setIsOpen(open)
      if (!open) {
        setIsLinkRequired(false)
        setErrorMsg(null)
      }
    }}>
      <DialogTrigger asChild>
        <Button variant="outline" size="sm" className="font-semibold text-xs py-1 px-4">
          Zaloguj się
        </Button>
      </DialogTrigger>
      <DialogContent className="sm:max-w-[360px] p-6 text-center">
        <DialogHeader className="text-center items-center">
          <DialogTitle className="font-serif text-2xl font-medium tracking-tight mb-2">
            Logowanie
          </DialogTitle>
          <DialogDescription className="text-sm text-muted-foreground leading-relaxed">
            Zaloguj się, aby dodawać ulubione miejsca i zapisywać historię wizyt.
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
                <div id="google-signin-button" className="min-h-[40px] flex items-center justify-center" />
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
                    Bypass Login (Fake User)
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
  )
}
