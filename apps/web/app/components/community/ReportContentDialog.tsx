import * as React from "react"
import { reportContent } from "@family-places/api-client"
import { useSession } from "~/lib/session-context"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
  DialogFooter,
} from "~/components/ui/dialog"
import { Button } from "~/components/ui/button"
import { Label } from "~/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "~/components/ui/select"
import { AlertCircle, CheckCircle2 } from "lucide-react"

interface ReportContentDialogProps {
  targetId: string
  targetType: "REVIEW" | "PLACE_COMMENT" | "FORUM_THREAD" | "FORUM_POST"
  trigger: React.ReactNode
}

export function ReportContentDialog({ targetId, targetType, trigger }: ReportContentDialogProps) {
  const { session } = useSession()
  const [open, setOpen] = React.useState(false)
  const [reason, setReason] = React.useState<string>("")
  const [details, setDetails] = React.useState("")
  const [submitting, setSubmitting] = React.useState(false)
  const [success, setSuccess] = React.useState(false)
  const [error, setError] = React.useState<string | null>(null)

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!reason) {
      setError("Wybierz powód zgłoszenia.")
      return
    }

    setError(null)
    setSubmitting(true)

    try {
      const res = await reportContent({
        body: {
          targetId,
          targetType,
          reason: reason as any,
          details: details.trim() || undefined,
        },
        headers: {
          "X-CSRF-Token": session.csrfToken || "",
        },
      })

      if (res.response.status === 201) {
        setSuccess(true)
        setTimeout(() => {
          setOpen(false)
          setSuccess(false)
          setReason("")
          setDetails("")
        }, 2000)
      } else {
        const errorData = res.error as any
        if (res.response.status === 409) {
          setError("Ta treść została już przez Ciebie zgłoszona i jest weryfikowana.")
        } else if (res.response.status === 429) {
          setError("Przekroczono limit zgłoszeń. Spróbuj ponownie później.")
        } else {
          setError(errorData?.detail || "Nie udało się przesłać zgłoszenia.")
        }
      }
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Wystąpił nieoczekiwany błąd.")
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={(val) => {
      setOpen(val)
      if (!val) {
        setError(null)
        setSuccess(false)
      }
    }}>
      <DialogTrigger asChild>{trigger}</DialogTrigger>
      <DialogContent className="sm:max-w-[425px]">
        <DialogHeader>
          <DialogTitle>Zgłoś naruszenie regulaminu</DialogTitle>
        </DialogHeader>

        {success ? (
          <div className="flex flex-col items-center justify-center py-6 text-center space-y-3" role="alert">
            <CheckCircle2 className="h-12 w-12 text-emerald-500 animate-bounce" />
            <h3 className="font-bold text-lg">Zgłoszenie wysłane</h3>
            <p className="text-sm text-muted-foreground">Dziękujemy. Moderatorzy przyjrzą się tej treści.</p>
          </div>
        ) : (
          <form onSubmit={handleSubmit} className="space-y-4 py-4">
            {error && (
              <div className="flex items-start gap-2 bg-destructive/10 text-destructive p-3 rounded text-xs leading-relaxed" role="alert">
                <AlertCircle className="h-4 w-4 shrink-0 mt-0.5" />
                <span>{error}</span>
              </div>
            )}

            <div className="space-y-1.5">
              <Label htmlFor="reason-select">Powód zgłoszenia</Label>
              <Select value={reason} onValueChange={setReason} required>
                <SelectTrigger id="reason-select">
                  <SelectValue placeholder="Wybierz powód..." />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="SPAM">Spam lub reklama</SelectItem>
                  <SelectItem value="HARASSMENT">Nękanie lub mowa nienawiści</SelectItem>
                  <SelectItem value="INAPPROPRIATE">Treści nieodpowiednie dla dzieci</SelectItem>
                  <SelectItem value="MISINFORMATION">Dezinformacja</SelectItem>
                  <SelectItem value="PRIVACY_CONCERN">Naruszenie prywatności</SelectItem>
                  <SelectItem value="OTHER">Inny powód</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="details-textarea">Dodatkowe szczegóły (opcjonalnie)</Label>
              <textarea
                id="details-textarea"
                value={details}
                onChange={(e) => setDetails(e.target.value)}
                placeholder="Napisz dlaczego zgłaszasz tę treść..."
                className="flex min-h-[90px] w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                maxLength={1000}
              />
            </div>

            <DialogFooter className="pt-4">
              <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                Anuluj
              </Button>
              <Button type="submit" disabled={submitting}>
                {submitting ? "Wysyłanie..." : "Wyślij zgłoszenie"}
              </Button>
            </DialogFooter>
          </form>
        )}
      </DialogContent>
    </Dialog>
  )
}
