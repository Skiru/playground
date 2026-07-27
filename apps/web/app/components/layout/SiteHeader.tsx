import { Link, useLocation } from "react-router"
import { Heart, Search } from "lucide-react"
import { brand } from "~/brand/default-brand"
import { Button } from "~/components/ui/button"
import { UserArea } from "./UserArea"
import { AppImage } from "../media/AppImage"

export function SiteHeader() {
  const location = useLocation()

  const navLinks = [
    { href: "/", label: "Odkrywaj" },
    { href: "/miejsca", label: "Miejsca" },
    { href: "/spolecznosc", label: "Społeczność" },
    { href: "/konto", label: "Profil" },
  ]

  const isActive = (href: string) => href === "/" ? location.pathname === "/" : location.pathname === href || location.pathname.startsWith(`${href}/`)

  return (
    <header className="sticky top-0 z-40 w-full border-b bg-background/95 backdrop-blur-md supports-[backdrop-filter]:bg-background/85">
      <div className="mx-auto flex h-[4.5rem] max-w-7xl items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
        <div className="flex min-w-0 items-center gap-8">
          <Link to="/" className="flex shrink-0 items-center" aria-label="FamilyPlaces, strona główna">
            <span className="hidden sm:inline-block">
              <AppImage
                src={brand.wordmark.path}
                fallback={brand.wordmark.path}
                alt={brand.wordmark.alt}
                width={brand.wordmark.width || 120}
                height={brand.wordmark.height || 30}
                className="h-7 w-auto object-contain"
              />
            </span>
            <span className="sm:hidden">
              <AppImage
                src={brand.compactMark.path}
                fallback={brand.compactMark.path}
                alt={brand.compactMark.alt}
                width={brand.compactMark.width || 32}
                height={brand.compactMark.height || 32}
                className="h-8 w-auto object-contain"
              />
            </span>
          </Link>

          <nav aria-label="Nawigacja główna" className="hidden items-center gap-1 md:flex">
            {navLinks.map((link) => (
              <Link
                key={link.href}
                to={link.href}
                aria-current={isActive(link.href) ? "page" : undefined}
                className={`rounded-xl px-3 py-2 text-sm font-semibold transition-colors hover:bg-secondary/70 hover:text-primary ${
                  isActive(link.href)
                    ? "bg-secondary text-primary"
                    : "text-muted-foreground"
                }`}
              >
                {link.label}
              </Link>
            ))}
          </nav>
        </div>

        <div className="flex shrink-0 items-center gap-2">
          <Button variant="ghost" size="sm" asChild className="hidden lg:inline-flex">
            <Link to="/miejsca">
              <Search className="size-4" />
              Szukaj miejsc
            </Link>
          </Button>
          <Button variant="ghost" size="icon" asChild className="hidden md:inline-flex" title="Ulubione miejsca">
            <Link to="/konto/ulubione" aria-label="Ulubione miejsca">
              <Heart className="size-5" />
            </Link>
          </Button>
          <div>
            <UserArea />
          </div>
        </div>
      </div>
    </header>
  )
}
