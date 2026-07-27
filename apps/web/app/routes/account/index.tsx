import { fetchSession } from "../../lib/api-session.server"
import { redirect, Link } from "react-router"
import type { Route } from "./+types/index"
import { AppShell } from "../../components/layout/AppShell"
import { AccountLayout } from "~/components/account/AccountLayout"
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "~/components/ui/card"
import { Button } from "~/components/ui/button"
import { Heart, Compass, ArrowRight, Mail } from "lucide-react"

export async function loader({ request }: Route.LoaderArgs) {
  const { data } = await fetchSession(request.headers)
  if (!data.authenticated) {
    return redirect("/?loginRequired=true")
  }
  return { session: data }
}

export default function AccountDashboard({ loaderData }: Route.ComponentProps) {
  const { session } = loaderData
  const user = session.user!

  return (
    <AppShell>
      <AccountLayout>
        <section aria-labelledby="account-title" className="rounded-[var(--radius-card)] border border-border bg-card p-5 shadow-[var(--shadow-soft)] sm:p-7">
          <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
            <div className="flex size-14 shrink-0 items-center justify-center rounded-full bg-primary/10 font-serif text-xl font-semibold text-primary" aria-hidden="true">{user.initials}</div>
            <div className="min-w-0">
              <h1 id="account-title" className="font-serif text-3xl font-semibold tracking-tight text-foreground sm:text-4xl">{user.displayName}</h1>
              <p className="mt-1 flex items-center gap-2 text-sm text-muted-foreground"><Mail className="size-4 shrink-0" aria-hidden="true" /> Prywatne konto FamilyPlaces</p>
            </div>
          </div>
        </section>
        <section aria-labelledby="account-activity" className="space-y-4">
          <div>
            <h2 id="account-activity" className="font-serif text-2xl font-semibold text-foreground">Twoja aktywność</h2>
            <p className="mt-1 text-sm text-muted-foreground">Wróć do miejsc, które chcesz zachować lub już odwiedziliście.</p>
          </div>
          <div className="grid gap-4 md:grid-cols-2">
            <Card className="border-border bg-card transition-[border-color,box-shadow] hover:border-primary/40 hover:shadow-[var(--shadow-soft)]">
              <CardHeader>
                <div className="mb-2 w-fit rounded-[var(--radius-control)] bg-accent/10 p-3 text-accent">
                  <Heart className="size-6" />
                </div>
                <CardTitle className="font-serif text-xl font-bold">Ulubione miejsca</CardTitle>
                <CardDescription>
                  Zachowaj miejsca, do których chcecie łatwo wrócić.
                </CardDescription>
              </CardHeader>
              <CardContent>
                <Button asChild className="w-full">
                  <Link to="/konto/ulubione" className="flex items-center gap-1.5 justify-center">
                    Przejdź do ulubionych
                    <ArrowRight className="size-4" />
                  </Link>
                </Button>
              </CardContent>
            </Card>

            <Card className="border-border bg-card transition-[border-color,box-shadow] hover:border-primary/40 hover:shadow-[var(--shadow-soft)]">
              <CardHeader>
                <div className="mb-2 w-fit rounded-[var(--radius-control)] bg-primary/10 p-3 text-primary">
                  <Compass className="size-6" />
                </div>
                <CardTitle className="font-serif text-xl font-bold">Historia wizyt</CardTitle>
                <CardDescription>
                  Zobacz prywatną historię miejsc, które odwiedziliście.
                </CardDescription>
              </CardHeader>
              <CardContent>
                <Button asChild className="w-full">
                  <Link to="/konto/odwiedzone" className="flex items-center gap-1.5 justify-center">
                    Przejdź do historii
                    <ArrowRight className="size-4" />
                  </Link>
                </Button>
              </CardContent>
            </Card>
          </div>
        </section>
      </AccountLayout>
    </AppShell>
  )
}
