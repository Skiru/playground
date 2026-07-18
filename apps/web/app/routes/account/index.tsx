import { fetchSession } from "../../lib/api-session.server"
import { redirect, Link } from "react-router"
import type { Route } from "./+types/index"
import { AppShell } from "../../components/layout/AppShell"
import { PageContainer } from "../../components/layout/PageContainer"
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "~/components/ui/card"
import { Button } from "~/components/ui/button"
import { Heart, Compass, ArrowRight } from "lucide-react"

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
      <PageContainer className="py-10 max-w-4xl">
        <div className="flex flex-col gap-8">
          <div>
            <h1 className="font-serif text-3xl sm:text-4xl font-medium text-foreground">
              Moje konto
            </h1>
            <p className="text-sm text-muted-foreground mt-1">
              Witaj z powrotem, {user.displayName}! Tutaj możesz zarządzać swoją aktywnością.
            </p>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            {/* Favorites Card */}
            <Card className="border hover:border-primary/50 hover:shadow-md transition-all duration-300">
              <CardHeader>
                <div className="p-3 rounded-lg bg-accent/10 text-accent w-fit mb-2">
                  <Heart className="size-6" />
                </div>
                <CardTitle className="font-serif text-xl font-bold">Ulubione miejsca</CardTitle>
                <CardDescription className="text-xs">
                  Przeglądaj i zarządzaj zapisanymi miejscami przyjaznymi rodzinom.
                </CardDescription>
              </CardHeader>
              <CardContent>
                <Button size="sm" asChild className="w-full font-bold bg-primary hover:bg-primary/95 text-white">
                  <Link to="/konto/ulubione" className="flex items-center gap-1.5 justify-center">
                    Przejdź do ulubionych
                    <ArrowRight className="size-4" />
                  </Link>
                </Button>
              </CardContent>
            </Card>

            {/* Visits Card */}
            <Card className="border hover:border-primary/50 hover:shadow-md transition-all duration-300">
              <CardHeader>
                <div className="p-3 rounded-lg bg-primary/10 text-primary w-fit mb-2">
                  <Compass className="size-6" />
                </div>
                <CardTitle className="font-serif text-xl font-bold">Historia wizyt</CardTitle>
                <CardDescription className="text-xs">
                  Wyświetlaj, edytuj i wspominaj miejsca, które już odwiedziliście.
                </CardDescription>
              </CardHeader>
              <CardContent>
                <Button size="sm" asChild className="w-full font-bold bg-primary hover:bg-primary/95 text-white">
                  <Link to="/konto/odwiedzone" className="flex items-center gap-1.5 justify-center">
                    Przejdź do historii
                    <ArrowRight className="size-4" />
                  </Link>
                </Button>
              </CardContent>
            </Card>
          </div>
        </div>
      </PageContainer>
    </AppShell>
  )
}
