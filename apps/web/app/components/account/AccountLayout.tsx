import type { ReactNode } from "react"
import { Heart, UserRound, Waypoints } from "lucide-react"
import { Link, useLocation } from "react-router"

import { PageContainer } from "~/components/layout/PageContainer"

const navigation = [
  { to: "/konto", label: "Konto", icon: UserRound, exact: true },
  { to: "/konto/ulubione", label: "Ulubione", icon: Heart },
  { to: "/konto/odwiedzone", label: "Wizyty", icon: Waypoints },
]

export function AccountLayout({ children }: { children: ReactNode }) {
  const { pathname } = useLocation()

  return (
    <PageContainer className="max-w-5xl py-8 sm:py-10">
      <div className="space-y-7 sm:space-y-9">
        <nav aria-label="Nawigacja konta" className="overflow-x-auto border-b border-border">
          <div className="flex min-w-max gap-1" role="list">
            {navigation.map(({ to, label, icon: Icon, exact }) => {
              const active = exact ? pathname === to : pathname === to || pathname.startsWith(`${to}/`)
              return (
                <Link
                  key={to}
                  to={to}
                  aria-current={active ? "page" : undefined}
                  className={`inline-flex min-h-11 items-center gap-2 border-b-2 px-3 py-2 text-sm font-semibold transition-colors ${active ? "border-primary text-primary" : "border-transparent text-muted-foreground hover:border-border hover:text-foreground"}`}
                >
                  <Icon className="size-4" aria-hidden="true" />
                  {label}
                </Link>
              )
            })}
          </div>
        </nav>
        {children}
      </div>
    </PageContainer>
  )
}
