import * as React from "react"
import { Link, useLocation } from "react-router"
import { Menu } from "lucide-react"
import { brand } from "~/brand/default-brand"
import { content } from "~/content"
import { Button } from "~/components/ui/button"
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "~/components/ui/sheet"
import { UserAreaPlaceholder } from "./UserAreaPlaceholder"

export function SiteHeader() {
  const [isOpen, setIsOpen] = React.useState(false)
  const location = useLocation()

  const navLinks = [
    { href: "/miejsca", label: content.navigation.placesCatalog },
  ]

  const isActive = (href: string) => location.pathname === href

  return (
    <header className="sticky top-0 z-50 w-full border-b bg-background/95 backdrop-blur-md support-[backdrop-filter]:bg-background/60">
      <div className="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
        {/* Logo and Brand */}
        <div className="flex items-center gap-6">
          <Link to="/" className="flex items-center space-x-2">
            <span className="hidden sm:inline-block">
              <img
                src={brand.wordmark.path}
                alt={brand.wordmark.alt}
                width={brand.wordmark.width || 120}
                height={brand.wordmark.height || 30}
                className="h-8 w-auto object-contain"
              />
            </span>
            <span className="sm:hidden">
              <img
                src={brand.compactMark.path}
                alt={brand.compactMark.alt}
                width={brand.compactMark.width || 32}
                height={brand.compactMark.height || 32}
                className="h-8 w-auto object-contain"
              />
            </span>
          </Link>

          {/* Desktop Navigation */}
          <nav className="hidden md:flex items-center space-x-6">
            {navLinks.map((link) => (
              <Link
                key={link.href}
                to={link.href}
                className={`text-sm font-medium transition-colors hover:text-primary ${
                  isActive(link.href)
                    ? "text-primary font-semibold"
                    : "text-muted-foreground"
                }`}
              >
                {link.label}
              </Link>
            ))}
          </nav>
        </div>

        {/* Right Actions */}
        <div className="flex items-center gap-4">
          <div className="hidden md:block">
            <UserAreaPlaceholder />
          </div>

          {/* Mobile Menu Trigger */}
          <div className="md:hidden flex items-center gap-2">
            <UserAreaPlaceholder />
            <Sheet open={isOpen} onOpenChange={setIsOpen}>
              <SheetTrigger asChild>
                <Button
                  variant="ghost"
                  size="icon"
                  className="h-9 w-9 px-0 hover:bg-transparent"
                  aria-label="Toggle Menu"
                >
                  <Menu className="h-5 w-5" />
                  <span className="sr-only">Toggle Menu</span>
                </Button>
              </SheetTrigger>
              <SheetContent side="right" className="w-[300px] p-6">
                <SheetHeader className="text-left border-b pb-4 mb-4">
                  <SheetTitle className="text-lg font-bold">
                    <img
                      src={brand.wordmark.path}
                      alt={brand.wordmark.alt}
                      width={120}
                      className="h-6 w-auto object-contain"
                    />
                  </SheetTitle>
                </SheetHeader>
                <nav className="flex flex-col space-y-4">
                  {navLinks.map((link) => (
                    <Link
                      key={link.href}
                      to={link.href}
                      onClick={() => setIsOpen(false)}
                      className={`text-base font-semibold py-2 border-b border-muted/50 transition-colors ${
                        isActive(link.href) ? "text-primary" : "text-muted-foreground"
                      }`}
                    >
                      {link.label}
                    </Link>
                  ))}
                </nav>
              </SheetContent>
            </Sheet>
          </div>
        </div>
      </div>
    </header>
  )
}
