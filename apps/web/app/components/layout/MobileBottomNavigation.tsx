import { Compass, MapPin, MessageCircle, UserRound } from "lucide-react"
import { Link, useLocation } from "react-router"

const items = [
  { href: "/", label: "Odkrywaj", icon: Compass },
  { href: "/miejsca", label: "Miejsca", icon: MapPin },
  { href: "/spolecznosc", label: "Społeczność", icon: MessageCircle },
  { href: "/konto", label: "Profil", icon: UserRound },
]

export function MobileBottomNavigation() {
  const { pathname } = useLocation()

  return (
    <nav aria-label="Nawigacja mobilna" className="fixed inset-x-0 bottom-0 z-50 border-t bg-card/95 pb-[env(safe-area-inset-bottom)] backdrop-blur-md md:hidden">
      <div className="mx-auto grid h-[var(--mobile-navigation-height)] max-w-md grid-cols-4">
        {items.map(({ href, label, icon: Icon }) => {
          const active = href === "/" ? pathname === "/" : pathname === href || pathname.startsWith(`${href}/`)
          return (
            <Link
              key={href}
              to={href}
              aria-current={active ? "page" : undefined}
              className={`relative flex min-h-16 flex-col items-center justify-center gap-1 px-1 text-xs font-semibold ${active ? "text-primary" : "text-muted-foreground"}`}
            >
              <Icon className="size-5" aria-hidden="true" />
              <span>{label}</span>
              {active ? <span className="absolute inset-x-3 top-0 h-0.5 rounded-full bg-accent" aria-hidden="true" /> : null}
            </Link>
          )
        })}
      </div>
    </nav>
  )
}
