import * as React from "react"
import { listForumCategories } from "@family-places/api-client"
import { AppShell } from "~/components/layout/AppShell"
import { PageContainer } from "~/components/layout/PageContainer"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "~/components/ui/card"
import { MessageSquare, ArrowRight } from "lucide-react"
import { Link } from "react-router"

interface Category {
  id: string
  slug: string
  name: string
  description: string
}

export default function ForumCategoriesPage() {
  const [categories, setCategories] = React.useState<Category[]>([])
  const [loading, setLoading] = React.useState(true)
  const [error, setError] = React.useState<string | null>(null)

  React.useEffect(() => {
    async function load() {
      try {
        const res = await listForumCategories()
        if (res.data) {
          setCategories((res.data || []) as Category[])
        } else {
          setError("Nie udało się pobrać kategorii forum.")
        }
      } catch (err: unknown) {
        setError(err instanceof Error ? err.message : "Wystąpił błąd.")
      } finally {
        setLoading(false)
      }
    }
    load()
  }, [])

  return (
    <AppShell>
      <PageContainer>
        <div className="mx-auto max-w-5xl py-8">
          <div className="border-b pb-6 mb-8">
            <h1 className="text-3xl font-extrabold tracking-tight">Forum Społeczności</h1>
            <p className="text-muted-foreground mt-1">Dyskutuj, zadawaj pytania i dziel się doświadczeniami z innymi rodzicami.</p>
          </div>

          {error && (
            <div className="bg-destructive/10 text-destructive p-4 rounded-lg mb-6 text-sm" role="alert">
              {error}
            </div>
          )}

          {loading ? (
            <div className="grid gap-6 sm:grid-cols-2">
              {[1, 2, 3].map((n) => (
                <Card key={n} className="animate-pulse">
                  <CardHeader>
                    <div className="h-6 w-1/3 bg-muted rounded mb-2" />
                    <div className="h-4 w-5/6 bg-muted rounded" />
                  </CardHeader>
                </Card>
              ))}
            </div>
          ) : categories.length === 0 ? (
            <Card className="text-center py-12">
              <CardContent className="space-y-4">
                <MessageSquare className="h-12 w-12 text-muted-foreground mx-auto" />
                <h3 className="text-lg font-semibold">Brak kategorii</h3>
                <p className="text-muted-foreground">Nie utworzono jeszcze żadnych kategorii na forum.</p>
              </CardContent>
            </Card>
          ) : (
            <div className="grid gap-6 sm:grid-cols-2">
              {categories.map((cat) => (
                <Link key={cat.id} to={`/forum/${cat.slug}`} className="block group">
                  <Card className="h-full border-muted hover:border-primary/50 group-hover:shadow-md transition-all">
                    <CardHeader>
                      <CardTitle className="flex items-center justify-between text-xl font-bold text-foreground group-hover:text-primary transition-colors">
                        <span>{cat.name}</span>
                        <ArrowRight className="h-5 w-5 text-muted-foreground group-hover:text-primary transition-all group-hover:translate-x-1" />
                      </CardTitle>
                      <CardDescription className="text-sm text-muted-foreground mt-2 line-clamp-2 leading-relaxed">
                        {cat.description}
                      </CardDescription>
                    </CardHeader>
                  </Card>
                </Link>
              ))}
            </div>
          )}
        </div>
      </PageContainer>
    </AppShell>
  )
}
