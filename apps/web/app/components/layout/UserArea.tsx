import * as React from "react"
import { useSession } from "~/lib/session-context"
import { useLoginRequiredAction } from "~/features/auth/LoginRequiredActionContext"
import { Link, useNavigate } from "react-router"
import { Button } from "~/components/ui/button"
import { Avatar, AvatarFallback } from "~/components/ui/avatar"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "~/components/ui/dropdown-menu"
import { toast } from "sonner"
import { LogOut, User as UserIcon, Heart, Compass } from "lucide-react"

export function UserArea() {
  const { session, logout } = useSession()
  const { requireLogin } = useLoginRequiredAction()
  const navigate = useNavigate()

  const handleLogout = async () => {
    await logout()
    toast.info("Wylogowano pomyślnie.")
    navigate("/")
  }

  if (session.authenticated && session.user) {
    return (
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button aria-label="Panel użytkownika" data-testid="user-menu-button" variant="ghost" className="relative h-10 w-10 rounded-full ring-offset-background transition-all hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
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
    <Button
      variant="outline"
      size="sm"
      className="font-semibold text-xs py-1 px-4"
      onClick={() => requireLogin(() => {})}
    >
      Zaloguj się
    </Button>
  )
}
