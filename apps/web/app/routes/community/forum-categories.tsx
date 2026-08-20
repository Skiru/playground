import { useLoaderData, Link } from "react-router"
import { loadForumCategories } from "~/lib/api.server"
import { AppShell } from "~/components/layout/AppShell"
import { PageContainer } from "~/components/layout/PageContainer"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "~/components/ui/card"
import { MessageSquare, ArrowRight } from "lucide-react"

interface Category {
  id: string
  slug: string
  name: string
  description: string
}

function useOptionalLoaderData<T>(): T | undefined {
  try {
    return useLoaderData() as T
  } catch {
    return undefined
  }
}

export async function loader() {
  const categoriesData = await loadForumCategories()
  return { categoriesData }
}

export default function ForumCategoriesPage() {
  const loaderData = useOptionalLoaderData<Awaited<ReturnType<typeof loader>>>()
  const categories: Category[] = (loaderData?.categoriesData || []).map((category) => ({
    id: String(category.id),
    slug: String(category.slug),
    name: String(category.name),
    description: String(category.description),
  }))

  return (
    <AppShell>
      <PageContainer>
        <div className="mx-auto max-w-5xl py-8">
          <div className="border-b pb-6 mb-8">
            <h1 className="text-3xl font-extrabold tracking-tight">Forum Społeczności</h1>
            <p className="text-muted-foreground mt-1">Dyskutuj, zadawaj pytania i dziel się doświadczeniami z innymi rodzicami.</p>
          </div>

          {categories.length === 0 ? (
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
