import * as React from "react"
import {
  isRouteErrorResponse,
  Links,
  Meta,
  Outlet,
  Scripts,
  ScrollRestoration,
} from "react-router"

import type { Route } from "./+types/root"
import "./styles/global.css"
import { Toaster } from "~/components/ui/sonner"
import { AppShell } from "~/components/layout/AppShell"
import { Button } from "~/components/ui/button"
import { Card, CardContent, CardFooter, CardHeader, CardTitle, CardDescription } from "~/components/ui/card"
import { AlertCircle } from "lucide-react"
import { content } from "~/content"
import { fetchSession } from "./lib/api-session.server"
import { SessionProvider } from "./lib/session-context"

export const links: Route.LinksFunction = () => []

export async function loader({ request }: Route.LoaderArgs) {
  const { data } = await fetchSession(request.headers)
  return { initialSession: data }
}

export function Layout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="pl">
      <head>
        <meta charSet="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <Meta />
        <Links />
      </head>
      <body className="antialiased">
        {children}
        <Toaster position="bottom-right" closeButton />
        <ScrollRestoration />
        <Scripts />
      </body>
    </html>
  )
}

export default function App({ loaderData }: Route.ComponentProps) {
  return (
    <SessionProvider initialSession={loaderData.initialSession}>
      <Outlet />
    </SessionProvider>
  )
}

export function ErrorBoundary({ error }: Route.ErrorBoundaryProps) {
  let message = content.errors.generalError
  let details = content.errors.errorDetailsPrefix
  let status = "Error"
  let stack: string | undefined

  if (isRouteErrorResponse(error)) {
    status = String(error.status)
    message = error.status === 404 ? content.errors.error404 : content.errors.generalError
    details =
      error.status === 404
        ? content.errors.notFoundDetails
        : error.statusText || details
  } else if (import.meta.env.DEV && error && error instanceof Error) {
    details = error.message
    stack = error.stack
  }

  return (
    <html lang="pl">
      <head>
        <meta charSet="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <title>{message} | {content.common.siteTitle}</title>
        <Meta />
        <Links />
      </head>
      <body className="antialiased">
        <AppShell>
          <div className="flex-1 flex items-center justify-center py-20 px-4">
            <Card className="max-w-md w-full border-muted/60 shadow-lg bg-card">
              <CardHeader className="text-center">
                <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-destructive/10 text-destructive mb-4">
                  <AlertCircle className="h-6 w-6" />
                </div>
                <CardTitle className="font-serif text-3xl font-medium tracking-tight text-foreground">
                  {status === "404" ? "404" : message}
                </CardTitle>
                <CardDescription className="text-sm text-muted-foreground mt-2">
                  {details}
                </CardDescription>
              </CardHeader>
              <CardContent className="p-6 pt-0 text-center">
                {stack && (
                  <pre className="mt-4 max-h-40 overflow-y-auto text-left rounded-md bg-muted p-4 font-mono text-3xs text-muted-foreground leading-normal border">
                    <code>{stack}</code>
                  </pre>
                )}
              </CardContent>
              <CardFooter className="flex flex-col gap-2 p-6 pt-0">
                <Button className="w-full font-bold bg-primary hover:bg-primary/95 text-white" onClick={() => window.location.reload()}>
                  Spróbuj ponownie
                </Button>
                <Button variant="outline" className="w-full font-bold" asChild>
                  <a href="/">Wróć do strony głównej</a>
                </Button>
              </CardFooter>
            </Card>
          </div>
        </AppShell>
        <ScrollRestoration />
        <Scripts />
      </body>
    </html>
  )
}
