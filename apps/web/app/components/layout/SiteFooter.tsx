import * as React from "react"
import { Link } from "react-router"
import { content } from "~/content"

export function SiteFooter() {
  const currentYear = new Date().getFullYear()

  return (
    <footer className="border-t bg-muted/30">
      <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 md:flex md:items-center md:justify-between lg:px-8">
        <div className="flex justify-center space-x-6 md:order-2">
          <Link to="/miejsca" className="text-sm text-muted-foreground hover:text-primary">
            {content.navigation.placesCatalog}
          </Link>
        </div>
        <div className="mt-8 md:order-1 md:mt-0">
          <p className="text-center text-xs text-muted-foreground">
            &copy; {currentYear} {content.common.siteTitle}. Wszystkie prawa zastrzeżone.
          </p>
        </div>
      </div>
    </footer>
  )
}
