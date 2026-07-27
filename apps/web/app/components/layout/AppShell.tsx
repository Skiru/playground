import * as React from "react"
import { SiteHeader } from "./SiteHeader"
import { SiteFooter } from "./SiteFooter"
import { MobileBottomNavigation } from "./MobileBottomNavigation"

interface AppShellProps {
  children: React.ReactNode
}

export function AppShell({ children }: AppShellProps) {
  return (
    <div className="flex min-h-screen flex-col bg-background text-foreground">
      <a href="#main-content" className="sr-only z-[100] rounded-md bg-primary px-4 py-3 text-primary-foreground focus:not-sr-only focus:fixed focus:left-4 focus:top-4">
        Przejdź do treści
      </a>
      <SiteHeader />
      <main id="main-content" className="flex-1 pb-[calc(var(--mobile-navigation-height)+env(safe-area-inset-bottom)+1rem)] md:pb-0">{children}</main>
      <SiteFooter />
      <MobileBottomNavigation />
    </div>
  )
}
