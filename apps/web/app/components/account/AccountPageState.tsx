import { AlertCircle, Compass } from "lucide-react"
import { Link } from "react-router"

import { Button } from "~/components/ui/button"
import { Card, CardContent } from "~/components/ui/card"

export function AccountEmptyState({ icon: Icon = Compass, title, description }: { icon?: typeof Compass; title: string; description: string }) {
  return (
    <Card className="border-dashed bg-muted/20">
      <CardContent className="flex min-h-64 flex-col items-center justify-center px-6 py-10 text-center">
        <Icon className="mb-4 size-10 text-primary" aria-hidden="true" />
        <h2 className="font-serif text-xl font-semibold text-foreground">{title}</h2>
        <p className="mt-2 max-w-md text-sm leading-relaxed text-muted-foreground">{description}</p>
        <Button asChild className="mt-6"><Link to="/miejsca">Przejdź do Miejsc</Link></Button>
      </CardContent>
    </Card>
  )
}

export function AccountErrorState() {
  return (
    <Card className="border-destructive/30 bg-card">
      <CardContent role="alert" className="flex min-h-56 flex-col items-center justify-center px-6 py-10 text-center">
        <AlertCircle className="mb-4 size-10 text-destructive" aria-hidden="true" />
        <h2 className="font-serif text-xl font-semibold text-foreground">Nie udało się wczytać danych</h2>
        <p className="mt-2 max-w-md text-sm leading-relaxed text-muted-foreground">Twoje zapisane dane są bezpieczne. Spróbuj ponownie za chwilę.</p>
        <Button className="mt-6" onClick={() => window.location.reload()}>Spróbuj ponownie</Button>
      </CardContent>
    </Card>
  )
}
